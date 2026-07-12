<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Mailer;
use App\View;

final class SettingsController
{
    private const URGENCY_GREEN_CHOICES = [60, 120, 240, 480, 720, 1440, 2880, 4320];
    private const URGENCY_CRITICAL_CHOICES = [15, 30, 60, 120, 240, 480, 720, 1440];

    public function form(): void
    {
        $user = Auth::requireLogin();

        $this->render($user, [], []);
    }

    public function save(): void
    {
        $user = Auth::requireLogin();
        Csrf::require();

        $pdo = Database::pdo();
        $userId = (int) $user['id'];

        $errors = [];
        $successes = [];

        // --- Timezone -----------------------------------------------------
        $newTimezone = input_string('timezone');
        if ($newTimezone !== '' && $newTimezone !== $user['timezone']) {
            if (valid_timezone($newTimezone)) {
                $pdo->prepare('UPDATE users SET timezone = ? WHERE id = ?')
                    ->execute([$newTimezone, $userId]);
                $successes[] = 'Timezone updated successfully.';
            } else {
                $errors[] = 'Invalid timezone.';
            }
        }

        // --- Email change (single hop: verify at the new address) ---------
        $newEmail = input_string('email');
        if ($newEmail !== '' && strcasecmp($newEmail, (string) $user['email']) !== 0) {
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email format.';
            } else {
                $inUse = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id != ?');
                $inUse->execute([$newEmail, $userId]);
                if ((int) $inUse->fetchColumn() > 0) {
                    $errors[] = 'That email address is already in use.';
                } else {
                    $token = bin2hex(random_bytes(32));
                    $pdo->prepare('UPDATE users SET new_email = ?, new_email_token = ?,
                                   new_email_token_expiry = DATE_ADD(NOW(), INTERVAL 24 HOUR)
                                   WHERE id = ?')
                        ->execute([$newEmail, $token, $userId]);

                    $link = url('/settings/verify-new-email?token=' . $token);
                    Mailer::send(
                        $newEmail,
                        (string) $user['name'],
                        'Verify Your New Email Address',
                        "Hello {$user['name']},\n\nA request was made to change your To-Do App email "
                        . "address to this one. Click the link below to confirm:\n{$link}\n\n"
                        . "The link is valid for 24 hours. If you did not request this, you can "
                        . "ignore this email.\n\nThank you!"
                    );
                    Mailer::send(
                        (string) $user['email'],
                        (string) $user['name'],
                        'Email Change Requested',
                        "Hello {$user['name']},\n\nA request was made to change your To-Do App email "
                        . "address to {$newEmail}. A verification link has been sent to that address.\n\n"
                        . "If you did not request this, please change your password immediately."
                    );

                    $successes[] = "A verification email has been sent to {$newEmail}. "
                        . 'The change takes effect once you confirm it there.';
                }
            }
        }

        // --- Password change ----------------------------------------------
        $currentPassword = (string) ($_POST['currentPassword'] ?? '');
        $newPassword = (string) ($_POST['newPassword'] ?? '');
        if ($newPassword !== '') {
            if ($currentPassword === '' || !password_verify($currentPassword, (string) $user['password'])) {
                $errors[] = 'Current password is incorrect.';
            } elseif (!password_meets_policy($newPassword)) {
                $errors[] = 'New password does not meet the requirements.';
            } else {
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                    ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
                // Sign out remembered sessions elsewhere.
                $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?')->execute([$userId]);
                $successes[] = 'Password updated successfully.';
            }
        }

        // --- Urgency thresholds -------------------------------------------
        $urgencyGreen = (int) ($_POST['urgency_green'] ?? 0);
        $urgencyCritical = (int) ($_POST['urgency_critical'] ?? 0);
        if (!in_array($urgencyGreen, self::URGENCY_GREEN_CHOICES, true)
            || !in_array($urgencyCritical, self::URGENCY_CRITICAL_CHOICES, true)
        ) {
            $errors[] = 'Invalid urgency selection.';
        } elseif ($urgencyCritical >= $urgencyGreen) {
            $errors[] = 'Critical urgency must be less than Green urgency.';
        } elseif ($urgencyGreen !== (int) $user['urgency_green']
            || $urgencyCritical !== (int) $user['urgency_critical']
        ) {
            $pdo->prepare('UPDATE users SET urgency_green = ?, urgency_critical = ? WHERE id = ?')
                ->execute([$urgencyGreen, $urgencyCritical, $userId]);
            $successes[] = 'Urgency settings updated successfully.';
        }

        // Re-fetch so the form reflects current state.
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $this->render($user, $errors, $successes);
    }

    public function verifyNewEmail(): void
    {
        $token = input_string('token', $_GET);
        $pdo = Database::pdo();

        $success = false;
        $message = 'This email change link is invalid or has expired.';

        if ($token !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, new_email FROM users
                 WHERE new_email_token = ? AND new_email IS NOT NULL
                   AND new_email_token_expiry > NOW()'
            );
            $stmt->execute([$token]);
            $row = $stmt->fetch();

            if ($row) {
                $inUse = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id != ?');
                $inUse->execute([$row['new_email'], $row['id']]);

                if ((int) $inUse->fetchColumn() > 0) {
                    $message = 'That email address is now in use by another account.';
                    $pdo->prepare('UPDATE users SET new_email = NULL, new_email_token = NULL,
                                   new_email_token_expiry = NULL WHERE id = ?')
                        ->execute([$row['id']]);
                } else {
                    $pdo->prepare('UPDATE users SET email = ?, new_email = NULL, new_email_token = NULL,
                                   new_email_token_expiry = NULL WHERE id = ?')
                        ->execute([$row['new_email'], $row['id']]);
                    $success = true;
                    $message = 'Your email address has been updated successfully.';
                }
            }
        }

        echo View::render('auth/message', [
            'title'   => 'Email Change',
            'success' => $success,
            'message' => $message,
            'action'  => ['href' => url('/settings'), 'label' => 'Back to Settings'],
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @param list<string> $errors
     * @param list<string> $successes
     */
    private function render(array $user, array $errors, array $successes): void
    {
        echo View::render('settings/user_settings', [
            'title'           => 'User Settings',
            'user'            => $user,
            'errors'          => $errors,
            'successes'       => $successes,
            'greenChoices'    => self::URGENCY_GREEN_CHOICES,
            'criticalChoices' => self::URGENCY_CRITICAL_CHOICES,
            'scripts'         => [asset('js/password.js')],
        ]);
    }
}
