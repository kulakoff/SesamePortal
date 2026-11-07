<?php

declare(strict_types=1);

namespace SesamePortal;

final class Auth
{
    private const REMEMBER_COOKIE = 'sesame_portal_remember';
    // Browser cookie retention is bounded; renew it during use. The server-side
    // device credential has no time limit and remains valid until revoked.
    private const COOKIE_LIFETIME = 400 * 86400;

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('sesame_portal');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            $options = self::cookieOptions(0);
            unset($options['expires']);
            session_set_cookie_params(['lifetime' => 0] + $options);
            session_start();
        }
    }

    public static function user(): ?array
    {
        self::start();
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            $id = self::restoreRememberedLogin();
            if (!$id) {
                return null;
            }
        }

        $stmt = DB::pdo()->prepare('SELECT * FROM users WHERE id = ? AND blocked = 0');
        $stmt->execute([$id]);
        $user = $stmt->fetch() ?: null;
        if (!$user || (isset($_SESSION['auth_fingerprint'])
            && !hash_equals($_SESSION['auth_fingerprint'], self::fingerprint($user)))) {
            self::forgetDevice();
            unset($_SESSION['user_id'], $_SESSION['auth_fingerprint']);
            return null;
        }
        if (isset($_SESSION['remember_token_hash'])) {
            $token = DB::pdo()->prepare('SELECT token_hash FROM remembered_logins WHERE token_hash = ? AND user_id = ?');
            $token->execute([$_SESSION['remember_token_hash'], $id]);
            if (!$token->fetchColumn() || !self::rememberEnabled()) {
                self::forgetDevice();
                unset($_SESSION['user_id'], $_SESSION['auth_fingerprint']);
                return null;
            }
            self::refreshRememberCookie();
        }
        $_SESSION['auth_fingerprint'] = self::fingerprint($user);
        if ($user) {
            TokenService::ensureUserTokens((int)$user['id']);
            $stmt->execute([$id]);
            $user = $stmt->fetch() ?: null;
        }
        return $user;
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if (!$user) {
            Util::redirect('/login');
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
        return $user;
    }

    public static function login(string $login, string $password, bool $remember = false): bool
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM users WHERE login = ? AND blocked = 0');
        $stmt->execute([$login]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Audit::logForUser($user['id'] ?? null, 'auth.login_failed', 'login=' . Audit::cleanValue($login) . ' ip=' . Audit::clientIp());
            return false;
        }

        self::start();
        self::forgetDevice();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['auth_fingerprint'] = self::fingerprint($user);
        unset($_SESSION['csrf']);
        if ($remember && self::rememberEnabled()) {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            DB::pdo()->prepare('INSERT INTO remembered_logins(token_hash, user_id, credential_hash, created_at) VALUES(?, ?, ?, ?)')
                ->execute([$hash, $user['id'], self::fingerprint($user), time()]);
            $_SESSION['remember_token_hash'] = $hash;
            $_COOKIE[self::REMEMBER_COOKIE] = $token;
            self::refreshRememberCookie();
        }
        TokenService::ensureUserTokens((int)$user['id']);
        DB::pdo()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([Util::now(), $user['id']]);
        Audit::logForUser((int)$user['id'], 'auth.login', 'login=' . Audit::cleanValue((string)$user['login']) . ' ip=' . Audit::clientIp());
        return true;
    }

    public static function logout(): void
    {
        self::start();
        self::forgetDevice();
        $_SESSION = [];
        setcookie(session_name(), '', self::cookieOptions(time() - 3600));
        session_destroy();
    }

    public static function rememberEnabled(): bool
    {
        return (bool)Config::get('remember_login_enabled', true);
    }

    public static function revokeRememberedLogins(int $userId): void
    {
        DB::pdo()->prepare('DELETE FROM remembered_logins WHERE user_id = ?')->execute([$userId]);
    }

    private static function cookieOptions(int $expires): array
    {
        // base_url covers TLS termination at a reverse proxy without trusting client headers.
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)parse_url((string)Config::get('base_url'), PHP_URL_SCHEME)) === 'https';
        return ['expires' => $expires, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'];
    }

    private static function fingerprint(array $user): string
    {
        return hash_hmac('sha256', (string)$user['password_hash'], (string)Config::get('app_secret'));
    }

    private static function rememberHash(): ?string
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        return is_string($cookie) && preg_match('/\A[a-f0-9]{64}\z/D', $cookie) ? hash('sha256', $cookie) : null;
    }

    private static function forgetDevice(): void
    {
        foreach (array_unique(array_filter([self::rememberHash(), $_SESSION['remember_token_hash'] ?? null])) as $hash) {
            DB::pdo()->prepare('DELETE FROM remembered_logins WHERE token_hash = ?')->execute([$hash]);
        }
        if (isset($_COOKIE[self::REMEMBER_COOKIE]) || isset($_SESSION['remember_token_hash'])) {
            setcookie(self::REMEMBER_COOKIE, '', self::cookieOptions(time() - 3600));
        }
        unset($_COOKIE[self::REMEMBER_COOKIE], $_SESSION['remember_token_hash'], $_SESSION['remember_cookie_refreshed_at']);
    }

    private static function refreshRememberCookie(): void
    {
        if (($_SESSION['remember_cookie_refreshed_at'] ?? 0) > time() - 86400 || headers_sent()) return;
        if (!hash_equals((string)($_SESSION['remember_token_hash'] ?? ''), (string)self::rememberHash())) return;
        setcookie(self::REMEMBER_COOKIE, $_COOKIE[self::REMEMBER_COOKIE], self::cookieOptions(time() + self::COOKIE_LIFETIME));
        $_SESSION['remember_cookie_refreshed_at'] = time();
    }

    private static function restoreRememberedLogin(): ?int
    {
        $hash = self::rememberHash();
        if (!$hash || !self::rememberEnabled()) {
            if (isset($_COOKIE[self::REMEMBER_COOKIE])) self::forgetDevice();
            return null;
        }
        $stmt = DB::pdo()->prepare('SELECT r.*, u.password_hash, u.blocked FROM remembered_logins r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ?');
        $stmt->execute([$hash]);
        $token = $stmt->fetch();
        if (!$token || (int)$token['blocked'] !== 0
            || !hash_equals((string)$token['credential_hash'], self::fingerprint($token))) {
            self::forgetDevice();
            return null;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$token['user_id'];
        $_SESSION['auth_fingerprint'] = $token['credential_hash'];
        $_SESSION['remember_token_hash'] = $hash;
        unset($_SESSION['csrf'], $_SESSION['remember_cookie_refreshed_at']);
        // Keep the device token stable until revoked. Concurrent launches and
        // Safari's copy of cookies into an installed PWA must not invalidate each other.
        return (int)$token['user_id'];
    }
}
