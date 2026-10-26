<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Mailer;
use App\RateLimiter;
use App\View;
use App\Verification;

final class AuthController
{
    private const DUMMY_HASH = '$2y$12$LI2EdUCHF8af8cdMN0c/peAJAz8aIhx8/Hpwyc8nzr3zUsVU7IH42';

    public function loginForm(): void
    {
        if (Auth::user() !== null) {
            redirect('/tasks');
        }

        echo View::render('auth/login', ['title' => 'To Do Login Page', 'error' => null]);
    }

    public function login(): void
    {
        if (Auth::user() !== null) {
            redirect('/tasks');
        }

        Csrf::require();

        $username = input_string('username');
        $password = input_password('password');
        $remember = !empty($_POST['rememberMe']);

        $error = null;
        if (!RateLimiter::record('login', $username)) {
            echo View::render('auth/login', ['title' => 'To Do Login Page', 'error' => 'Invalid username or password.']);
            return;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        $valid = password_verify($password, $user ? $user['password'] : self::DUMMY_HASH);
        if (!$user || !$valid) {
            $error = 'Invalid username or password.';
        } elseif (!$user['email_verified']) {
            $error = 'Please verify your email address before logging in.';
        } else {
            RateLimiter::clearLogin($username, request_client_ip());
            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $update = Database::pdo()->prepare('UPDATE users SET password = ? WHERE id = ? AND password = ?');
                $update->execute([$hash, $user['id'], $user['password']]);
                if ($update->rowCount() !== 1) {
                    redirect('/login');
                }
                $user['password'] = $hash;
                Database::pdo()->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?')
                    ->execute([$user['id']]);
            }
            Auth::login($user, $remember);
            redirect('/tasks');
        }

        echo View::render('auth/login', ['title' => 'To Do Login Page', 'error' => $error]);
    }

    public function logout(): void
    {
        Csrf::require();
        Auth::logout();
        redirect('/login');
    }

    public function registerForm(): void
    {
        if (Auth::user() !== null) {
            redirect('/tasks');
        }

        echo View::render('auth/register', [
            'title'               => 'Register',
            'registrationEnabled' => $this->registrationEnabled(),
            'error'               => null,
            'success'             => null,
            'old'                 => [],
        ]);
    }

    public function register(): void
    {
        if (Auth::user() !== null) {
            redirect('/tasks');
        }

        if (!$this->registrationEnabled()) {
            redirect('/register');
        }

        Csrf::require();

        // Honeypot: bots fill every field.
        if (!empty($_POST['faxNumber'])) {
            exit('No bots allowed!');
        }

        $name = input_string('name');
        $username = input_string('username');
        $email = input_string('email');
        $timezone = input_string('timezone');
        $password = input_password('password');
        $verifyPassword = input_password('verifyPassword');

        $pdo = Database::pdo();
        $error = null;
        $success = null;

        if ($name === '' || $username === '' || $email === '' || $timezone === '') {
            $error = 'All fields are required.';
        } elseif (!within_character_limit($name, 255) || !within_character_limit($username, 190) || !within_character_limit($email, 190)) {
            $error = 'Name must be at most 255 characters; username and email at most 190 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!valid_timezone($timezone)) {
            $error = 'Please choose a valid timezone.';
        } elseif ($password !== $verifyPassword) {
            $error = 'The passwords do not match. Please try again.';
        } elseif (strlen($password) > 72) {
            $error = 'Password must not exceed 72 bytes.';
        } elseif (!password_meets_policy($password)) {
            $error = 'Password must be at least 8 characters long, at most 72 bytes, and include an uppercase letter, '
                . 'a lowercase letter, a number, and a non-alphanumeric character (spaces count).';
        } else {
            $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
            $check->execute([$username, $email]);
            if ((int) $check->fetchColumn() > 0) {
                $error = 'Username or Email already exists.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO users (name, username, email, password, role, timezone)
                     VALUES (?, ?, ?, ?, 'user', ?)"
                );
                $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $timezone]);
                $sent = Verification::send((int) $pdo->lastInsertId());

                if ($sent) {
                    $success = 'Registration successful! Please check your email to verify your account.';
                } else {
                    $error = 'Registration completed, but the verification email could not be sent. '
                        . 'Please contact the site administrator.';
                }
            }
        }

        echo View::render('auth/register', [
            'title'               => 'Register',
            'registrationEnabled' => true,
            'error'               => $error,
            'success'             => $success,
            'old'                 => $error !== null ? old_strings($_POST) : [],
        ]);
    }

    public function verifyEmail(): void
    {
        $token = input_string('token', $_GET);

        if ($token === '') {
            $this->verificationResult(false, 'No verification token provided.');
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, verification_token_expiry FROM users
                               WHERE verification_token = ? AND email_verified = FALSE');
        $stmt->execute([hash('sha256', $token)]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->verificationResult(false, 'This verification link is invalid or has already been used.');
        }

        if ($user['verification_token_expiry'] !== null
            && strtotime((string) $user['verification_token_expiry']) < time()
        ) {
            echo View::render('auth/message', [
                'title'   => 'Email Verification',
                'success' => false,
                'message' => 'This verification link has expired.',
                'action'  => ['href' => url('/resend-verification?token=' . $token), 'label' => 'Send a new link'],
            ]);
            exit;
        }

        $pdo->prepare('UPDATE users SET email_verified = TRUE, verification_token = NULL,
                       verification_token_expiry = NULL WHERE id = ?')
            ->execute([$user['id']]);

        $this->verificationResult(true, 'Your email has been successfully verified. You can now log in.');
    }

    public function resendVerification(): void
    {
        $token = input_string('token', $_GET);
        if ($token === '') {
            echo View::render('auth/resend_verification', ['title' => 'Send a new verification link', 'message' => null]);
            return;
        }

        $stmt = Database::pdo()->prepare('SELECT id, email FROM users
            WHERE verification_token = ? AND email_verified = FALSE');
        $stmt->execute([hash('sha256', $token)]);
        $user = $stmt->fetch();
        Verification::request($user ? (int) $user['id'] : null, 'invalid-verification-link');
        $this->verificationResult(true, 'If the account needs verification, a new verification email has been sent.');
    }

    public function requestVerification(): void
    {
        Csrf::require();
        $identifier = input_string('identifier');
        $stmt = Database::pdo()->prepare('SELECT id, email, email_verified FROM users
            WHERE username = ? OR email = ?');
        $stmt->execute([$identifier, $identifier]);
        $matches = $stmt->fetchAll();
        // Ambiguous username/email matches must not send to the wrong account.
        $user = count($matches) === 1 ? $matches[0] : null;
        Verification::request($user ? (int) $user['id'] : null, $identifier);
        echo View::render('auth/resend_verification', [
            'title' => 'Send a new verification link',
            'message' => 'If the account needs verification, a new verification email has been sent.',
        ]);
    }

    public function forgotPasswordForm(): void
    {
        echo View::render('auth/forgot_password', ['title' => 'Forgot Password', 'message' => null, 'isError' => false]);
    }

    public function forgotPassword(): void
    {
        Csrf::require();

        $email = input_string('email');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $allowed = RateLimiter::record('reset', $user ? $user['email'] : $email);

        if ($allowed && filter_var($email, FILTER_VALIDATE_EMAIL)) {

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $pdo->prepare('UPDATE users SET reset_token = ?,
                               reset_token_expiry = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?')
                    ->execute([hash('sha256', $token), $user['id']]);

                $link = url('/reset-password?token=' . $token);
                Mailer::send(
                    $user['email'],
                    $user['name'],
                    'Password Reset Request',
                    "Hello,\n\nYou have requested to reset your password. Please click on the link below "
                    . "to reset your password:\n{$link}\n\nThe link is valid for 1 hour. If you did not "
                    . "request this, please ignore this email.\n\nThank you,\nThe To Do Team"
                );
            }
        }

        echo View::render('auth/forgot_password', [
            'title'   => 'Forgot Password',
            'message' => 'If your email is registered, you will receive a password reset link.',
            'isError' => false,
        ]);
    }

    public function resetPasswordForm(): void
    {
        $token = input_string('token', $_GET);

        if ($token === '' || $this->userForResetToken($token) === null) {
            $this->verificationResult(false, 'Invalid or expired reset token.');
        }

        echo View::render('auth/reset_password', ['title' => 'Reset Password', 'token' => $token, 'error' => null]);
    }

    public function resetPassword(): void
    {
        Csrf::require();

        $token = input_string('token');
        $password = input_password('password');
        $confirm = input_password('confirmPassword');

        $user = $token !== '' ? $this->userForResetToken($token) : null;

        if ($user === null) {
            $this->verificationResult(false, 'Invalid or expired reset token.');
        }

        $error = null;
        if ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) > 72) {
            $error = 'Password must not exceed 72 bytes.';
        } elseif (!password_meets_policy($password)) {
            $error = 'Password must be at least 8 characters long, at most 72 bytes, and include an uppercase letter, '
                . 'a lowercase letter, a number, and a non-alphanumeric character (spaces count).';
        }

        if ($error !== null) {
            echo View::render('auth/reset_password', ['title' => 'Reset Password', 'token' => $token, 'error' => $error]);

            return;
        }

        $pdo = Database::pdo();
        $pdo->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);

        RateLimiter::clearLogin((string) $user['username']);

        // Invalidate any remembered sessions on other devices.
        $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?')->execute([$user['id']]);

        flash('Password has been reset successfully. Please log in.');
        redirect('/login');
    }

    // ---------------------------------------------------------------------

    private function registrationEnabled(): bool
    {
        $stmt = Database::pdo()->prepare("SELECT value FROM settings WHERE name = 'user_registration'");
        $stmt->execute();

        return $stmt->fetchColumn() === '1';
    }

    /** @return array<string, mixed>|null */
    private function userForResetToken(string $token): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, username FROM users WHERE reset_token = ? AND reset_token_expiry > NOW()'
        );
        $stmt->execute([hash('sha256', $token)]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    private function verificationResult(bool $success, string $message): never
    {
        echo View::render('auth/message', [
            'title'   => 'To-Do App',
            'success' => $success,
            'message' => $message,
            'action'  => ['href' => url('/login'), 'label' => 'Go to Login'],
        ]);
        exit;
    }
}
