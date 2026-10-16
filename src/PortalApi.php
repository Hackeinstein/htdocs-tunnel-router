<?php
declare(strict_types=1);

/**
 * The portal's whole server side (public/api.php calls run()). Deliberately separate from the admin api.php: it can only
 * reach what is defined here — login, the user's assigned sites, and file operations inside those sites' folders.
 */
class PortalApi
{
    private array $cfg;
    private UserStore $users;
    private State $state;
    private PortalLog $log;
    private LoginThrottle $throttle;
    private Cache $cache;
    private string $ip;
    private ?array $me = null;

    public static function run(string $stateDir): void
    {
        $api = new self($stateDir);
        try { $api->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', (string)($_GET['route'] ?? '')); }
        catch (RouteException $e) { $api->fail($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400, $e->data); }
        catch (Throwable $e) { error_log('portal: ' . $e->getMessage()); $api->fail('Something went wrong on the server.', 500); }   // never leak internals to the public
    }

    private function __construct(private string $stateDir)
    {
        $this->cfg = Config::load();
        $this->users = new UserStore($stateDir);
        $this->state = new State($stateDir);
        $this->log = new PortalLog($stateDir);
        $this->throttle = new LoginThrottle($stateDir);
        $this->cache = new Cache($stateDir);
        $this->ip = PortalAuth::ip($_SERVER);
    }

    private function handle(string $method, string $route): void
    {
        self::headers();
        if ($why = PortalAuth::guard($_SERVER, $this->cfg)) { http_response_code(404); echo json_encode(['ok' => false, 'error' => $why]); return; }
        PortalAuth::start($this->stateDir, PortalAuth::isHttps($_SERVER));
        if ($method === 'POST' && !PortalAuth::csrfOk($_SERVER) && $route !== '/session') throw new RouteException('Your session expired. Reload the page and try again.', 403);

        $b = $method === 'POST' && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart') ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_POST;
        $key = $method . ' ' . $route;

        // ---- no login needed
        switch ($key) {
            case 'GET /session':
                $u = PortalAuth::user($this->users);
                $this->ok(['csrf' => PortalAuth::csrf(), 'user' => $u ? $this->me($u) : null, 'limits' => $this->limits()]); return;
            case 'GET /invite/check':
                $this->throttleIp(); $u = $this->users->checkInvite((string)($_GET['token'] ?? ''));
                if (!$u) { $this->throttle->fail('ip:' . $this->ip); throw new RouteException('This invitation is invalid or has expired. Ask for a new one.', 410); }
                $this->ok(['username' => $u['username'], 'display' => $u['display']]); return;
            case 'POST /invite/accept':
                $this->throttleIp();
                try { $u = $this->users->acceptInvite((string)($b['token'] ?? ''), (string)($b['password'] ?? '')); }
                catch (RouteException $e) { if ($e->getCode() === 410) $this->throttle->fail('ip:' . $this->ip); throw $e; }
                $raw = $this->users->find($u['id']); PortalAuth::login($raw); $this->log->append($u['username'], '', 'invite', '', true, $this->ip);
                $this->ok(['csrf' => PortalAuth::csrf(), 'user' => $this->me($raw)]); return;
            case 'POST /login': $this->login((string)($b['username'] ?? ''), (string)($b['password'] ?? '')); return;
            case 'POST /logout': PortalAuth::logout(); $this->ok(); return;
        }

        // ---- everything else needs a logged-in user
        $this->me = PortalAuth::user($this->users) ?? throw new RouteException('Please log in.', 401);
        if ($key === 'GET /sites') { $this->ok(['sites' => $this->sites()]); return; }
        if (!str_starts_with($route, '/fs/')) throw new RouteException('Not found', 404);

        $host = strtolower(trim((string)($_GET['host'] ?? $b['host'] ?? '')));
        [$fs, $grant] = $this->site($host);
        $p = (string)($_GET['path'] ?? $b['path'] ?? '');
        switch ($key) {
            case 'GET /fs/list':      $this->ok(['path' => trim($p, '/'), 'entries' => $fs->list($p), 'grant' => $this->grantOut($grant)]); return;
            case 'GET /fs/read':      $this->ok($fs->read($p)); return;
            case 'POST /fs/write':    $this->ok($fs->write($p, (string)($b['content'] ?? ''), isset($b['mtime']) ? (int)$b['mtime'] : null)); return;
            case 'POST /fs/create':   $fs->create($p); $this->ok(); return;
            case 'POST /fs/mkdir':    $fs->mkdir($p); $this->ok(); return;
            case 'POST /fs/rename':   $fs->rename((string)($b['from'] ?? ''), (string)($b['to'] ?? '')); $this->ok(); return;
            case 'POST /fs/delete':   $this->ok($fs->delete((array)($b['paths'] ?? []))); return;
            case 'POST /fs/upload':
                if (empty($_FILES['files'])) throw new RouteException('No files arrived — the upload is probably larger than the server allows (' . ini_get('upload_max_filesize') . ').', 413);
                $this->ok(['results' => $fs->upload((string)($b['dir'] ?? ''), $_FILES['files'], !empty($b['overwrite']))]); return;
            case 'POST /fs/unzip':    $this->ok($fs->unzip($p, !empty($b['here']))); return;
            case 'GET /fs/zip':       $this->stream($fs->zip($p), false); return;
            case 'GET /fs/download': $this->stream($fs->file($p), false); return;
            case 'GET /fs/raw':      $this->stream($fs->file($p), true); return;
        }
        throw new RouteException('Not found', 404);
    }

    // ---- login ---------------------------------------------------------------

    private function login(string $username, string $password): void
    {
        $uk = 'u:' . strtolower(trim($username));
        $wait = max($this->throttle->wait($uk, 5, 900, 900), $this->throttle->wait('ip:' . $this->ip, 30, 900, 900));
        if ($wait > 0) { $this->log->append(strtolower(trim($username)), '', 'login', '', false, $this->ip, 'locked out'); throw new RouteException('Too many attempts. Try again in ' . max(1, (int)ceil($wait / 60)) . ' minute(s).', 429, ['retry_after' => $wait]); }
        $u = strlen($password) <= 1024 ? $this->users->authenticate($username, $password) : null;
        if (!$u) {
            $this->throttle->fail($uk); $this->throttle->fail('ip:' . $this->ip);
            $this->log->append(strtolower(trim($username)), '', 'login', '', false, $this->ip, 'wrong username or password');
            throw new RouteException('Wrong username or password.', 401);      // identical for unknown user, wrong password, disabled and not-yet-invited
        }
        $this->throttle->clear($uk);
        PortalAuth::login($u); $this->me = $u;
        $this->log->append($u['username'], '', 'login', '', true, $this->ip);
        $this->ok(['csrf' => PortalAuth::csrf(), 'user' => $this->me($u)]);
    }

    private function throttleIp(): void
    {
        $w = $this->throttle->wait('ip:' . $this->ip, 30, 900, 900);
        if ($w > 0) throw new RouteException('Too many attempts. Try again later.', 429, ['retry_after' => $w]);
    }

    // ---- sites ---------------------------------------------------------------

    /** A user's valid grants: not expired, the route still exists, is enabled and has the portal switched on. */
    private function sites(): array
    {
        $out = [];
        foreach ($this->users->access($this->me['id']) as $g) {
            if ($g['until'] !== null && $g['until'] <= time()) continue;
            $r = $this->state->findRoute($g['host']);
            if (!$r || ($r['enabled'] ?? true) === false || empty($r['portal_enabled']) || !is_dir($this->projectDir($r))) continue;
            $out[] = ['host' => $g['host'], 'label' => (string)($r['label'] ?? $g['host']), 'grant' => $this->grantOut($g), 'until' => $g['until']];
        }
        return $out;
    }

    /** @return array{0: PortalFiles, 1: array} or a plain 403 — the same answer whatever the reason, so nothing is revealed about other sites */
    private function site(string $host): array
    {
        foreach ($this->sites() as $s) {
            if ($s['host'] !== $host) continue;
            $r = $this->state->findRoute($host); $root = $this->projectDir($r);
            $grant = ['upload' => $s['grant']['upload'], 'edit' => $s['grant']['edit'], 'delete' => $s['grant']['delete']];
            $fs = new PortalFiles(new Files($root, APP_ROOT, $this->stateDir, new NullActivityLog($this->stateDir)), $this->me, $grant, $host, $root, $this->log, $this->ip,
                (int)$this->cfg['portal']['upload_mb'] * 1048576, (int)$this->cfg['portal']['site_mb'] * 1048576, $this->cache);
            return [$fs, $grant];
        }
        throw new RouteException("You don't have access to that site.", 403);
    }

    private function projectDir(array $route): string
    {
        $p = (string)($route['project'] ?? '');
        return sanitizeProjectName($p) ? rtrim($this->cfg['paths']['htdocs'], '/\\') . '/' . $p : '/nonexistent';
    }

    private function grantOut(array $g): array { return ['upload' => !empty($g['upload']), 'edit' => !empty($g['edit']), 'delete' => !empty($g['delete'])]; }
    private function me(array $u): array { return ['username' => $u['username'], 'display' => $u['display']]; }
    private function limits(): array { return ['upload_mb' => (int)$this->cfg['portal']['upload_mb'], 'site_mb' => (int)$this->cfg['portal']['site_mb']]; }

    // ---- output --------------------------------------------------------------

    private static function headers(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
    }

    private function ok(array $data = []): void { echo json_encode(['ok' => true] + $data); }

    private function fail(string $msg, int $code, array $extra = []): void
    {
        if (!headers_sent()) { self::headers(); http_response_code($code); }
        echo json_encode(['ok' => false, 'error' => $msg] + $extra);
    }

    /**
     * Send a user's file. User content is untrusted: it never runs in the portal's origin. Everything is an attachment except
     * plain raster images (for previews), and every response is sandboxed with nosniff so a renamed HTML/SVG cannot execute.
     */
    private function stream(array $f, bool $inline): void
    {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $img = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        $show = $inline && isset($img[$ext]);
        while (ob_get_level()) ob_end_clean();
        header_remove('Content-Type');
        header('Content-Type: ' . ($show ? $img[$ext] : 'application/octet-stream'));
        header('Content-Disposition: ' . ($show ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', $f['name']) . '"');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($f['abs']));
        readfile($f['abs']);
        if (!empty($f['temp'])) @unlink($f['abs']);
        exit;
    }
}
