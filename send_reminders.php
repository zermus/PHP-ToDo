<?php
require 'config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $reminderStmt = $pdo->query("
        SELECT t.id, t.user_id, t.group_id, t.summary, t.due_date, t.reminder_preference, t.last_notification_sent, u.email, u.name, u.timezone
        FROM tasks t
        INNER JOIN users u ON t.user_id = u.id
        WHERE t.reminder_sent = 0
    ");

    $tasks = $reminderStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tasks as $task) {
        $userEmails = [];
        if ($task['group_id'] && $task['group_id'] != 0) {
            $groupMembersStmt = $pdo->prepare("SELECT email, name, timezone FROM users WHERE id IN (SELECT user_id FROM group_memberships WHERE group_id = ?)");
            $groupMembersStmt->execute([$task['group_id']]);
            while ($member = $groupMembersStmt->fetch(PDO::FETCH_ASSOC)) {
                $userEmails[$member['email']] = $member;
            }
        }
        // Ensure the task owner is also added but not duplicated
        $userEmails[$task['email']] = ['email' => $task['email'], 'name' => $task['name'], 'timezone' => $task['timezone']];

        foreach ($userEmails as $user) {
            $userTimezone = new DateTimeZone($user['timezone'] ?? 'UTC');
            $nowInUserTimezone = new DateTime("now", $userTimezone);
            $dueDate = new DateTime($task['due_date'], new DateTimeZone('UTC'));
            $dueDate->setTimezone($userTimezone);

            if (shouldSendReminder($task, $nowInUserTimezone, $dueDate)) {
                $formattedDueDate = $dueDate->format('Y-m-d h:i A');
                $to = $user['email'];
                $subject = "Task Reminder";
                $message = "Hello " . $user['name'] . ",\n\nThis is a reminder for your task: " . $task['summary'] . ", which is due on " . $formattedDueDate . ".";
                $headers = "From: " . $from_email;

                if (mail($to, $subject, $message, $headers)) {
                    $updateStmt = $pdo->prepare("UPDATE tasks SET reminder_sent = 1, last_notification_sent = NOW() WHERE id = ?");
                    $updateStmt->execute([$task['id']]);
                }
            }
        }
    }

    $completionStmt = $pdo->prepare("
        SELECT t.id, t.user_id, t.summary, u.email, u.name
        FROM tasks t
        JOIN users u ON t.user_id = u.id
        WHERE t.completed = 1 AND t.receive_completion_email = 1 AND t.completion_email_sent = 0
    ");
    $completionStmt->execute();
    $completedTasks = $completionStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($completedTasks as $task) {
        $to = $task['email'];
        $subject = "Task Completion Notification";
        $message = "Hello " . $task['name'] . ",\n\nThis is a notification that your task '" . $task['summary'] . "' has been marked as completed.";
        $headers = "From: " . $from_email;

        if (mail($to, $subject, $message, $headers)) {
            $updateStmt = $pdo->prepare("UPDATE tasks SET completion_email_sent = 1 WHERE id = ?");
            $updateStmt->execute([$task['id']]);
        }
    }
} catch (PDOException $e) {
    // Handle error
}

function shouldSendReminder($task, $nowInUserTimezone, $dueDate) {
    $reminderTime = clone $dueDate;
    switch ($task['reminder_preference']) {
        case '15m':
            $reminderTime->sub(new DateInterval('PT15M'));
            break;
        case '30m':
            $reminderTime->sub(new DateInterval('PT30M'));
            break;
        case '1h':
            $reminderTime->sub(new DateInterval('PT1H'));
            break;
        case '2h':
            $reminderTime->sub(new DateInterval('PT2H'));
            break;
        case '4h':
            $reminderTime->sub(new DateInterval('PT4H'));
            break;
        case '12h':
            $reminderTime->sub(new DateInterval('PT12H'));
            break;
        case '24h':
            $reminderTime->sub(new DateInterval('P1D'));
            break;
    }

    $sendReminder = ($nowInUserTimezone >= $reminderTime) && ($nowInUserTimezone < $dueDate);

    $lastSent = $task['last_notification_sent'] ? new DateTime($task['last_notification_sent']) : null;
    if ($sendReminder && $lastSent) {
        $timeSinceLastSent = $lastSent->diff($nowInUserTimezone);
        if ($timeSinceLastSent->h < 1 && $timeSinceLastSent->days < 1) {
            $sendReminder = false;
        }
    }

    return $sendReminder;
}
?>
