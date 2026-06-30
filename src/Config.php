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
        'defaults' => [
            'www_alias' => false,
            'canonical' => 'https',
        ],
    ];

    public static function init(string $stateDir): void
    {
        self::$stateDir = rtrim(normalizePath($stateDir), '/');
    }

    public static function load(): array
    {
        $file = self::$stateDir . '/config.json';
        if (!file_exists($file)) {
            return self::$defaults;
        }
        $raw = json_decode(file_get_contents($file), true);
        if (!is_array($raw)) {
            return self::$defaults;
        }
        return array_replace_recursive(self::$defaults, $raw);
    }

    public static function save(array $config): void
    {
        // Never let an empty token accidentally clear a saved one
        $existing = self::load();
        if (empty($config['cloudflare']['api_token'])) {
            $config['cloudflare']['api_token'] = $existing['cloudflare']['api_token'];
        }
        atomicWrite(self::$stateDir . '/config.json', jsonPretty($config));
    }

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
