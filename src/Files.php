<?php
declare(strict_types=1);

/**
 * Sandboxed file operations inside htdocs. Every path is resolved and checked so nothing outside the
 * root — or inside Tunnel Router's own folder — can be read, written or deleted, including via symlinks.
 * Deletes go to a trash folder; overwritten files keep their previous versions.
 */
class Files
{
    public const MAX_EDIT      = 2 * 1024 * 1024;
    public const MAX_UPLOAD    = 100 * 1024 * 1024;
    public const MAX_UNZIP     = 500 * 1024 * 1024;
    public const MAX_UNZIP_N   = 20000;
    public const KEEP_VERSIONS = 10;

    private string $root;
    private string $app;
    private string $trash;
    private string $versions;

    public function __construct(string $htdocs, string $appRoot, string $stateDir, private ActivityLog $log)
    {
        $root = realpath($htdocs);
        if ($root === false || !is_dir($root)) throw new RouteException("htdocs folder not found: $htdocs", 500);
        $this->root = rtrim(normalizePath($root), '/');
        $this->app  = rtrim(normalizePath((string)realpath($appRoot)), '/');
        $state = rtrim(normalizePath($stateDir), '/');
        $this->trash    = "$state/trash";
        $this->versions = "$state/file_versions";
    }

    // ---- path safety ---------------------------------------------------------

    /** Windows device names (CON, NUL, COM1…, with or without an extension) hang or misbehave as file names. */
    public static function reservedName(string $part): bool
    {
        return (bool)preg_match('/^(con|prn|aux|nul|conin\$|conout\$|com[0-9¹²³]|lpt[0-9¹²³])(\..*)?$/iu', $part);
    }

    /** Is this a single path component we are willing to create on any OS? */
    public static function badName(string $part): bool
    {
        return $part === '' || $part === '.' || $part === '..' || preg_match('/[<>:"|?*\x00-\x1f]/', $part) || rtrim($part, '. ') !== $part || self::reservedName($part);
    }

    /** Relative path ("blog/index.php") → absolute path inside the root. Throws on anything unsafe. */
    public function resolve(string $rel, bool $mustExist = true): string
    {
        $abs = $this->lexical($rel);

        if (file_exists($abs) || is_link($abs)) {
            $real = realpath($abs);
            if ($real === false) throw new RouteException('Invalid path');
            $real = normalizePath($real);
        } else {
            if ($mustExist) throw new RouteException('Not found: ' . $rel, 404);
            $parent = realpath(dirname($abs));
            if ($parent === false) throw new RouteException('Parent folder does not exist', 404);
            $real = normalizePath($parent) . '/' . basename($abs);
        }
        if (!$this->within($real, $this->root)) throw new RouteException('Path is outside htdocs');
        if ($this->within($real, $this->app))   throw new RouteException('This is Tunnel Router\'s own folder and cannot be managed here');
        return $real;
    }

    /** Root + the cleaned path parts, without following links. Throws on traversal or bad characters. */
    private function lexical(string $rel): string
    {
        if (str_contains($rel, "\0")) throw new RouteException('Invalid path');
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $rel)) as $p) {
            if ($p === '' || $p === '.') continue;
            if (self::badName($p)) throw new RouteException('Invalid path');
            $parts[] = $p;
        }
        return $this->root . ($parts ? '/' . implode('/', $parts) : '');
    }

    /** For rename/delete: act on a symlink itself rather than what it points to (still sandbox-checked). */
    private function target(string $rel): string
    {
        $abs = $this->resolve($rel);
        $lex = $this->lexical($rel);
        return is_link($lex) ? $lex : $abs;
    }

    public function rel(string $abs): string
    {
        return ltrim(substr(normalizePath($abs), strlen($this->root)), '/');
    }

    private function within(string $path, string $base): bool
    {
        return strcasecmp($path, $base) === 0 || strncasecmp($path, $base . '/', strlen($base) + 1) === 0;
    }

    // ---- reading -------------------------------------------------------------

    public function list(string $rel): array
    {
        $dir = $this->resolve($rel);
        if (!is_dir($dir)) throw new RouteException('Not a folder');
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $abs = "$dir/$name";
            if ($this->within(normalizePath((string)(realpath($abs) ?: $abs)), $this->app)) continue; // hide the tool's own folder
            $isDir = is_dir($abs);
            $out[] = ['name' => $name, 'path' => $this->rel($abs), 'type' => $isDir ? 'dir' : 'file', 'size' => $isDir ? null : (int)@filesize($abs),
                      'mtime' => (int)@filemtime($abs), 'perms' => sprintf('%03o', @fileperms($abs) & 0777), 'ext' => $isDir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)), 'link' => is_link($abs)];
        }
        usort($out, fn($a, $b) => [$a['type'] === 'dir' ? 0 : 1, strtolower($a['name'])] <=> [$b['type'] === 'dir' ? 0 : 1, strtolower($b['name'])]);
        return $out;
    }

    /** Text content for the editor, or why it can't be edited. */
    public function read(string $rel): array
    {
        $f = $this->resolve($rel);
        if (!is_file($f)) throw new RouteException('Not a file');
        $size = (int)filesize($f);
        $base = ['path' => $this->rel($f), 'size' => $size, 'mtime' => (int)filemtime($f)];
        if ($size > self::MAX_EDIT) return $base + ['editable' => false, 'reason' => 'File is larger than ' . (self::MAX_EDIT >> 20) . ' MB'];
        $data = (string)file_get_contents($f);
        if (str_contains(substr($data, 0, 8192), "\0") || !mb_check_encoding($data, 'UTF-8')) return $base + ['editable' => false, 'reason' => 'Binary or non-UTF-8 file'];
        return $base + ['editable' => true, 'content' => $data];
    }

    // ---- writing -------------------------------------------------------------

    /** Save text. $expectedMtime guards against overwriting a change made elsewhere (409 with the new mtime). */
    public function write(string $rel, string $content, ?int $expectedMtime): array
    {
        if (strlen($content) > self::MAX_EDIT) throw new RouteException('Content too large to save here');
        $f = $this->resolve($rel, false);
        if (is_dir($f)) throw new RouteException('That is a folder');
        if (is_file($f)) {
            clearstatcache(true, $f);
            if ($expectedMtime !== null && (int)filemtime($f) !== $expectedMtime) {
                throw new RouteException('The file changed on disk since you opened it.', 409, ['mtime' => (int)filemtime($f)]);
            }
            $this->keepVersion($f);
        }
        atomicWrite($f, $content);
        clearstatcache(true, $f);
        $this->log->append('file_save', $this->rel($f), true, strlen($content) . ' bytes');
        return ['path' => $this->rel($f), 'mtime' => (int)filemtime($f), 'size' => strlen($content)];
    }

    public function create(string $rel): void
    {
        $f = $this->resolve($rel, false);
        if (file_exists($f)) throw new RouteException('Already exists');
        atomicWrite($f, '');
        $this->log->append('file_create', $this->rel($f), true, 'file');
    }

    public function mkdir(string $rel): void
    {
        $d = $this->resolve($rel, false);
        if (file_exists($d)) throw new RouteException('Already exists');
        if (!@mkdir($d, 0777)) throw new RouteException('Could not create folder');
        $this->log->append('file_create', $this->rel($d), true, 'folder');
    }

    public function rename(string $from, string $to): void
    {
        $a = $this->target($from); $b = $this->resolve($to, false);
        $this->notRoot($a);
        if (file_exists($b)) throw new RouteException('Target already exists');
        if ($this->within($b, $a)) throw new RouteException('Cannot move a folder into itself');
        if (!@rename($a, $b)) throw new RouteException('Rename failed');
        $this->log->append('file_move', $this->rel($a), true, '→ ' . $this->rel($b));
    }

    public function copy(string $from, string $to): void
    {
        $a = $this->resolve($from); $b = $this->resolve($to, false);
        if (file_exists($b)) throw new RouteException('Target already exists');
        if ($this->within($b, $a)) throw new RouteException('Cannot copy a folder into itself');
        @set_time_limit(300);
        $this->copyTree($a, $b);
        $this->log->append('file_copy', $this->rel($a), true, '→ ' . $this->rel($b));
    }

    /** Move to trash (recoverable). Returns trash ids. */
    public function delete(array $rels): array
    {
        $ids = [];
        foreach ($rels as $rel) {
            $abs = $this->target((string)$rel);
            $this->notRoot($abs);
            $id = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
            $dir = "{$this->trash}/$id";
            if (!is_dir($dir) && !@mkdir($dir, 0777, true)) throw new RouteException('Cannot create the trash folder (check permissions on state/)', 500);
            $meta = ['id' => $id, 'root' => $this->root, 'from' => $this->rel($abs), 'name' => basename($abs), 'type' => is_dir($abs) ? 'dir' : 'file', 'ts' => time(), 'size' => is_dir($abs) ? null : (int)filesize($abs)];
            if (!@rename($abs, "$dir/item")) { $this->copyTree($abs, "$dir/item"); $this->removeTree($abs); } // different volume
            atomicWrite("$dir/meta.json", jsonPretty($meta));
            $this->log->append('file_delete', $meta['from'], true, 'moved to trash');
            $ids[] = $id;
        }
        return $ids;
    }

    public function trashList(): array
    {
        $out = [];
        foreach (glob($this->trash . '/*/meta.json') ?: [] as $m) {
            $j = json_decode((string)file_get_contents($m), true);
            if ($j && strcasecmp((string)($j['root'] ?? $this->root), $this->root) === 0) $out[] = $j;   // only this site's items
        }
        usort($out, fn($a, $b) => $b['ts'] <=> $a['ts']);
        return $out;
    }

    public function trashRestore(string $id): string
    {
        if (!preg_match('/^[0-9]{8}_[0-9]{6}_[0-9a-f]{6}$/', $id)) throw new RouteException('Invalid trash id');
        $dir = "{$this->trash}/$id";
        $meta = is_file("$dir/meta.json") ? json_decode((string)file_get_contents("$dir/meta.json"), true) : null;
        if (!$meta || strcasecmp((string)($meta['root'] ?? $this->root), $this->root) !== 0) throw new RouteException('Not in trash', 404);
        $dest = $this->resolve($meta['from'], false);
        if (file_exists($dest)) throw new RouteException('Something already exists at ' . $meta['from'] . ' — rename or move it first');
        if (!is_dir(dirname($dest))) throw new RouteException('The original folder no longer exists');
        if (!@rename("$dir/item", $dest)) { $this->copyTree("$dir/item", $dest); }
        $this->removeTree($dir);
        $this->log->append('file_restore', $meta['from'], true, 'restored from trash');
        return $meta['from'];
    }

    public function trashEmpty(): int
    {
        $n = 0;
        foreach (glob($this->trash . '/*', GLOB_ONLYDIR) ?: [] as $d) { $this->removeTree($d); $n++; }
        if ($n) $this->log->append('file_trash_empty', '', true, "$n item(s) permanently deleted");
        return $n;
    }

    // ---- versions ------------------------------------------------------------

    public function versions(string $rel): array
    {
        $dir = $this->versionDir($this->resolve($rel));
        $out = [];
        foreach (glob("$dir/*.txt") ?: [] as $f) $out[] = ['id' => basename($f, '.txt'), 'ts' => (int)filemtime($f), 'size' => (int)filesize($f)];
        usort($out, fn($a, $b) => strcmp($b['id'], $a['id']));
        return $out;
    }

    public function versionContent(string $rel, string $id): string
    {
        if (!preg_match('/^\d+_[0-9a-f]{4}$/', $id)) throw new RouteException('Invalid version id');
        $f = $this->versionDir($this->resolve($rel)) . "/$id.txt";
        if (!is_file($f)) throw new RouteException('Version not found', 404);
        return (string)file_get_contents($f);
    }

    // ---- upload / zip --------------------------------------------------------

    /** @param array $files one PHP $_FILES entry in the multi-file shape */
    public function upload(string $dirRel, array $files, bool $overwrite, ?callable $mover = null): array
    {
        $dir = $this->resolve($dirRel);
        if (!is_dir($dir)) throw new RouteException('Not a folder');
        $out = [];
        foreach ((array)($files['name'] ?? []) as $i => $orig) {
            $name = trim(basename(str_replace('\\', '/', (string)$orig)));
            try {
                if (($files['error'][$i] ?? 0) !== UPLOAD_ERR_OK) throw new RouteException('Upload error code ' . ($files['error'][$i] ?? '?') . ' (check upload_max_filesize / post_max_size in php.ini)');
                if (self::badName($name)) throw new RouteException('Invalid file name');
                if (($files['size'][$i] ?? 0) > self::MAX_UPLOAD) throw new RouteException('Larger than ' . (self::MAX_UPLOAD >> 20) . ' MB');
                $dest = $this->resolve(($this->rel($dir) !== '' ? $this->rel($dir) . '/' : '') . $name, false);
                if (file_exists($dest) && !$overwrite) throw new RouteException('Already exists');
                if (is_file($dest)) $this->keepVersion($dest);
                if (!($mover ?? 'move_uploaded_file')($files['tmp_name'][$i], $dest)) throw new RouteException('Could not store the file');
                $this->log->append('file_upload', $this->rel($dest), true, (int)($files['size'][$i] ?? 0) . ' bytes');
                $out[] = ['id' => $name, 'ok' => true];
            } catch (RouteException $e) { $out[] = ['id' => $name ?: (string)$orig, 'ok' => false, 'error' => $e->getMessage()]; }
        }
        return $out;
    }

    /** Octal permission change, e.g. "644". Only rwx bits for owner/group/others — no setuid/sticky. */
    public function chmod(string $rel, string $mode): string
    {
        if (!preg_match('/^[0-7]{3}$/', $mode)) throw new RouteException('Mode must be three octal digits, e.g. 644 or 755');
        $abs = $this->target($rel);
        $this->notRoot($abs);
        if (is_link($abs)) throw new RouteException('Permissions of a link cannot be changed here');
        if (!@chmod($abs, octdec($mode))) throw new RouteException('chmod failed — the web server user may not own this file');
        // (On Windows PHP only maps this to the read-only flag: owner-write on = normal, off = read-only.)
        $this->log->append('file_chmod', $this->rel($abs), true, $mode);
        return sprintf('%03o', fileperms($abs) & 0777);
    }

    /** Zip the given items into a new .zip inside the sandbox (Hostinger-style "Compress"). Returns its path. */
    public function compress(string $dirRel, array $rels, ?string $name): string
    {
        $dir = $this->resolve($dirRel);
        if (!is_dir($dir)) throw new RouteException('Not a folder');
        $name = $name !== null && trim($name) !== '' ? trim($name) : 'archive-' . date('Ymd-His') . '.zip';
        if (!str_ends_with(strtolower($name), '.zip')) $name .= '.zip';
        if (!preg_match('/^[A-Za-z0-9._ -]{1,100}$/', $name) || str_starts_with($name, '.')) throw new RouteException('Archive name: letters, digits, space . _ - only');
        $dest = $this->resolve(($this->rel($dir) !== '' ? $this->rel($dir) . '/' : '') . $name, false);
        if (file_exists($dest)) throw new RouteException("$name already exists here");
        $tmp = $this->zip($rels);                        // built in the temp dir, so it can never include itself
        if (!@rename($tmp, $dest) && !(@copy($tmp, $dest) && @unlink($tmp))) { @unlink($tmp); throw new RouteException('Could not store the archive here'); }
        $this->log->append('file_zip', $this->rel($dest), true, count($rels) . ' item(s)');
        return $this->rel($dest);
    }

    /** Zip files/folders into a temp file and return its path (caller streams then deletes it). */
    public function zip(array $rels): string
    {
        if (!class_exists('ZipArchive')) throw new RouteException('PHP zip extension is not enabled');
        @set_time_limit(300);                              // large folders exceed the 30 s default
        $items = array_map(fn($r) => $this->resolve((string)$r), $rels);
        if (!$items) throw new RouteException('Nothing to zip');
        $tmp = tempnam(sys_get_temp_dir(), 'trz');
        $z = new ZipArchive();
        if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RouteException('Cannot create zip');
        foreach ($items as $abs) {
            $base = basename($abs);
            if (is_dir($abs)) {
                $z->addEmptyDir($base);
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($it as $p => $info) {
                    $real = normalizePath((string)(realpath($p) ?: $p));
                    if (!$this->within($real, $this->root) || $this->within($real, $this->app)) continue; // never follow links out of the sandbox
                    $name = $base . '/' . ltrim(substr(normalizePath($p), strlen(normalizePath($abs))), '/');
                    $info->isDir() ? $z->addEmptyDir($name) : $z->addFile($p, $name);
                }
            } else $z->addFile($abs, $base);
        }
        $z->close();
        return $tmp;
    }

    /** Extract a .zip into a new folder next to it, refusing unsafe entry names and zip bombs. */
    public function unzip(string $rel): string
    {
        if (!class_exists('ZipArchive')) throw new RouteException('PHP zip extension is not enabled');
        @set_time_limit(300);
        $zipPath = $this->resolve($rel);
        $destRel = ($this->rel(dirname($zipPath)) !== '' ? $this->rel(dirname($zipPath)) . '/' : '') . pathinfo($zipPath, PATHINFO_FILENAME);
        $dest = $this->resolve($destRel, false);
        if (file_exists($dest)) throw new RouteException('A folder named ' . basename($dest) . ' already exists here');
        $z = new ZipArchive();
        if ($z->open($zipPath) !== true) throw new RouteException('Not a valid zip file');
        if ($z->numFiles > self::MAX_UNZIP_N) throw new RouteException('Too many files in the archive');
        $total = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            $total += (int)$st['size'];
            $n = str_replace('\\', '/', $st['name']);
            if ($n === '' || $n[0] === '/' || preg_match('/[:\x00-\x1f]/', $n)) throw new RouteException('Archive contains an unsafe path: ' . $st['name']);
            foreach (explode('/', rtrim($n, '/')) as $part) if (self::badName($part)) throw new RouteException('Archive contains a name that is not allowed: ' . $st['name']);
        }
        if ($total > self::MAX_UNZIP) throw new RouteException('Archive expands to more than ' . (self::MAX_UNZIP >> 20) . ' MB');
        mkdir($dest, 0777);
        for ($i = 0; $i < $z->numFiles; $i++) {
            $name = str_replace('\\', '/', $z->getNameIndex($i));
            $target = "$dest/$name";
            if (str_ends_with($name, '/')) { @mkdir($target, 0777, true); continue; }
            @mkdir(dirname($target), 0777, true);
            $in = $z->getStream($name); $out = fopen($target, 'wb');
            if ($in && $out) stream_copy_to_stream($in, $out);
            if ($in) fclose($in); if ($out) fclose($out);
        }
        $z->close();
        $this->log->append('file_unzip', $this->rel($zipPath), true, '→ ' . $this->rel($dest));
        return $this->rel($dest);
    }

    // ---- internals -----------------------------------------------------------

    private function notRoot(string $abs): void
    {
        if (strcasecmp($abs, $this->root) === 0) throw new RouteException('Cannot change the htdocs root itself');
    }

    private function versionDir(string $abs): string
    {
        return $this->versions . '/' . sha1(strtolower(normalizePath($abs)));
    }

    private function keepVersion(string $abs): void
    {
        if (!is_file($abs) || filesize($abs) > self::MAX_EDIT) return;
        $dir = $this->versionDir($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) return; // versions are best-effort; never block a save
        @copy($abs, "$dir/" . time() . '_' . bin2hex(random_bytes(2)) . '.txt');
        $all = glob("$dir/*.txt") ?: []; sort($all);
        foreach (array_slice($all, 0, max(0, count($all) - self::KEEP_VERSIONS)) as $old) @unlink($old);
    }

    private function copyTree(string $a, string $b): void
    {
        if (is_file($a)) { if (!@copy($a, $b)) throw new RouteException('Copy failed'); return; }
        if (!@mkdir($b, 0777, true)) throw new RouteException('Copy failed');
        foreach (scandir($a) ?: [] as $n) {
            if ($n === '.' || $n === '..') continue;
            $src = "$a/$n";
            if (is_link($src)) continue;                     // links are not followed or copied
            $this->copyTree($src, "$b/$n");
        }
    }

    private function removeTree(string $p): void
    {
        if (is_link($p) || is_file($p)) { @unlink($p); return; }
        foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') $this->removeTree("$p/$n");
        @rmdir($p);
    }
}
