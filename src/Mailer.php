<?php

declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    /**
     * Send a plain-text email. Returns true on success; failures are logged,
     * never displayed.
     */
    public static function send(string $to, string $toName, string $subject, string $body): bool
    {
        $transport = (string) App::config('mail.transport', 'mail');

        if ($transport === 'log') {
            return self::sendToLog($to, $toName, $subject, $body);
        }

        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            if ($transport === 'smtp') {
                $mail->isSMTP();
                $mail->Host = (string) App::config('mail.smtp.host');
                $mail->Port = (int) App::config('mail.smtp.port', 587);
                $username = (string) App::config('mail.smtp.username', '');
                if ($username !== '') {
                    $mail->SMTPAuth = true;
                    $mail->Username = $username;
                    $mail->Password = (string) App::config('mail.smtp.password', '');
                }
                $encryption = (string) App::config('mail.smtp.encryption', 'tls');
                if ($encryption === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } elseif ($encryption === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                }
            } else {
                $mail->isMail();
            }

            $mail->setFrom(
                (string) App::config('mail.from'),
                (string) App::config('mail.from_name', 'To-Do App')
            );
            $mail->addAddress($to, $toName);
            $mail->Subject = $subject;
            $mail->Body = $body;

            return $mail->send();
        } catch (MailException $e) {
            error_log('[php-todo] Mail send failed to ' . $to . ': ' . $e->getMessage());

            return false;
        }
    }

    private static function sendToLog(string $to, string $toName, string $subject, string $body): bool
    {
        $path = (string) App::config('mail.log_path', APP_ROOT . '/mail.log');

        $entry = sprintf(
            "=== %s ===\nTo: %s <%s>\nSubject: %s\n\n%s\n\n",
            date('c'),
            $toName,
            $to,
            $subject,
            $body
        );

        return file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) !== false;
    }
}
