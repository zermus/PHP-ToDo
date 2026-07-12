<?php

declare(strict_types=1);

namespace App\Installer;

use App\App;
use App\Database;
use App\Mailer;
use PDO;
use PDOException;

final class Installer
{
    /** 0.96 files that a 0.97 upgrade leaves behind in a flat deploy. */
    public const LEGACY_FILES = [
        'calendar.php', 'checklist_item_complete.php', 'confirm_email_change.php',
        'create_task.php', 'edit_task.php', 'forgot_password.php', 'index.php',
        'login.php', 'main.php', 'manage_users.php', 'register.php',
        'reset_password.php', 'send_reminders.php', 'send_task_completion_email.php',
        'task_complete.php', 'user_settings.php', 'verify.php', 'verify_new_email.php',
        'stylesheet.css', 'install.php',
    ];

    /**
     * Connect to the app database, creating it first if it doesn't exist.
     */
    public function connect(): PDO
    {
        try {
            return Database::pdo();
        } catch (PDOException $e) {
            // 1049 = unknown database
            if (($e->errorInfo[1] ?? null) !== 1049) {
                throw $e;
            }
        }

        $name = (string) App::config('db.name');
        $server = Database::serverPdo();
        $server->exec(
            'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $name)
            . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        return Database::pdo();
    }

    public function superAdminExists(PDO $pdo, Migrator $migrator): bool
    {
        if (!$migrator->tableExists('users')) {
            return false;
        }

        return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn() > 0;
    }

    /**
     * Create the super_admin account and default settings; send the
     * verification email. Returns an error message or null on success.
     */
    public function createAdmin(
        PDO $pdo,
        string $name,
        string $username,
        string $email,
        string $timezone,
        string $password,
        string $verifyPassword
    ): ?string {
        if ($name === '' || $username === '' || $email === '' || $timezone === '') {
            return 'All fields are required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Please enter a valid email address.';
        }
        if (!valid_timezone($timezone)) {
            return 'Please choose a valid timezone.';
        }
        if ($password !== $verifyPassword) {
            return 'The passwords do not match. Please try again.';
        }
        if (!password_meets_policy($password)) {
            return 'Password must be at least 8 characters long and include at least one uppercase letter, '
                . 'one lowercase letter, one number, and one special character.';
        }

        $token = bin2hex(random_bytes(32));

        $stmt = $pdo->prepare(
            "INSERT INTO users (name, username, email, password, role, verification_token,
                                verification_token_expiry, timezone)
             VALUES (?, ?, ?, ?, 'super_admin', ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), ?)"
        );
        $stmt->execute([
            $name, $username, $email,
            password_hash($password, PASSWORD_DEFAULT),
            $token, $timezone,
        ]);

        $exists = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE name = 'user_registration'");
        $exists->execute();
        if ((int) $exists->fetchColumn() === 0) {
            $pdo->exec("INSERT INTO settings (name, value) VALUES ('user_registration', '1')");
        }

        $link = url('/verify?token=' . $token);
        Mailer::send(
            $email,
            $name,
            'Verify Your Email',
            "Hello {$name},\n\nPlease click the following link to verify your email and activate "
            . "your admin account:\n{$link}\n\nThe link is valid for 24 hours.\n\nThank you!"
        );

        return null;
    }

    /**
     * Delete leftover 0.96 top-level files. Returns [removed[], failed[]].
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public function removeLegacyFiles(): array
    {
        $removed = [];
        $failed = [];

        foreach (self::LEGACY_FILES as $file) {
            $path = APP_ROOT . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            if (@unlink($path)) {
                $removed[] = $file;
            } else {
                $failed[] = $file;
            }
        }

        return [$removed, $failed];
    }
}
