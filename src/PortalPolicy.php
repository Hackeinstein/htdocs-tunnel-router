<?php
declare(strict_types=1);

/**
 * What a portal user may create, read or change. The point: a person who can put a program into a site folder can run
 * it on this computer, so executable and secret-bearing files are off limits unless that user has been explicitly
 * trusted with "code files". The rules look at EVERY dot-separated part of a name, because Apache can treat
 * "shell.php.jpg" as PHP, and at content, because "x.html" holding <?php … ?> is still a trap on some setups.
 */
class PortalPolicy
{
    /** Extensions that run as programs or reconfigure the server. Checked against every part of the file name. */
    public const CODE = ['php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc', 'shtml', 'shtm', 'stm', 'cgi', 'fcgi', 'pl', 'pm', 'py', 'pyc', 'pyw', 'rb', 'sh', 'bash', 'zsh', 'bat', 'cmd', 'com', 'exe', 'dll', 'msi', 'scr', 'vbs', 'vbe', 'jse', 'wsf', 'wsh', 'ps1', 'psm1', 'jar', 'jsp', 'jspx', 'asp', 'aspx', 'asa', 'cer', 'htaccess', 'htpasswd', 'ini', 'conf', 'config'];
    /** Names that hold secrets or history, whatever their extension. */
    private const SENSITIVE_NAMES = ['.env', '.git', '.gitignore', '.gitattributes', '.svn', '.hg', '.npmrc', '.netrc', '.ssh', '.aws', 'id_rsa', 'id_dsa', 'id_ecdsa', 'id_ed25519', 'composer.lock', 'wp-config.php'];
    private const SENSITIVE_EXT = ['sql', 'sqlite', 'sqlite3', 'db', 'mdb', 'pem', 'key', 'p12', 'pfx', 'log', 'bak', 'old', 'orig', 'swp', 'dump', 'kdbx'];
    private const HIDDEN_FROM_LISTINGS = ['.git', '.svn', '.hg', '.idea', '.vscode', '.ssh', '.aws'];

    /** @return string[] lower-case parts of a file name after the first dot, e.g. "a.PHP.jpg" → ["php","jpg"] */
    private static function parts(string $name): array
    {
        $p = explode('.', strtolower($name));
        array_shift($p);
        return array_filter($p, fn($x) => $x !== '');
    }

    public static function isCode(string $name): bool
    {
        $l = strtolower($name);
        if (str_starts_with($l, '.ht') || $l === 'web.config' || $l === 'php.ini') return true;
        return (bool)array_intersect(self::parts($name), self::CODE);
    }

    public static function isSensitive(string $name): bool
    {
        $l = strtolower($name);
        if (in_array($l, self::SENSITIVE_NAMES, true) || str_starts_with($l, '.env') || str_starts_with($l, 'id_rsa')) return true;
        return (bool)array_intersect(self::parts($name), self::SENSITIVE_EXT);
    }

    /** Files an untrusted user can see exist but may not open, change, rename over or delete. */
    public static function locked(string $name, bool $codeUser): bool
    {
        return !$codeUser && (self::isCode($name) || self::isSensitive($name));
    }

    /** Entries that should not even appear in an untrusted user's listing. */
    public static function hidden(string $name, bool $codeUser): bool
    {
        return !$codeUser && in_array(strtolower($name), self::HIDDEN_FROM_LISTINGS, true);
    }

    /** @return string|null why a NEW name (create / upload / rename target) is refused */
    public static function nameError(string $name, bool $codeUser): ?string
    {
        if ($name === '' || mb_strlen($name) > 150) return 'File names must be 1–150 characters.';
        if ($codeUser) return null;
        if (str_starts_with($name, '.')) return 'Hidden files (names starting with a dot) are not allowed.';
        if (self::isCode($name)) return 'This type of file can run programs on the server, so it is not allowed. Ask the site owner if you really need it.';
        if (self::isSensitive($name)) return 'This type of file (database, key, log or backup) is not allowed here.';
        return null;
    }

    /** @return string|null why this text/bytes may not be saved (server-side script tags hidden in an innocent-looking file) */
    public static function contentError(string $bytes, bool $codeUser): ?string
    {
        if ($codeUser) return null;
        if (preg_match('/<\?(php|=|\s)|<script[^>]+language\s*=\s*["\']?php/i', substr($bytes, 0, 2_000_000))) return 'The content contains server-side script tags (<?php …), which are not allowed.';
        return null;
    }
}
