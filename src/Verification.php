<?php

declare(strict_types=1);

namespace App;

final class Verification
{
    public static function request(?int $userId, string $identifier = ''): bool
    {
        $stmt = Database::pdo()->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $email = $stmt->fetchColumn();
        $allowed = RateLimiter::record('reset', $email !== false ? $email : $identifier);

        return $allowed && $userId !== null && self::send($userId);
    }

    public static function send(int $userId): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ? AND email_verified = FALSE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $update = $pdo->prepare('UPDATE users SET verification_token = ?,
            verification_token_expiry = DATE_ADD(NOW(), INTERVAL 24 HOUR)
            WHERE id = ? AND email_verified = FALSE');
        $update->execute([hash('sha256', $token), $userId]);
        if ($update->rowCount() !== 1) {
            return false;
        }

        $link = url('/verify?token=' . $token);
        return Mailer::send($user['email'], $user['name'], 'Verify Your Email',
            "Hello {$user['name']},\n\nHere is your new verification link:\n{$link}\n\n"
            . 'The link is valid for 24 hours.');
    }
}
