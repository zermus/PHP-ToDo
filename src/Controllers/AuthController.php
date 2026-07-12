<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Mailer;
use App\View;

final class AuthController
{
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
        $password = (string) ($_POST['password'] ?? '');
        $remember = !empty($_POST['rememberMe']);

        $error = null;

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $error = 'Invalid username or password.';
        } elseif (!$user['email_verified']) {
            $error = 'Please verify your email address before logging in.';
        } else {
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
        $password = (string) ($_POST['password'] ?? '');
        $verifyPassword = (string) ($_POST['verifyPassword'] ?? '');

        $pdo = Database::pdo();
        $error = null;
        $success = null;

        if ($name === '' || $username === '' || $email === '' || $timezone === '') {
            $error = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!valid_timezone($timezone)) {
            $error = 'Please choose a valid timezone.';
        } elseif ($password !== $verifyPassword) {
            $error = 'The passwords do not match. Please try again.';
        } elseif (!password_meets_policy($password)) {
            $error = 'Password must be at least 8 characters long and include at least one uppercase letter, '
                . 'one lowercase letter, one number, and one special character.';
        } else {
            $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
            $check->execute([$username, $email]);
            if ((int) $check->fetchColumn() > 0) {
                $error = 'Username or Email already exists.';
            } else {
                $token = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare(
                    "INSERT INTO users (name, username, email, password, role, verification_token,
                                        verification_token_expiry, timezone)
                     VALUES (?, ?, ?, ?, 'user', ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), ?)"
                );
                $stmt->execute([
                    $name, $username, $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $token, $timezone,
                ]);

                $link = url('/verify?token=' . $token);
                $sent = Mailer::send(
                    $email,
                    $name,
                    'Verify Your Email',
                    "Hello {$name},\n\nPlease click the following link to verify your email and activate "
                    . "your account:\n{$link}\n\nThe link is valid for 24 hours.\n\nThank you!"
                );

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
            'old'                 => $error !== null ? $_POST : [],
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
        $stmt->execute([$token]);
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

        if ($token !== '') {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT id, name, email FROM users
                                   WHERE verification_token = ? AND email_verified = FALSE');
            $stmt->execute([$token]);
            $user = $stmt->fetch();

            if ($user) {
                $newToken = bin2hex(random_bytes(32));
                $pdo->prepare('UPDATE users SET verification_token = ?,
                               verification_token_expiry = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ?')
                    ->execute([$newToken, $user['id']]);

                $link = url('/verify?token=' . $newToken);
                Mailer::send(
                    $user['email'],
                    $user['name'],
                    'Verify Your Email',
                    "Hello {$user['name']},\n\nHere is your new verification link:\n{$link}\n\n"
                    . "The link is valid for 24 hours.\n\nThank you!"
                );
            }
        }

        // Same response either way — don't leak token validity.
        $this->verificationResult(true, 'If the link was valid, a new verification email has been sent.');
    }

    public function forgotPasswordForm(): void
    {
        echo View::render('auth/forgot_password', ['title' => 'Forgot Password', 'message' => null, 'isError' => false]);
    }

    public function forgotPassword(): void
    {
        Csrf::require();

        // Session-based rate limiting: 3 attempts per 15 minutes.
        if (!isset($_SESSION['reset_attempts']) || ($_SESSION['reset_timestamp'] ?? 0) + 900 < time()) {
            $_SESSION['reset_attempts'] = 0;
            $_SESSION['reset_timestamp'] = time();
        }

        if ($_SESSION['reset_attempts'] >= 3) {
            echo View::render('auth/forgot_password', [
                'title'   => 'Forgot Password',
                'message' => 'Too many requests. Please try again later.',
                'isError' => true,
            ]);

            return;
        }

        $_SESSION['reset_attempts']++;

        $email = input_string('email');

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $pdo->prepare('UPDATE users SET reset_token = ?,
                               reset_token_expiry = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?')
                    ->execute([$token, $user['id']]);

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
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirmPassword'] ?? '');

        $user = $token !== '' ? $this->userForResetToken($token) : null;

        if ($user === null) {
            $this->verificationResult(false, 'Invalid or expired reset token.');
        }

        $error = null;
        if ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (!password_meets_policy($password)) {
            $error = 'Password must be at least 8 characters long and include at least one uppercase letter, '
                . 'one lowercase letter, one number, and one special character.';
        }

        if ($error !== null) {
            echo View::render('auth/reset_password', ['title' => 'Reset Password', 'token' => $token, 'error' => $error]);

            return;
        }

        $pdo = Database::pdo();
        $pdo->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);

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
            'SELECT id FROM users WHERE reset_token = ? AND reset_token_expiry > NOW()'
        );
        $stmt->execute([$token]);
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
