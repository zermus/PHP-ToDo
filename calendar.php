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

    // Fetch Tasks for Current Month for both individual and group tasks
    $currentMonth = isset($_GET['month']) ? $_GET['month'] : date('m');
    $currentYear = isset($_GET['year']) ? $_GET['year'] : date('Y');
    $firstDayOfMonth = new DateTime("$currentYear-$currentMonth-01", $userTimezone);
    $firstDayOfMonth->modify('first day of this month');
    $dayOfWeek = $firstDayOfMonth->format('w');
    $startDayOfWeek = clone $firstDayOfMonth;
    if ($dayOfWeek != 0) { // Adjust if the first day is not Sunday
        $startDayOfWeek->modify('-' . $dayOfWeek . ' days');
    }
    $lastDayOfMonth = clone $firstDayOfMonth;
    $lastDayOfMonth->modify('last day of this month');
    $endDayOfWeek = clone $lastDayOfMonth;
    if ($lastDayOfMonth->format('w') != 6) { // Adjust if the last day is not Saturday
        $endDayOfWeek->modify('+'.(6 - $lastDayOfMonth->format('w')).' days');
    }

    // Adjust the query to include tasks shared with groups
    $taskStmt = $pdo->prepare("
        SELECT t.* FROM tasks t
        LEFT JOIN group_memberships gm ON t.group_id = gm.group_id
        WHERE (t.user_id = ? OR gm.user_id = ?) AND t.due_date BETWEEN ? AND ?
        GROUP BY t.id
        ORDER BY t.due_date ASC
    ");
    $taskStmt->execute([
        $_SESSION['user_id'],
        $_SESSION['user_id'],
        $startDayOfWeek->format('Y-m-d'),
        $endDayOfWeek->format('Y-m-d')
    ]);
    $tasks = $taskStmt->fetchAll();

    // Calculate previous and next months and years
    $previousMonth = date('m', strtotime('-1 month', strtotime("$currentYear-$currentMonth-01")));
    $previousYear = date('Y', strtotime('-1 month', strtotime("$currentYear-$currentMonth-01")));
    $nextMonth = date('m', strtotime('+1 month', strtotime("$currentYear-$currentMonth-01")));
    $nextYear = date('Y', strtotime('+1 month', strtotime("$currentYear-$currentMonth-01")));

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
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
        <div class="month-navigation">
            <button onclick="location.href='?year=<?php echo $previousYear; ?>&month=<?php echo $previousMonth; ?>'" class="btn calendar-navigation-btn previous-month">P
revious</button>
            <span><?php echo date('F Y', strtotime("$currentYear-$currentMonth-01")); ?></span>
            <button onclick="location.href='?year=<?php echo $nextYear; ?>&month=<?php echo $nextMonth; ?>'" class="btn calendar-navigation-btn next-month">Next</button>
        </div>
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
                    $currentDate = clone $startDayOfWeek;
                    while ($currentDate <= $endDayOfWeek) {
                        // Check to avoid unnecessary row at the end
                        if ($currentDate > $lastDayOfMonth && $currentDate->format('w') == 0) {
                            break;
                        }
                        echo "<tr>";
                        for ($i = 0; $i < 7; $i++) {
                            echo "<td>";
                            echo "<div class='date-box'>";
                            if ($currentDate >= $firstDayOfMonth && $currentDate <= $lastDayOfMonth) {
                                echo "<div class='date'>" . $currentDate->format('j') . "</div>";
                                foreach ($tasks as $task) {
                                    $dueDate = new DateTime($task['due_date'], new DateTimeZone('UTC'));
                                    $dueDate->setTimezone($userTimezone);
                                    if ($dueDate->format('Y-m-d') === $currentDate->format('Y-m-d')) {
                                        $now = new DateTime("now", $userTimezone);
                                        $interval = $now->diff($dueDate);
                                        $taskClass = 'task-item-green'; // Default color for tasks
                                        if ($interval->invert == 1) {
                                            $taskClass = $task['completed'] ? 'task-completed' : 'task-past-due';
                                        } elseif ($interval->days == 0 && $interval->h < 3) {
                                            $taskClass = 'task-soon';
                                        } elseif ($interval->days == 0) {
                                            $taskClass = 'task-today';
                                        }
                                        echo "<div class='task $taskClass'><a href='edit_task.php?id=" . $task['id'] . "' class='task-name'>" . htmlspecialchars($task['s
ummary']) . "</a></div>";
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
        <a href="main.php" class="btn">Tasks</a>
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
