<?php
declare(strict_types=1);

/**
 * Who may reach the portal and who is logged in. The portal is a PUBLIC address (it has to be, for users to reach it),
 * so unlike the admin panel it answers the tunnel — but only under its own hostname, and only its own small API.
 */
class PortalAuth
{
    public const IDLE = 7200;          // 2 h without a request
    public const ABSOLUTE = 43200;     // 12 h since login, whatever happens

    /** @return string|null why this request may not reach the portal at all */
    public static function guard(array $server, array $cfg): ?string
    {
        $host = (string)($cfg['portal']['hostname'] ?? '');
        if ($host === '') return 'The file portal is not switched on.';
        $h = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
        $h = preg_match('/^\[[0-9a-f:]+\](?::\d+)?$/', $h) ? preg_replace('/\]:\d+$/', ']', $h) : preg_replace('/:\d+$/', '', $h);
        if ($h === strtolower($host)) return null;                                   // the public address: fine, from anywhere
        if (in_array($h, Guard::BUILTIN_HOSTS, true) && Guard::check($server) === null) return null;   // the admin testing it on this computer
        return 'This address is not the file portal.';
    }

    public static function isHttps(array $server): bool
    {
        if (($server['HTTPS'] ?? '') !== '' && strtolower((string)$server['HTTPS']) !== 'off') return true;
        if (strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
        return (bool)preg_match('/"scheme"\s*:\s*"https"/', (string)($server['HTTP_CF_VISITOR'] ?? ''));   // Cloudflare terminates TLS, the origin sees http
    }

    /** The visitor's address: Cloudflare's header when the request came through the tunnel, otherwise the socket's. */
    public static function ip(array $server): string
    {
        $cf = (string)($server['HTTP_CF_CONNECTING_IP'] ?? '');
        return filter_var($cf, FILTER_VALIDATE_IP) ? $cf : (string)($server['REMOTE_ADDR'] ?? '');
    }

    public static function start(string $stateDir, bool $secure): void
    {
        $dir = rtrim($stateDir, '/') . '/sessions';
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string)self::ABSOLUTE);
        ini_set('session.gc_probability', '1'); ini_set('session.gc_divisor', '50');
        session_name('tr_portal');
        session_save_path($dir);
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
    }

    /** The raw user record of the logged-in user, or null (and the session is destroyed if it is stale or revoked). */
    public static function user(UserStore $store): ?array
    {
        if (empty($_SESSION['uid'])) return null;
        $now = time();
        if ($now - (int)($_SESSION['last'] ?? 0) > self::IDLE || $now - (int)($_SESSION['t0'] ?? 0) > self::ABSOLUTE) { self::logout(); return null; }
        $u = $store->find((string)$_SESSION['uid']);
        if (!$u || $u['disabled'] || (int)$u['sv'] !== (int)($_SESSION['sv'] ?? -1)) { self::logout(); return null; }   // disabled, reset, or "sign out everywhere"
        $_SESSION['last'] = $now;
        return $u;
    }

    public static function login(array $u): void
    {
        session_regenerate_id(true);                                      // a new session id at login defeats session fixation
        $_SESSION = ['uid' => $u['id'], 'sv' => (int)$u['sv'], 't0' => time(), 'last' => time(), 'csrf' => bin2hex(random_bytes(24))];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
            session_destroy();
        }
    }

    public static function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(24)); }

    public static function csrfOk(array $server): bool
    {
        $t = (string)($server['HTTP_X_CSRF_TOKEN'] ?? '');
        return $t !== '' && isset($_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], $t);
    }
}
