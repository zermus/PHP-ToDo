<?php
session_start();
require 'config.php';

$pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!isset($_SESSION['user_id']) && !isset($_COOKIE['rememberMe'])) {
    header('Location: login.php');
    exit();
} elseif (!isset($_SESSION['user_id']) && isset($_COOKIE['rememberMe'])) {
    $rememberToken = $_COOKIE['rememberMe'];
    $userStmt = $pdo->prepare("SELECT id, name, role, timezone, urgency_green, urgency_critical FROM users WHERE remember_token = ?"
);
    $userStmt->execute([$rememberToken]);
    $userDetails = $userStmt->fetch();

    if ($userDetails) {
        $_SESSION['user_id'] = $userDetails['id'];
        $_SESSION['username'] = $userDetails['name'];
        $_SESSION['timezone'] = $userDetails['timezone'];
        $_SESSION['urgency_green'] = $userDetails['urgency_green'];
        $_SESSION['urgency_critical'] = $userDetails['urgency_critical'];
    } else {
        header('Location: login.php');
        exit();
    }
} else {
    $userId = $_SESSION['user_id'];
    $userStmt = $pdo->prepare("SELECT name, role, timezone, urgency_green, urgency_critical FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $userDetails = $userStmt->fetch();
}

$userTimezone = new DateTimeZone($userDetails['timezone'] ?? 'UTC');
$nowInUserTimezone = new DateTime('now', $userTimezone); // Define $nowInUserTimezone here
$urgencyGreen = $userDetails['urgency_green'];
$urgencyCritical = $userDetails['urgency_critical'];

$currentMonth = isset($_GET['month']) ? $_GET['month'] : date('m');
$currentYear = isset($_GET['year']) ? $_GET['year'] : date('Y');
$firstDayOfMonth = new DateTime("$currentYear-$currentMonth-01", $userTimezone);
$firstDayOfMonth->modify('first day of this month');
$dayOfWeek = $firstDayOfMonth->format('w');
$startDayOfWeek = clone $firstDayOfMonth;
if ($dayOfWeek != 0) {
    $startDayOfWeek->modify('-' . $dayOfWeek . ' days');
}
$lastDayOfMonth = clone $firstDayOfMonth;
$lastDayOfMonth->modify('last day of this month');
$endDayOfWeek = clone $lastDayOfMonth;
if ($lastDayOfMonth->format('w') != 6) {
    $endDayOfWeek->modify('+'.(6 - $lastDayOfMonth->format('w')).' days');
}

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

$previousMonth = date('m', strtotime('-1 month', strtotime("$currentYear-$currentMonth-01")));
$previousYear = date('Y', strtotime('-1 month', strtotime("$currentYear-$currentMonth-01")));
$nextMonth = date('m', strtotime('+1 month', strtotime("$currentYear-$currentMonth-01")));
$nextYear = date('Y', strtotime('+1 month', strtotime("$currentYear-$currentMonth-01")));
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
            <button onclick="location.href='?year=<?php echo $previousYear; ?>&month=<?php echo $previousMonth; ?>'" class="btn cale
ndar-navigation-btn previous-month">Previous</button>
            <span><?php echo date('F Y', strtotime("$currentYear-$currentMonth-01")); ?></span>
            <button onclick="location.href='?year=<?php echo $nextYear; ?>&month=<?php echo $nextMonth; ?>'" class="btn calendar-nav
igation-btn next-month">Next</button>
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
                        if ($currentDate > $lastDayOfMonth && $currentDate->format('w') == 0) {
                            break;
                        }
                        echo "<tr>";
                        for ($i = 0; $i < 7; $i++) {
                            echo "<td>";
                            echo "<div class='date-box'>";
                            if ($currentDate >= $firstDayOfMonth && $currentDate <= $lastDayOfMonth) {
                                $isToday = $currentDate->format('Y-m-d') === $nowInUserTimezone->format('Y-m-d');
                                $dateClass = $isToday ? "date current-day" : "date";
                                echo "<div class='{$dateClass}'>" . $currentDate->format('j') . "</div>";
                            }
                            echo "<div class='tasks' style='margin-top: 20px;'>";
                            foreach ($tasks as $task) {
                                $dueDate = new DateTime($task['due_date'], new DateTimeZone('UTC'));
                                $dueDate->setTimezone($userTimezone);
                                if ($dueDate->format('Y-m-d') === $currentDate->format('Y-m-d')) {
                                    $interval = $nowInUserTimezone->diff($dueDate);
                                    $minutesToDue = $interval->days * 1440 + $interval->h * 60 + $interval->i;
                                    $taskClass = '';
                                    if ($task['completed']) {
                                        $taskClass .= 'task-completed';
                                    } elseif ($interval->invert == 1) {
                                        $taskClass .= 'task-past-due';
                                    } elseif ($minutesToDue <= $urgencyCritical) {
                                        $taskClass .= 'task-urgency-critical';
                                    } elseif ($minutesToDue <= $urgencyGreen) {
                                        $taskClass .= 'task-urgency-soon';
                                    } else {
                                        $taskClass .= 'task-urgency-green';
                                    }
                                    echo "<div class='{$taskClass}'><a href='edit_task.php?id={$task['id']}' class='task-name'>" . h
tmlspecialchars($task['summary']) . "</a></div>";
                                }
                            }
                            echo "</div>";
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
</body>
</html>
