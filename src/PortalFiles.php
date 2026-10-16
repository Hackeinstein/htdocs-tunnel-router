<?php
declare(strict_types=1);

/** An ActivityLog that records nothing (Files logs every write; the portal keeps its own per-user log instead). */
class NullActivityLog extends ActivityLog
{
    public function append(string $action, string $hostname, bool $ok, string $detail): void {}
}

/**
 * One portal user's view of ONE site folder. The folder sandbox is the existing Files class (no ".." tricks, no symlinks
 * out, never the tool's own folder); on top of it this adds: the per-user permissions (upload / edit / delete), the file
 * type rules of PortalPolicy, size limits and the audit log. Anything not offered here (copy, chmod, trash restore)
 * is simply not reachable by users.
 */
class PortalFiles
{
    private bool $code;

    public function __construct(
        private Files $fs, private array $user, private array $grant, private string $host, private string $root,
        private PortalLog $log, private string $ip, private int $maxUpload, private int $siteLimit, private ?Cache $cache = null, private $mover = null,
    ) {
        $this->code = (bool)($user['code_files'] ?? false);
    }

    // ---- reading -------------------------------------------------------------

    public function list(string $path): array
    {
        $this->guardDir($path);
        $out = [];
        foreach ($this->fs->list($path) as $e) {
            if (PortalPolicy::hidden($e['name'], $this->code)) continue;
            $out[] = ['name' => $e['name'], 'path' => $e['path'], 'type' => $e['type'], 'size' => $e['size'], 'mtime' => $e['mtime'], 'ext' => $e['ext'],
                      'locked' => $e['type'] === 'file' && PortalPolicy::locked($e['name'], $this->code)];
        }
        return $out;
    }

    public function read(string $path): array
    {
        $this->guardPath($path); $this->notLocked($path);
        $r = $this->fs->read($path);
        $this->log('read', $path);
        return $r;
    }

    /** Absolute file for a download or inline image; the caller streams it with safe headers. */
    public function file(string $path): array
    {
        $this->guardPath($path); $this->notLocked($path);
        $abs = $this->fs->resolve($path);
        if (!is_file($abs)) throw new RouteException('Not a file');
        $this->log('download', $path);
        return ['abs' => $abs, 'name' => basename($abs)];
    }

    // ---- writing -------------------------------------------------------------

    public function write(string $path, string $content, ?int $mtime): array
    {
        $this->guardPath($path);
        $abs = $this->fs->resolve($path, false); $exists = is_file($abs);
        if ($exists) { $this->need('edit', 'change files'); $this->notLocked($path); }
        else { $this->need('upload', 'create files'); $this->nameOk(basename($path)); }
        $this->contentOk($content);
        $this->quota(strlen($content) - ($exists ? (int)filesize($abs) : 0));
        try { $r = $this->fs->write($path, $content, $mtime); }
        catch (RouteException $e) { $this->log('save', $path, false, $e->getMessage()); throw $e; }
        $this->log('save', $path, true, strlen($content) . ' bytes'); $this->touchQuota();
        return $r;
    }

    public function create(string $path): void
    {
        $this->guardPath($path); $this->need('upload', 'create files'); $this->nameOk(basename($path));
        $this->fs->create($path); $this->log('create', $path); $this->touchQuota();
    }

    public function mkdir(string $path): void
    {
        $this->guardPath($path); $this->need('upload', 'create folders'); $this->nameOk(basename($path));
        $this->fs->mkdir($path); $this->log('mkdir', $path);
    }

    public function rename(string $from, string $to): void
    {
        $this->guardPath($from); $this->guardPath($to); $this->need('edit', 'rename files');
        $this->notLocked($from); $this->nameOk(basename($to));
        $this->fs->rename($from, $to); $this->log('rename', $from, true, '→ ' . $to);
    }

    /** @return array{done: int} items go to the site's trash, which the site owner can restore */
    public function delete(array $paths): array
    {
        $this->need('delete', 'delete files');
        foreach ($paths as $p) {
            $this->guardPath((string)$p); $this->notLocked((string)$p);
            $abs = $this->fs->resolve((string)$p);
            if (is_dir($abs) && !$this->code && $this->holdsProtected($abs)) throw new RouteException('That folder contains protected files (programs, settings or secrets), so it cannot be deleted from here.', 403);
        }
        $this->fs->delete($paths);
        foreach ($paths as $p) $this->log('delete', (string)$p);
        $this->touchQuota();
        return ['done' => count($paths)];
    }

    /**
     * @param array $files one PHP $_FILES entry in the multi-file shape
     * @return array [{id, ok, error?}] each file is judged on its own; rejected ones never reach the disk
     */
    public function upload(string $dir, array $files, bool $overwrite): array
    {
        $this->guardDir($dir); $this->need('upload', 'upload files');
        if ($overwrite) $this->need('edit', 'replace files');
        $accept = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []]; $out = []; $room = $this->room();
        foreach ((array)($files['name'] ?? []) as $i => $orig) {
            $name = trim(basename(str_replace('\\', '/', (string)$orig)));
            $size = (int)($files['size'][$i] ?? 0); $tmp = (string)($files['tmp_name'][$i] ?? '');
            $err = null;
            if (($files['error'][$i] ?? 0) !== UPLOAD_ERR_OK) $err = 'The upload failed (too large for the server, or interrupted).';
            elseif ($e = PortalPolicy::nameError($name, $this->code)) $err = $e;
            elseif ($size > $this->maxUpload) $err = 'Larger than the ' . round($this->maxUpload / 1048576) . ' MB limit for one file.';
            elseif ($size > $room) $err = 'This would exceed the storage limit for this site (' . round($this->siteLimit / 1048576) . ' MB).';
            elseif ($tmp !== '' && !preg_match('/\.zip$/i', $name) && ($e = PortalPolicy::contentError((string)@file_get_contents($tmp, false, null, 0, 262144), $this->code))) $err = $e;
            elseif ($overwrite && is_file($this->fs->resolve(($dir !== '' ? trim($dir, '/') . '/' : '') . $name, false)) && PortalPolicy::locked($name, $this->code)) $err = 'That existing file is protected.';
            if ($err !== null) { $out[] = ['id' => $name ?: (string)$orig, 'ok' => false, 'error' => $err]; $this->log('upload', trim($dir, '/') . '/' . $name, false, $err); continue; }
            foreach ($accept as $k => $_) $accept[$k][] = $files[$k][$i] ?? null;
            $room -= $size;
        }
        if ($accept['name']) {
            foreach ($this->fs->upload($dir, $accept, $overwrite, $this->mover) as $r) { $out[] = $r; $this->log('upload', trim($dir, '/') . '/' . $r['id'], $r['ok'], $r['error'] ?? ''); }
            $this->touchQuota();
        }
        return $out;
    }

    // ---- zip -----------------------------------------------------------------

    private const ZIP_MAX_ENTRIES = 5000;

    /**
     * Extract a .zip the user uploaded. Every entry goes through the same rules as an upload (names, file types, content), so a
     * zip cannot smuggle in what a plain upload would be refused. Entries that fail are skipped and reported; entries that try to
     * escape the folder abort the whole extraction. Real byte counts are enforced while writing (an archive's own size
     * fields can lie), links inside archives are never created, and nothing outside the user's quota is written.
     * @return array{path:string, files:int, skipped:array, skipped_n:int}
     */
    public function unzip(string $path, bool $here): array
    {
        if (!class_exists('ZipArchive')) throw new RouteException('Zip files are not supported on this server (the PHP zip extension is off).', 501);
        $this->guardPath($path); $this->need('upload', 'extract archives'); $this->notLocked($path);
        $abs = $this->fs->resolve($path);
        if (!is_file($abs) || strtolower(pathinfo($abs, PATHINFO_EXTENSION)) !== 'zip') throw new RouteException('That is not a .zip file.');
        @set_time_limit(300);
        $dirRel = trim(str_replace('\\', '/', dirname(trim($path, '/'))), '/'); if ($dirRel === '.') $dirRel = '';
        $destRel = $here ? $dirRel : ($dirRel !== '' ? $dirRel . '/' : '') . pathinfo($abs, PATHINFO_FILENAME);
        if (!$here) {
            $this->nameOk(basename($destRel));
            if (file_exists($this->fs->resolve($destRel, false))) throw new RouteException('A folder named ' . basename($destRel) . ' already exists here. Rename it, or choose "Extract here".', 409);
        }
        $z = new ZipArchive();
        if ($z->open($abs, ZipArchive::RDONLY) !== true) throw new RouteException('This is not a valid zip file.');
        if ($z->numFiles > self::ZIP_MAX_ENTRIES) throw new RouteException('The archive has more than ' . self::ZIP_MAX_ENTRIES . ' items.', 413);

        $plan = []; $skipped = []; $total = 0;
        $skip = function (string $n, string $why) use (&$skipped) { $skipped[] = ['name' => $n, 'reason' => $why]; };
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i); $n = str_replace('\\', '/', (string)$st['name']);
            if ($n === '' || $n[0] === '/' || preg_match('/[:\x00-\x1f]/', $n)) throw new RouteException('The archive contains an unsafe path, so nothing was extracted.', 400);
            $isDir = str_ends_with($n, '/'); $segs = explode('/', rtrim($n, '/'));
            foreach ($segs as $sg) if (Files::badName($sg)) throw new RouteException('The archive contains a name that is not allowed, so nothing was extracted.', 400);
            $z->getExternalAttributesIndex($i, $os, $attr);
            if ($os === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000)) { $skip($n, 'links are not extracted'); continue; }
            if (!$this->code) {
                foreach ($segs as $sg) if (str_starts_with($sg, '.') || $sg === '__MACOSX' || PortalPolicy::hidden($sg, false)) { $skip($n, 'hidden item'); continue 2; }
                foreach ($segs as $sg) if (PortalPolicy::nameError($sg, false)) { $skip($n, 'file type not allowed'); continue 2; }   // folders follow the same naming rules as mkdir
            }
            if (!$isDir) $total += (int)$st['size'];
            $plan[] = [$n, $isDir];
        }
        $room = min(Files::MAX_UNZIP, $this->room());
        if ($total > $room) throw new RouteException('The archive expands to ' . round($total / 1048576, 1) . ' MB, more than the room left on this site.', 413);
        if (!$plan) throw new RouteException('Nothing in this archive can be extracted here.', 400);
        foreach ($plan as [$n, $isDir]) {                                  // would anything existing be replaced?
            if ($isDir || !$here) continue;
            try { $t = $this->fs->resolve($destRel . ($destRel !== '' ? '/' : '') . $n, false); } catch (RouteException) { continue; }   // folder does not exist yet: nothing to replace
            if (is_file($t) && empty($this->grant['edit'])) throw new RouteException('Extracting would replace existing files (' . $n . '), which needs edit permission.', 403);
            if (is_file($t) && PortalPolicy::locked(basename($n), $this->code)) throw new RouteException('Extracting would replace a protected file (' . $n . ').', 403);
        }
        if (!is_dir($d = $this->fs->resolve($destRel, false))) mkdir($d, 0777, true);

        $files = 0; $left = $room;
        foreach ($plan as [$n, $isDir]) {
            $full = $destRel . ($destRel !== '' ? '/' : '') . rtrim($n, '/');
            $parent = $isDir ? $full : (dirname($full) === '.' ? '' : dirname($full));
            $cur = '';
            foreach (explode('/', $parent) as $seg) {                      // one level at a time, each checked by the sandbox, so no link can lead out
                if ($seg === '') continue;
                $cur .= ($cur !== '' ? '/' : '') . $seg; $dir = $this->fs->resolve($cur, false);
                if (!is_dir($dir)) @mkdir($dir, 0777);
            }
            if ($isDir) continue;
            $target = $this->fs->resolve($full, false);
            $in = $z->getStream(rtrim($n, '/'));
            if (!$in) { $skip($n, 'could not be read'); continue; }
            $head = (string)fread($in, 262144);
            if (!$this->code && ($e = PortalPolicy::contentError($head, false))) { fclose($in); $skip($n, 'contains program code'); continue; }
            $tmp = $target . '.part' . getmypid(); $out = fopen($tmp, 'wb'); $w = 0; $ok = $out !== false;
            if ($ok) {
                $chunk = $head;
                do {
                    $w += strlen($chunk);
                    if ($w > $left) { $ok = false; break; }
                    fwrite($out, $chunk);
                } while (!feof($in) && ($chunk = (string)fread($in, 262144)) !== '');
                fclose($out);
            }
            fclose($in);
            if (!$ok) { @unlink($tmp); $this->touchQuota(); throw new RouteException('The archive grew past the storage limit while extracting. Part of it may have been written.', 413); }
            if (!@rename($tmp, $target)) { @unlink($tmp); $skip($n, 'could not be written'); continue; }
            $left -= $w; $files++;
        }
        $z->close(); $this->touchQuota();
        $this->log('unzip', $path, true, "$files file(s) → " . ($destRel !== '' ? $destRel : '/') . (count($skipped) ? ', ' . count($skipped) . ' skipped' : ''));
        return ['path' => $destRel, 'files' => $files, 'skipped' => array_slice($skipped, 0, 20), 'skipped_n' => count($skipped)];
    }

    /**
     * A zip of a folder (or one file) for download, built in the temp dir. Protected and hidden files are left out for ordinary
     * users, links are never followed, and the size is capped at the site's storage limit.
     * @return array{abs:string, name:string, temp:true}
     */
    public function zip(string $path): array
    {
        if (!class_exists('ZipArchive')) throw new RouteException('Zip files are not supported on this server (the PHP zip extension is off).', 501);
        if (trim($path, '/') !== '') $this->guardPath($path);
        $abs = $this->fs->resolve($path);
        @set_time_limit(300);
        $base = trim($path, '/') === '' ? preg_replace('/[^A-Za-z0-9._-]/', '_', $this->host) : basename($abs);
        $tmp = tempnam(sys_get_temp_dir(), 'prz'); $z = new ZipArchive();
        if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RouteException('Could not create the archive.', 500);
        $n = 0; $bytes = 0;
        $keep = fn(string $name) => $this->code || (!str_starts_with($name, '.') && !PortalPolicy::locked($name, false) && !PortalPolicy::hidden($name, false));
        $add = function (string $file, string $in) use (&$z, &$n, &$bytes, $tmp) {
            if (++$n > 20000 || ($bytes += (int)filesize($file)) > $this->siteLimit) { $z->close(); @unlink($tmp); throw new RouteException('This is too large to download as one zip. Download folders separately.', 413); }
            $z->addFile($file, $in);
        };
        if (is_file($abs)) { if (!$keep(basename($abs))) { $z->close(); @unlink($tmp); throw new RouteException('This file is protected.', 403); } $add($abs, basename($abs)); }
        else {
            $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
                fn($cur) => !$cur->isLink() && $keep($cur->getFilename())), RecursiveIteratorIterator::SELF_FIRST);
            $it->setMaxDepth(20);
            $z->addEmptyDir($base);
            try {
                foreach ($it as $p => $info) {
                    $in = $base . '/' . ltrim(substr(str_replace('\\', '/', $p), strlen(str_replace('\\', '/', $abs))), '/');
                    $info->isDir() ? $z->addEmptyDir($in) : $add($p, $in);
                }
            } catch (RouteException $e) { @unlink($tmp); throw $e; }
        }
        $z->close();
        if (!is_file($tmp) || ($n === 0 && !is_dir($abs))) { @unlink($tmp); throw new RouteException('Nothing to download here.', 404); }
        $this->log('zip', $path, true, "$n file(s)");
        return ['abs' => $tmp, 'name' => $base . '.zip', 'temp' => true];
    }

    // ---- rules ---------------------------------------------------------------

    private function need(string $perm, string $what): void
    {
        if (empty($this->grant[$perm])) { $this->log($perm, '', false, 'no permission'); throw new RouteException("You don't have permission to $what on this site.", 403); }
    }

    private function nameOk(string $name): void
    {
        if ($e = PortalPolicy::nameError($name, $this->code)) { $this->log('name', $name, false, $e); throw new RouteException($e, 403); }
    }

    private function contentOk(string $c): void
    {
        if ($e = PortalPolicy::contentError($c, $this->code)) { $this->log('content', '', false, $e); throw new RouteException($e, 403); }
    }

    private function notLocked(string $path): void
    {
        $name = basename($path);
        if (PortalPolicy::locked($name, $this->code) && is_file($this->fs->resolve($path, false))) { $this->log('locked', $path, false, 'protected file'); throw new RouteException('This file is protected (it can run programs or holds settings or secrets), so it cannot be opened or changed from here.', 403); }
    }

    /**
     * A folder we are about to enter or list: no part of it may be hidden (.git …) or a dot-folder for an ordinary user.
     * Answers 404 — such folders do not exist as far as that user is concerned.
     */
    private function guardDir(string $path): void
    {
        foreach (explode('/', str_replace('\\', '/', $path)) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if (PortalPolicy::hidden($seg, $this->code) || (!$this->code && str_starts_with($seg, '.'))) throw new RouteException('Not found', 404);
        }
    }

    /** A file or folder NAME at the end of a path: everything before it must pass guardDir; the name itself is judged by the name/lock rules (403 with a reason). */
    private function guardPath(string $path): void
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $this->guardDir(dirname($path) === '.' ? '' : dirname($path));
        if (PortalPolicy::hidden(basename($path), $this->code)) throw new RouteException('Not found', 404);
    }

    private function holdsProtected(string $dir): bool
    {
        $n = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if (++$n > 5000) return true;                                  // too big to check: refuse rather than guess
            if (PortalPolicy::locked($f->getFilename(), false) || PortalPolicy::hidden(basename(dirname($f->getPathname())), false)) return true;
        }
        return false;
    }

    // ---- quota ---------------------------------------------------------------

    private function used(): int
    {
        $calc = fn() => (new ProjectScanner(dirname($this->root)))->inspect(basename($this->root), 20000, 1.5)['bytes'];
        return (int)($this->cache ? $this->cache->remember('quota_' . $this->host, 20, $calc) : $calc());
    }

    private function room(): int { return max(0, $this->siteLimit - $this->used()); }

    private function quota(int $delta): void
    {
        if ($delta > 0 && $delta > $this->room()) { $this->log('quota', '', false, 'site is full'); throw new RouteException('This would exceed the storage limit for this site (' . round($this->siteLimit / 1048576) . ' MB).', 413); }
    }

    private function touchQuota(): void { if ($this->cache) $this->cache->forget('quota_' . $this->host); }

    private function log(string $action, string $path, bool $ok = true, string $detail = ''): void
    {
        $this->log->append($this->user['username'], $this->host, $action, $path, $ok, $this->ip, $detail);
    }
}
