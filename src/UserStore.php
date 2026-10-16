<?php
declare(strict_types=1);

/**
 * Portal users and which sites they may manage — a small JSON "database" (state/users.json).
 * Every change is a locked read-modify-write; password hashes never leave this class.
 *
 * {
 *   "version": 1,
 *   "users":  [{id, username, display, pw, disabled, code_files, sv, created_at, last_login, invite: {h, exp}|null}],
 *   "access": [{user, host, upload, edit, delete, until}]
 * }
 *
 * `sv` is the session version: bumping it signs the user out everywhere. A user with pw=null cannot log in until an
 * invite is accepted (new users, and after an admin reset).
 */
class UserStore
{
    public const MAX_USERS   = 50;
    public const INVITE_TTL  = 172800;                  // 48 hours
    /** bcrypt cost; tests lower it so the suite stays fast */
    public static int $cost = 12;

    private string $file;

    public function __construct(string $stateDir)
    {
        $this->file = rtrim(normalizePath($stateDir), '/') . '/users.json';
    }

    // ---- reading -------------------------------------------------------------

    /** Users without secrets (no hash, no invite token). */
    public function all(): array
    {
        return array_map([$this, 'public'], $this->load()['users']);
    }

    public function find(string $id): ?array
    {
        foreach ($this->load()['users'] as $u) if ($u['id'] === $id) return $u;
        return null;
    }

    public function findPublic(string $id): ?array { $u = $this->find($id); return $u ? $this->public($u) : null; }

    public function byUsername(string $name): ?array
    {
        $name = strtolower(trim($name));
        foreach ($this->load()['users'] as $u) if ($u['username'] === $name) return $u;
        return null;
    }

    public function count(): int { return count($this->load()['users']); }

    /** All grants, or only those for one user / one host. */
    public function access(?string $userId = null, ?string $host = null): array
    {
        return array_values(array_filter($this->load()['access'], fn($a) => ($userId === null || $a['user'] === $userId) && ($host === null || $a['host'] === $host)));
    }

    // ---- accounts ------------------------------------------------------------

    /** @return array{user: array, token: string} the one-time invite token is shown to the admin once and only its hash is kept */
    public function create(string $username, string $display, bool $codeFiles): array
    {
        $username = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/', $username)) throw new RouteException('Username: 3–32 characters, lowercase letters, digits, . _ -, starting with a letter or digit');
        $display = trim($display) !== '' ? trim($display) : $username;
        if (mb_strlen($display) > 60 || preg_match('/[<>\x00-\x1f]/', $display)) throw new RouteException('Name: up to 60 characters, no < > or control characters');
        return $this->mutate(function (array &$db) use ($username, $display, $codeFiles) {
            if (count($db['users']) >= self::MAX_USERS) throw new RouteException('The limit of ' . self::MAX_USERS . ' users has been reached', 409);
            foreach ($db['users'] as $u) if ($u['username'] === $username) throw new RouteException('That username is already taken', 409);
            $token = bin2hex(random_bytes(24));
            $db['users'][] = $u = ['id' => 'u_' . bin2hex(random_bytes(4)), 'username' => $username, 'display' => $display, 'pw' => null, 'disabled' => false,
                'code_files' => $codeFiles, 'sv' => 1, 'created_at' => nowUtc(), 'last_login' => null, 'invite' => ['h' => hash('sha256', $token), 'exp' => time() + self::INVITE_TTL]];
            return ['user' => $this->public($u), 'token' => $token];
        });
    }

    /** Patch display name / code-file permission / disabled flag. Disabling also signs the user out. */
    public function update(string $id, array $patch): array
    {
        return $this->mutate(function (array &$db) use ($id, $patch) {
            foreach ($db['users'] as &$u) {
                if ($u['id'] !== $id) continue;
                if (isset($patch['display'])) {
                    $d = trim((string)$patch['display']);
                    if ($d === '' || mb_strlen($d) > 60 || preg_match('/[<>\x00-\x1f]/', $d)) throw new RouteException('Name: 1–60 characters, no < > or control characters');
                    $u['display'] = $d;
                }
                if (isset($patch['code_files'])) $u['code_files'] = (bool)$patch['code_files'];
                if (isset($patch['disabled'])) { $u['disabled'] = (bool)$patch['disabled']; if ($u['disabled']) $u['sv']++; }
                return $this->public($u);
            }
            throw new RouteException('User not found', 404);
        });
    }

    /** Forget the password and issue a fresh invite (admin "reset"). The old password stops working at once. */
    public function resetInvite(string $id): array
    {
        return $this->mutate(function (array &$db) use ($id) {
            foreach ($db['users'] as &$u) {
                if ($u['id'] !== $id) continue;
                $token = bin2hex(random_bytes(24));
                $u['pw'] = null; $u['sv']++; $u['invite'] = ['h' => hash('sha256', $token), 'exp' => time() + self::INVITE_TTL];
                return ['user' => $this->public($u), 'token' => $token];
            }
            throw new RouteException('User not found', 404);
        });
    }

    /** Sign the user out of every browser. */
    public function signOutEverywhere(string $id): void
    {
        $this->mutate(function (array &$db) use ($id) {
            foreach ($db['users'] as &$u) if ($u['id'] === $id) { $u['sv']++; return; }
            throw new RouteException('User not found', 404);
        });
    }

    public function delete(string $id): void
    {
        $this->mutate(function (array &$db) use ($id) {
            $n = count($db['users']);
            $db['users']  = array_values(array_filter($db['users'],  fn($u) => $u['id'] !== $id));
            $db['access'] = array_values(array_filter($db['access'], fn($a) => $a['user'] !== $id));
            if (count($db['users']) === $n) throw new RouteException('User not found', 404);
        });
    }

    // ---- invites and passwords ----------------------------------------------

    /** The user an unexpired invite token belongs to (for the "choose a password" page), or null. */
    public function checkInvite(string $token): ?array
    {
        $h = hash('sha256', $token);
        foreach ($this->load()['users'] as $u) {
            if (!empty($u['invite']) && hash_equals($u['invite']['h'], $h) && $u['invite']['exp'] > time() && !$u['disabled']) return $this->public($u);
        }
        return null;
    }

    /** Set the password from an invite. The token is single use. */
    public function acceptInvite(string $token, string $password): array
    {
        $h = hash('sha256', $token);
        $found = $this->checkInvite($token) ?? throw new RouteException('This invitation is invalid or has expired. Ask for a new one.', 410);
        if ($err = PasswordPolicy::check($password, $found['username'])) throw new RouteException($err);
        $hash = self::hash($password);
        return $this->mutate(function (array &$db) use ($h, $hash) {
            foreach ($db['users'] as &$u) {
                if (!empty($u['invite']) && hash_equals($u['invite']['h'], $h) && $u['invite']['exp'] > time()) {
                    $u['pw'] = $hash; $u['invite'] = null; $u['sv']++;
                    return $this->public($u);
                }
            }
            throw new RouteException('This invitation is invalid or has expired. Ask for a new one.', 410);
        });
    }

    /**
     * Check a login. Always spends the same time whether or not the user exists (so response time reveals nothing).
     * @return array|null the raw user record (including `sv`) on success
     */
    public function authenticate(string $username, string $password): ?array
    {
        $u = $this->byUsername($username);
        $ok = password_verify(self::prehash($password), $u['pw'] ?? self::dummyHash());
        if (!$u || !$ok || $u['pw'] === null || $u['disabled']) return null;
        $this->mutate(function (array &$db) use ($u, $password) {
            foreach ($db['users'] as &$x) if ($x['id'] === $u['id']) {
                $x['last_login'] = nowUtc();
                if (password_needs_rehash($x['pw'], PASSWORD_DEFAULT, ['cost' => self::$cost])) $x['pw'] = self::hash($password);
            }
        });
        return $u;
    }

    public static function hash(string $password): string
    {
        return password_hash(self::prehash($password), PASSWORD_DEFAULT, ['cost' => self::$cost]);
    }

    /** bcrypt silently ignores everything after 72 bytes; hashing first makes every character count. */
    private static function prehash(string $password): string { return base64_encode(hash('sha256', $password, true)); }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= password_hash('x', PASSWORD_DEFAULT, ['cost' => self::$cost]);
    }

    // ---- access grants -------------------------------------------------------

    /** Give (or update) a user's access to one host. $until = unix time or null for no end. */
    public function grant(string $userId, string $host, bool $upload, bool $edit, bool $delete, ?int $until): void
    {
        if (!sanitizeHostname($host)) throw new RouteException('Invalid hostname');
        if ($until !== null && $until <= time()) throw new RouteException('"Access until" must be in the future');
        $this->mutate(function (array &$db) use ($userId, $host, $upload, $edit, $delete, $until) {
            if (!array_filter($db['users'], fn($u) => $u['id'] === $userId)) throw new RouteException('User not found', 404);
            $g = ['user' => $userId, 'host' => $host, 'upload' => $upload, 'edit' => $edit, 'delete' => $delete, 'until' => $until];
            foreach ($db['access'] as &$a) if ($a['user'] === $userId && $a['host'] === $host) { $a = $g; return; }
            $db['access'][] = $g;
        });
    }

    public function revoke(string $userId, string $host): void
    {
        $this->mutate(function (array &$db) use ($userId, $host) {
            $db['access'] = array_values(array_filter($db['access'], fn($a) => !($a['user'] === $userId && $a['host'] === $host)));
        });
    }

    /** A route was detached or renamed away: nobody keeps access to it. */
    public function revokeHost(string $host): void
    {
        $this->mutate(function (array &$db) use ($host) { $db['access'] = array_values(array_filter($db['access'], fn($a) => $a['host'] !== $host)); });
    }

    // ---- internals -----------------------------------------------------------

    private function public(array $u): array
    {
        return ['id' => $u['id'], 'username' => $u['username'], 'display' => $u['display'], 'disabled' => (bool)$u['disabled'], 'code_files' => (bool)$u['code_files'],
                'created_at' => $u['created_at'], 'last_login' => $u['last_login'], 'has_password' => $u['pw'] !== null,
                'invite_pending' => !empty($u['invite']) && $u['invite']['exp'] > time(), 'sv' => (int)$u['sv']];
    }

    private function load(): array
    {
        $raw = is_file($this->file) ? json_decode((string)file_get_contents($this->file), true) : null;
        $db = is_array($raw) ? $raw : [];
        return ['version' => 1, 'users' => $db['users'] ?? [], 'access' => $db['access'] ?? []];
    }

    /** Locked read-modify-write; the callback gets the database by reference and may return a value. */
    private function mutate(callable $fn): mixed
    {
        return withLock($this->file, function () use ($fn) {
            $db = $this->load();
            $out = $fn($db);
            atomicWrite($this->file, jsonPretty($db));
            return $out;
        });
    }
}
