<?php
declare(strict_types=1);

/**
 * Keeps the control panel private without a login: it only answers a browser on THIS computer, addressed by a
 * known local name. The tool can write files into htdocs and holds the Cloudflare token, so it must never be
 * served through the tunnel — e.g. when a hostname points at the tunnel but has no working vhost and Apache falls
 * back to the default site (cloudflared connects from localhost, so the client address alone proves nothing).
 *
 * Refused: requests carrying proxy/CDN headers, a client address that is not loopback, and any Host header that is
 * not on the allow-list (which also defeats DNS-rebinding, where a hostile site resolves to 127.0.0.1).
 */
class Guard
{
    public const BUILTIN_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];
    private const PROXY_HEADERS = ['HTTP_CF_CONNECTING_IP', 'HTTP_CF_CONNECTING_IPV6', 'HTTP_CF_RAY', 'HTTP_CF_VISITOR', 'HTTP_CF_IPCOUNTRY',
                                   'HTTP_CDN_LOOP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_VIA'];

    /** @return string|null why the request is refused, or null when it is fine */
    public static function check(array $server, string $extraHosts = ''): ?string
    {
        foreach (self::PROXY_HEADERS as $h) {
            if (isset($server[$h]) && $server[$h] !== '') return 'This request came through a proxy or the Cloudflare tunnel.';
        }
        $addr = (string)($server['REMOTE_ADDR'] ?? '');
        if (!in_array($addr, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true) && !str_starts_with($addr, '127.')) {
            return 'This request did not come from this computer.';
        }
        $host = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
        $host = preg_match('/^\[[0-9a-f:]+\](?::\d+)?$/', $host) ? preg_replace('/\]:\d+$/', ']', $host) : preg_replace('/:\d+$/', '', $host);
        $allowed = array_merge(self::BUILTIN_HOSTS, array_filter(array_map('trim', explode(',', strtolower($extraHosts)))));
        if ($host === '' || !in_array($host, $allowed, true)) return 'The address you used (' . preg_replace('/[^a-z0-9.\[\]:-]/i', '', $host) . ') is not allowed. Open the tool at http://localhost/…';
        return null;
    }

    /** Refuse with a plain 403 page and stop. Call before anything else in a web entry point. */
    public static function enforce(string $extraHosts = '', bool $json = false): void
    {
        $why = self::check($_SERVER, $extraHosts);
        if ($why === null) return;
        http_response_code(403);
        if ($json) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $why]); exit; }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Not available</title><body style="font:16px system-ui;max-width:34rem;margin:15vh auto;padding:0 1rem;color:#333">'
           . '<h1 style="font-size:1.3rem">Tunnel Router is private</h1><p>' . htmlspecialchars($why) . '</p>'
           . '<p style="color:#666">It only answers on this computer, at <code>http://localhost/</code>. To use another local name, add it under '
           . '<code>security.allowed_hosts</code> in <code>state/config.json</code> (or Settings → Access).</p>';
        exit;
    }
}
