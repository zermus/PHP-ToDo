<?php

declare(strict_types=1);

namespace App;

final class RateLimiter
{
    public const LOGIN_PAIR_LIMIT = 5;
    public const LOGIN_IP_LIMIT = 20;
    public const LOGIN_WINDOW = 900;
    public const RESET_IP_LIMIT = 3;
    public const RESET_IP_WINDOW = 900;
    public const RESET_EMAIL_LIMIT = 3;
    public const RESET_EMAIL_WINDOW = 3600;
    public const RETENTION = 86400;

    public static function record(string $kind, string $identifier): bool
    {
        $pdo = Database::pdo();
        $ip = request_client_ip();
        $identifier = mb_substr(mb_strtolower($identifier, 'UTF-8'), 0, 190, 'UTF-8');
        $pdo->prepare('INSERT INTO auth_attempts (kind, ip, identifier, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$kind, $ip, $identifier]);
        $pdo->prepare('DELETE FROM auth_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)')
            ->execute([self::RETENTION]);
        if ($kind === 'login') {
            return self::count($kind, 'ip = ? AND identifier = ?', [$ip, $identifier], self::LOGIN_WINDOW) <= self::LOGIN_PAIR_LIMIT
                && self::count($kind, 'ip = ?', [$ip], self::LOGIN_WINDOW) <= self::LOGIN_IP_LIMIT;
        }
        return self::count($kind, 'ip = ?', [$ip], self::RESET_IP_WINDOW) <= self::RESET_IP_LIMIT
            && self::count($kind, 'identifier = ?', [$identifier], self::RESET_EMAIL_WINDOW) <= self::RESET_EMAIL_LIMIT;
    }

    public static function clearLogin(string $username, ?string $ip = null): void
    {
        $sql = "DELETE FROM auth_attempts WHERE kind = 'login' AND identifier = ?";
        $params = [mb_substr(mb_strtolower($username, 'UTF-8'), 0, 190, 'UTF-8')];
        if ($ip !== null) {
            $sql .= ' AND ip = ?';
            $params[] = $ip;
        }
        Database::pdo()->prepare($sql)->execute($params);
    }

    private static function count(string $kind, string $where, array $params, int $window): int
    {
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM auth_attempts
            WHERE kind = ? AND {$where} AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->execute([$kind, ...$params, $window]);
        return (int) $stmt->fetchColumn();
    }
}
