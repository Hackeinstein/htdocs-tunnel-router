<?php
declare(strict_types=1);

/** What counts as an acceptable password. Length matters more than symbols, so there are no composition rules. */
class PasswordPolicy
{
    public const MIN = 10, MAX = 128;

    /** A few of the most common choices; anything that is merely one of these (any case, trailing digits) is refused. */
    private const COMMON = ['password', 'passw0rd', 'qwerty', 'qwertyuiop', 'letmein', 'welcome', 'admin', 'administrator', 'iloveyou', 'monkey', 'dragon', 'football',
        'baseball', 'abc123', 'trustno1', 'sunshine', 'master', 'shadow', 'superman', 'princess', 'login', 'starwars', 'changeme', 'default', 'secret', 'internet', 'whatever', 'computer'];

    /** @return string|null a human message when the password is not acceptable */
    public static function check(string $pw, string $username = ''): ?string
    {
        $len = mb_strlen($pw);
        if ($len < self::MIN) return 'Use at least ' . self::MIN . ' characters (a few random words make a good password).';
        if ($len > self::MAX) return 'Use at most ' . self::MAX . ' characters.';
        if (trim($pw) === '') return 'The password cannot be only spaces.';
        $l = strtolower($pw);
        if ($username !== '' && str_contains($l, strtolower($username))) return 'The password should not contain your username.';
        $stem = preg_replace('/[0-9!@#$%^&*._-]+$/', '', $l);
        if (in_array($stem, self::COMMON, true) || preg_match('/^(.)\1+$/', $l) || in_array($l, ['1234567890', '0123456789', '12345678910', '1111111111', 'qwertyuiop', 'asdfghjkl;'], true)) return 'That password is too common. Pick something less predictable.';
        if (count(array_unique(mb_str_split($l))) < 5) return 'That password is too repetitive.';
        return null;
    }
}
