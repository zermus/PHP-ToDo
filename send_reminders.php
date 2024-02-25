<?php
require 'config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch tasks that are due for a reminder
    $stmt = $pdo->query("SELECT t.id, t.user_id, t.summary, t.due_date, t.reminder_preference, u.email, u.name, u.timezone FROM tasks t INNER JOIN users u ON t.user_id = u.id WHERE t.reminder_sent = 0");

    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tasks as $task) {
        $userTimezone = new DateTimeZone($task['timezone'] ?? 'UTC');
        $nowInUserTimezone = new DateTime("now", $userTimezone);
        $dueDate = new DateTime($task['due_date'], $userTimezone);

        // Determine if it's time to send the reminder based on the reminder preference and due date
        $sendReminder = false;
        $interval = $nowInUserTimezone->diff($dueDate);

        switch ($task['reminder_preference']) {
            case '15m':
                if ($interval->i == 15 && $interval->h == 0 && $interval->days == 0) $sendReminder = true;
                break;
            case '30m':
                if ($interval->i == 30 && $interval->h == 0 && $interval->days == 0) $sendReminder = true;
                break;
            case '1h':
                if ($interval->h == 1 && $interval->days == 0) $sendReminder = true;
                break;
            case '2h':
                if ($interval->h == 2 && $interval->days == 0) $sendReminder = true;
                break;
            case '4h':
                if ($interval->h == 4 && $interval->days == 0) $sendReminder = true;
                break;
            case '12h':
                if ($interval->h == 12 && $interval->days == 0) $sendReminder = true;
                break;
            case '24h':
                if ($interval->days == 1) $sendReminder = true;
                break;
        }

        if ($sendReminder) {
            $formattedDueDate = $dueDate->format('Y-m-d h:i A'); // Format date to include AM/PM

            // Send email reminder
            $to = $task['email'];
            $subject = "Task Reminder";
            $message = "Hello " . $task['name'] . ",\n\nThis is a reminder for your task: " . $task['summary'] . ", which is due on " . $formattedDueDate . ".";
            $headers = "From: " . $from_email;

            if (mail($to, $subject, $message, $headers)) {
                // Optionally update the task to indicate the reminder has been sent
                $updateStmt = $pdo->prepare("UPDATE tasks SET reminder_sent = 1 WHERE id = ?");
                $updateStmt->execute([$task['id']]);
            }
        }
    }
} catch (PDOException $e) {
    // Handle error
}
?>
