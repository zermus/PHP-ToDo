<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Auth
{
    private const REMEMBER_COOKIE = 'rememberMe';
    private const REMEMBER_DAYS = 30;

    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    /**
     * Log a user in (after the caller verified credentials).
     *
     * @param array<string, mixed> $user users table row
     */
    public static function login(array $user, bool $remember): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];

        if ($remember) {
            self::issueRememberToken((int) $user['id']);
        }
    }

    public static function logout(): void
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($cookie !== '' && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            Database::pdo()
                ->prepare('DELETE FROM user_remember_tokens WHERE selector = ?')
                ->execute([$selector]);
        }
        self::clearRememberCookie();

        $_SESSION = [];
        session_destroy();
    }

    /**
     * The current user row, or null. Falls back to the remember-me cookie
     * (validated against a hashed, expiring token, then rotated).
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $pdo = Database::pdo();

        if (isset($_SESSION['user_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([(int) $_SESSION['user_id']]);
            $user = $stmt->fetch();
            if ($user) {
                return self::$user = $user;
            }
            unset($_SESSION['user_id']);
        }

        return self::$user = self::loginViaRememberCookie();
    }

    /**
     * Require a logged-in, email-verified user or redirect to /login.
     *
     * @return array<string, mixed>
     */
    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null || !$user['email_verified']) {
            redirect('/login');
        }

        return $user;
    }

    /**
     * Same as requireLogin() but for JSON endpoints.
     *
     * @return array<string, mixed>
     */
    public static function requireLoginJson(): array
    {
        $user = self::user();
        if ($user === null || !$user['email_verified']) {
            json_response(['success' => false, 'error' => 'Not logged in.'], 401);
        }

        return $user;
    }

    /**
     * Require an admin or super_admin user.
     *
     * @return array<string, mixed>
     */
    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if (!self::isAdmin($user)) {
            redirect('/tasks');
        }

        return $user;
    }

    /** @param array<string, mixed> $user */
    public static function isAdmin(array $user): bool
    {
        return in_array($user['role'], ['admin', 'super_admin'], true);
    }

    /** @return array<string, mixed>|null */
    private static function loginViaRememberCookie(): ?array
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($cookie === '' || !str_contains($cookie, ':')) {
            return null;
        }

        [$selector, $validator] = explode(':', $cookie, 2);

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT t.id AS token_id, t.token_hash, t.expires_at, u.*
             FROM user_remember_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.selector = ?'
        );
        $stmt->execute([$selector]);
        $row = $stmt->fetch();

        if (!$row
            || !hash_equals($row['token_hash'], hash('sha256', $validator))
            || strtotime((string) $row['expires_at']) < time()
            || !$row['email_verified']
        ) {
            if ($row) {
                $pdo->prepare('DELETE FROM user_remember_tokens WHERE id = ?')
                    ->execute([$row['token_id']]);
            }
            self::clearRememberCookie();

            return null;
        }

        // Rotate: single-use token, fresh one issued.
        $pdo->prepare('DELETE FROM user_remember_tokens WHERE id = ?')->execute([$row['token_id']]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['id'];
        self::issueRememberToken((int) $row['id']);

        unset($row['token_id'], $row['token_hash'], $row['expires_at']);

        return $row;
    }

    private static function issueRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));

        Database::pdo()->prepare(
            'INSERT INTO user_remember_tokens (user_id, selector, token_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
        )->execute([$userId, $selector, hash('sha256', $validator), self::REMEMBER_DAYS]);

        setcookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires'  => time() + 86400 * self::REMEMBER_DAYS,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(): void
    {
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            setcookie(self::REMEMBER_COOKIE, '', time() - 3600, '/');
            unset($_COOKIE[self::REMEMBER_COOKIE]);
        }
    }
}
