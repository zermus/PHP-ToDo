<?php

declare(strict_types=1);

/**
 * Reminder + completion-email cron. Run every minute:
 *   * * * * * php /path/to/php-todo/bin/send_reminders.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;
use App\Mailer;

$pdo = Database::pdo();

// --- Due-date reminders ---------------------------------------------------
// All comparisons in UTC (due_date is stored in UTC); each recipient's email
// formats the due date in their own timezone.

$stmt = $pdo->query(
    "SELECT t.id, t.user_id, t.group_id, t.summary, t.due_date,
            u.email AS owner_email, u.name AS owner_name, u.timezone AS owner_timezone
     FROM tasks t
     INNER JOIN users u ON t.user_id = u.id
     WHERE t.reminder_sent = 0
       AND t.completed = 0
       AND t.reminder_preference IS NOT NULL
       AND t.due_date > UTC_TIMESTAMP()
       AND TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), t.due_date) <= CASE t.reminder_preference
            WHEN '15m' THEN 15
            WHEN '30m' THEN 30
            WHEN '1h'  THEN 60
            WHEN '2h'  THEN 120
            WHEN '4h'  THEN 240
            WHEN '12h' THEN 720
            WHEN '24h' THEN 1440
        END"
);

$memberStmt = $pdo->prepare(
    'SELECT u.email, u.name, u.timezone FROM users u
     INNER JOIN group_memberships gm ON gm.user_id = u.id
     WHERE gm.group_id = ?'
);

foreach ($stmt->fetchAll() as $task) {
    // Recipients: group members plus the owner, deduplicated by email.
    $recipients = [];
    if (!empty($task['group_id'])) {
        $memberStmt->execute([(int) $task['group_id']]);
        foreach ($memberStmt->fetchAll() as $member) {
            $recipients[strtolower($member['email'])] = $member;
        }
    }
    $recipients[strtolower($task['owner_email'])] = [
        'email'    => $task['owner_email'],
        'name'     => $task['owner_name'],
        'timezone' => $task['owner_timezone'],
    ];

    $sentAny = false;
    foreach ($recipients as $recipient) {
        try {
            $timezone = new DateTimeZone($recipient['timezone'] ?: 'UTC');
        } catch (Exception) {
            $timezone = new DateTimeZone('UTC');
        }

        $dueDate = new DateTime($task['due_date'], new DateTimeZone('UTC'));
        $dueDate->setTimezone($timezone);

        $sent = Mailer::send(
            $recipient['email'],
            $recipient['name'],
            'Task Reminder',
            "Hello {$recipient['name']},\n\nThis is a reminder for your task: {$task['summary']}, "
            . 'which is due on ' . $dueDate->format('Y-m-d h:i A') . '.'
        );
        $sentAny = $sentAny || $sent;
    }

    if ($sentAny) {
        $pdo->prepare('UPDATE tasks SET reminder_sent = 1 WHERE id = ?')
            ->execute([(int) $task['id']]);
    }
}

// --- Completion notifications ----------------------------------------------

$completionStmt = $pdo->query(
    'SELECT t.id, t.summary, u.email, u.name
     FROM tasks t
     INNER JOIN users u ON t.user_id = u.id
     WHERE t.completed = 1 AND t.receive_completion_email = 1 AND t.completion_email_sent = 0'
);

foreach ($completionStmt->fetchAll() as $task) {
    $sent = Mailer::send(
        $task['email'],
        $task['name'],
        'Task Completion Notification',
        "Hello {$task['name']},\n\nThis is a notification that your task '{$task['summary']}' "
        . 'has been marked as completed.'
    );

    if ($sent) {
        $pdo->prepare('UPDATE tasks SET completion_email_sent = 1 WHERE id = ?')
            ->execute([(int) $task['id']]);
    }
}
