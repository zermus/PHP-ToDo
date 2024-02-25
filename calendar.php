<?php
session_start();
require 'config.php';

// User Authentication
if (!isset($_SESSION['user_id']) && !isset($_COOKIE['rememberMe'])) {
    header('Location: login.php');
    exit();
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch User Details
    $userStmt = $pdo->prepare("SELECT name, role, timezone FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userDetails = $userStmt->fetch();
    $userTimezone = new DateTimeZone($userDetails['timezone'] ?? 'UTC');

    // Fetch Tasks for Current Month
    $currentMonth = isset($_GET['month']) ? $_GET['month'] : date('m');
    $currentYear = date('Y');
    $firstDayOfMonth = new DateTime("$currentYear-$currentMonth-01", $userTimezone);
    $firstDayOfMonth->modify('first day of this month');
    $startDayOfWeek = clone $firstDayOfMonth;
    $startDayOfWeek->modify('last sunday');
    $lastDayOfMonth = clone $firstDayOfMonth;
    $lastDayOfMonth->modify('last day of this month');
    $endDayOfWeek = clone $lastDayOfMonth;
    $endDayOfWeek->modify('next saturday');

    $taskStmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? AND due_date >= ? AND due_date <= ?");
    $taskStmt->execute([$_SESSION['user_id'], $startDayOfWeek->format('Y-m-d'), $endDayOfWeek->format('Y-m-d')]);
    $tasks = $taskStmt->fetchAll();

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Implement Navigation Logic
$previousMonth = date('m', strtotime('-1 month', strtotime("$currentYear-$currentMonth-01")));
$nextMonth = date('m', strtotime('+1 month', strtotime("$currentYear-$currentMonth-01")));

// HTML Structure for Calendar Page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>To Do Calendar</title>
    <link rel="stylesheet" href="stylesheet.css">
</head>
<body>
    <div class="calendar-container">
        <h1>Calendar</h1>
        <!-- Month Navigation Buttons -->
        <div class="month-navigation">
            <button onclick="location.href='?month=<?php echo $previousMonth; ?>'" class="btn calendar-navigation-btn prev-month">Previous</button>
            <span><?php echo date('F Y', strtotime("$currentYear-$currentMonth-01")); ?></span>
            <button onclick="location.href='?month=<?php echo $nextMonth; ?>'" class="btn calendar-navigation-btn next-month">Next</button>
        </div>
        <!-- Calendar Display -->
        <div class="calendar">
            <table>
                <thead>
                    <tr>
                        <th>Sun</th>
                        <th>Mon</th>
                        <th>Tue</th>
                        <th>Wed</th>
                        <th>Thu</th>
                        <th>Fri</th>
                        <th>Sat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Loop through weeks
                    $currentDate = clone $startDayOfWeek;
                    while ($currentDate <= $endDayOfWeek) {
                        echo "<tr>";
                        // Loop through days of the week
                        for ($i = 0; $i < 7; $i++) {
                            echo "<td>";
                            // Display the day number
                            echo "<div class='date-box'>";
                            if ($currentDate >= $firstDayOfMonth && $currentDate <= $lastDayOfMonth) {
                                echo "<div class='date'>" . $currentDate->format('j') . "</div>";
                                // Display tasks for the current date
                                foreach ($tasks as $task) {
                                    $dueDate = new DateTime($task['due_date'], new DateTimeZone('UTC'));
                                    $dueDate->setTimezone($userTimezone);
                                    if ($dueDate->format('Y-m-d') === $currentDate->format('Y-m-d')) {
                                        $now = new DateTime("now", $userTimezone);
                                        $interval = $now->diff($dueDate);
                                        $taskClass = 'task-item-green'; // Default color for tasks
                                        if ($interval->invert == 1) {
                                            $taskClass = 'task-past-due';
                                        } elseif ($interval->days == 0 && $interval->h < 3) {
                                            $taskClass = 'task-soon';
                                        } elseif ($interval->days == 0) {
                                            $taskClass = 'task-today';
                                        }
                                        $taskStyle = $task['completed'] ? 'completed' : '';
                                        echo "<div class='task $taskClass $taskStyle'><a href='edit_task.php?id=" . $task['id'] . "' class='task-name'>" . htmlspecialchars($task['summary']) . "</a></div>";
                                    }
                                }
                            }
                            echo "</div>";
                            echo "</td>";
                            $currentDate->modify('+1 day');
                        }
                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <a href="main.php" class="btn">Tasks</a> <!-- Link to go back to main.php -->
    </div>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            var rowCount = document.querySelector('.calendar table tbody').getElementsByTagName('tr').length;
            if (rowCount > 5) {
                document.querySelector('.calendar-container').style.paddingBottom = '30px';
            }
        });
    </script>
</body>
</html>
