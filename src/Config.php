<?php
declare(strict_types=1);

class Config
{
    private static string $stateDir;

    private static array $defaults = [
        'cloudflare' => [
            'account_id'  => '',
            'api_token'   => '',
            'tunnel_id'   => '',
            'tunnel_name' => '',
        ],
        'paths' => [
            'htdocs'      => 'C:/xampp/htdocs',
            'vhosts_file' => 'C:/xampp/apache/conf/extra/httpd-vhosts.conf',
            'apache_bin'  => 'C:/xampp/apache/bin/httpd.exe',
        ],
        'security' => [
            'allowed_hosts' => '',   // extra hostnames (comma separated) the tool may be opened at; localhost/127.0.0.1/::1 always work
        ],
        'portal' => [
            'hostname'      => '',   // the public address users log in at (set by "Enable portal"; empty = portal off)
            'zone_id'       => '',
            'dns_record_id' => '',
            'upload_mb'     => 25,   // largest single upload
            'site_mb'       => 500,  // storage limit per site
        ],
        'defaults' => [
            'www_alias' => false,
            'canonical' => 'https',
            'expiry_action' => 'disable', // what happens when a route expires: disable | detach
            'reload_elevate' => true,     // allow a UAC prompt if a plain `httpd -k restart` is not enough
            'reload_hard'    => false,    // last resort: kill and restart httpd.exe
        ],
    ];

    public static function init(string $stateDir): void
    {
        self::$stateDir = rtrim(normalizePath($stateDir), '/');
        self::$memo = null;
    }

    /** Parsed once per request (and whenever the file changes): the code asks for the config dozens of times. */
    private static ?array $memo = null;
    private static int $memoStamp = -1;

    public static function load(): array
    {
        $file = self::$stateDir . '/config.json';
        $stamp = file_exists($file) ? (int)filemtime($file) * 1000 + (int)filesize($file) : 0;
        if (self::$memo !== null && self::$memoStamp === $stamp) return self::$memo;
        $raw = $stamp ? json_decode((string)file_get_contents($file), true) : null;
        self::$memoStamp = $stamp;
        return self::$memo = is_array($raw) ? array_replace_recursive(self::$defaults, $raw) : self::$defaults;
    }

    public static function save(array $config): void
    {
        // Never let an empty token accidentally clear a saved one
        $existing = self::load();
        if (empty($config['cloudflare']['api_token'])) {
            $config['cloudflare']['api_token'] = $existing['cloudflare']['api_token'];
        }
        atomicWrite(self::$stateDir . '/config.json', jsonPretty($config));
        self::$memo = null;
    }

    /**
     * Validate and normalise a config submitted from the UI. Only known keys survive, every value is a
     * string/bool of sane shape (no newlines, no shell metacharacters in paths, token charset limited),
     * and a blank token keeps the saved one. Throws RouteException with a readable reason.
     */
    public static function clean(array $in): array
    {
        $cur = self::load();
        $str = fn($v, int $max) => is_string($v) ? mb_substr(trim($v), 0, $max) : '';
        $cf = (array)($in['cloudflare'] ?? []); $pa = (array)($in['paths'] ?? []); $de = (array)($in['defaults'] ?? []);

        $token = $str($cf['api_token'] ?? '', 255) ?: $cur['cloudflare']['api_token'];
        if ($token !== '' && !preg_match('/^[A-Za-z0-9_\-]+$/', $token)) throw new RouteException('API token may only contain letters, digits, - and _');
        $acct = $str($cf['account_id'] ?? '', 64);
        if ($acct !== '' && !preg_match('/^[a-f0-9]{32}$/i', $acct)) throw new RouteException('Account ID should be 32 hex characters');
        $tid = $str($cf['tunnel_id'] ?? '', 64);
        if ($tid !== '' && !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $tid)) throw new RouteException('Tunnel ID should be a UUID');
        $tname = $str($cf['tunnel_name'] ?? '', 100);
        if (preg_match('/[\x00-\x1f<>]/', $tname)) throw new RouteException('Tunnel name has invalid characters');

        $paths = [];
        foreach (['htdocs', 'vhosts_file', 'apache_bin'] as $k) {
            $v = $str($pa[$k] ?? '', 500);
            if ($v === '' || preg_match('/[\x00-\x1f"&|<>^%`$;]/', $v)) throw new RouteException("Invalid path for $k (empty, or contains quotes, & | < > ^ % ` \$ ; or control characters)");
            $paths[$k] = $v;
        }
        $canon = $str($de['canonical'] ?? 'https', 10);
        $act   = $str($de['expiry_action'] ?? 'disable', 10);
        if (!in_array($canon, ['https', 'http'], true)) throw new RouteException('Canonical must be https or http');
        if (!in_array($act, ['disable', 'detach'], true)) throw new RouteException('Expiry action must be disable or detach');

        $hosts = [];
        foreach (explode(',', strtolower((string)(((array)($in['security'] ?? []))['allowed_hosts'] ?? ''))) as $h) {
            $h = trim($h);
            if ($h === '') continue;
            if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $h)) throw new RouteException("Allowed host '$h' is not a valid hostname");
            $hosts[$h] = true;
        }
        if (count($hosts) > 10) throw new RouteException('At most 10 allowed hosts');

        $po = (array)($in['portal'] ?? []);
        $up = (int)($po['upload_mb'] ?? $cur['portal']['upload_mb']); $st = (int)($po['site_mb'] ?? $cur['portal']['site_mb']);
        if ($up < 1 || $up > 500) throw new RouteException('Largest upload: 1–500 MB');
        if ($st < 10 || $st > 102400) throw new RouteException('Storage per site: 10–102400 MB');

        return ['portal' => ['hostname' => $cur['portal']['hostname'], 'zone_id' => $cur['portal']['zone_id'], 'dns_record_id' => $cur['portal']['dns_record_id'], 'upload_mb' => $up, 'site_mb' => $st],
                'security' => ['allowed_hosts' => implode(',', array_keys($hosts))], 'cloudflare' => ['account_id' => $acct, 'api_token' => $token, 'tunnel_id' => $tid, 'tunnel_name' => $tname],
                'paths' => $paths, 'defaults' => ['www_alias' => filter_var($de['www_alias'] ?? false, FILTER_VALIDATE_BOOLEAN), 'canonical' => $canon, 'expiry_action' => $act,
                'reload_elevate' => filter_var($de['reload_elevate'] ?? true, FILTER_VALIDATE_BOOLEAN), 'reload_hard' => filter_var($de['reload_hard'] ?? false, FILTER_VALIDATE_BOOLEAN)]];
    }

    /**
     * Make sure state/ and state/backups/ exist, are writable and are not web-readable. A fresh copy of the tool
     * (or one deployed without its state folder) then just works. Returns an error message, or null when fine.
     */
    public static function ensureState(): ?string
    {
        $dir = self::$stateDir;
        foreach ([$dir, "$dir/backups"] as $d) {
            if (!is_dir($d) && !@mkdir($d, 0775, true)) return "Cannot create $d — create the folder by hand and give the Apache/PHP user write access.";
        }
        if (!is_writable($dir)) return "$dir is not writable — give the Apache/PHP user write access to it.";
        if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\n");
        return null;
    }

    /** The portal's public hostname in lower case, or '' when the portal is off. */
    public static function portalHost(): string { return strtolower((string)(self::load()['portal']['hostname'] ?? '')); }

    public static function isConfigured(): bool
    {
        $c = self::load();
        return !empty($c['cloudflare']['api_token'])
            && !empty($c['cloudflare']['tunnel_id']);
    }

    /** Return config with api_token redacted — safe for UI/logs. */
    public static function publicView(): array
    {
        $c = self::load();
        if (!empty($c['cloudflare']['api_token'])) {
            $c['cloudflare']['api_token'] = '••••••••';
        }
        return $c;
    }

    public static function stateDir(): string
    {
        return self::$stateDir;
    }
}
