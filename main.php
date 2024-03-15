<?php
session_start();
require 'config.php';

// Initialize PDO instance at the beginning
$pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// User Authentication
if (!isset($_SESSION['user_id']) && !isset($_COOKIE['rememberMe'])) {
    header('Location: login.php');
    exit();
} elseif (!isset($_SESSION['user_id']) && isset($_COOKIE['rememberMe'])) {
    // Fetch user details using the remember token from the cookie
    $rememberToken = $_COOKIE['rememberMe'];
    $userStmt = $pdo->prepare("SELECT id, name, role, timezone FROM users WHERE remember_token = ?");
    $userStmt->execute([$rememberToken]);
    $userDetails = $userStmt->fetch();

    if ($userDetails) {
        // Set session variables if user is found
        $_SESSION['user_id'] = $userDetails['id'];
        $_SESSION['username'] = $userDetails['name']; // Assuming you want to store the name in the session
        // Re-fetch user details to avoid issues if user details were not properly set in session
        $userStmt = $pdo->prepare("SELECT name, role, timezone FROM users WHERE id = ?");
        $userStmt->execute([$_SESSION['user_id']]);
        $userDetails = $userStmt->fetch();
    } else {
        // If no user found with the token, redirect to login
        header('Location: login.php');
        exit();
    }
} else {
    // Fetch user details from the database using the session user_id
    $userStmt = $pdo->prepare("SELECT name, role, timezone FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userDetails = $userStmt->fetch();
}

$userTimezone = new DateTimeZone($userDetails['timezone'] ?? 'UTC');
$isAdmin = $userDetails['role'] === 'admin' || $userDetails['role'] === 'super_admin';

// Initialize an array to track task IDs assigned to groups
$groupTaskIds = [];

// Fetch group tasks
$groupTasksStmt = $pdo->prepare("SELECT t.*, g.name as group_name FROM tasks t INNER JOIN group_memberships gm ON t.group_id = gm.group_
id INNER JOIN user_groups g ON gm.group_id = g.id WHERE gm.user_id = ? AND t.completed = FALSE ORDER BY t.due_date ASC");
$groupTasksStmt->execute([$_SESSION['user_id']]);
$groupTasks = $groupTasksStmt->fetchAll();

$groupTasksWithChecklist = [];
foreach ($groupTasks as $task) {
    $checklistStmt = $pdo->prepare("SELECT * FROM checklist_items WHERE task_id = ?");
    $checklistStmt->execute([$task['id']]);
    $checklistItems = $checklistStmt->fetchAll();
    $task['checklist_items'] = $checklistItems;
    $groupTasksWithChecklist[$task['group_name']][] = $task;
    $groupTaskIds[] = $task['id'];
}

// Fetch personal tasks excluding those assigned to groups
$tasksWithChecklist = [];
$placeholders = implode(',', array_fill(0, count($groupTaskIds), '?'));
$query = "SELECT * FROM tasks WHERE user_id = ? AND completed = FALSE " . (!empty($groupTaskIds) ? "AND id NOT IN ($placeholders) " : ""
) . "ORDER BY due_date ASC";
$params = array_merge([$_SESSION['user_id']], $groupTaskIds);
$taskStmt = $pdo->prepare($query);
$taskStmt->execute($params);
$personalTasks = $taskStmt->fetchAll();

foreach ($personalTasks as $task) {
    $checklistStmt = $pdo->prepare("SELECT * FROM checklist_items WHERE task_id = ?");
    $checklistStmt->execute([$task['id']]);
    $checklistItems = $checklistStmt->fetchAll();
    $task['checklist_items'] = $checklistItems;
    $tasksWithChecklist[] = $task;
}

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    setcookie('rememberMe', '', time() - 3600, '/');
    header('Location: login.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>To Do Main Page</title>
    <link rel="stylesheet" href="stylesheet.css">
</head>
<body>
    <div class="container">
        <h1>Welcome, <?php echo htmlspecialchars($userDetails['name']); ?>!</h1>
        <div class="button-container">
            <a href="create_task.php" class="btn new-task">Create New Task</a>
            <a href="user_settings.php" class="btn">User Settings</a>
            <?php if ($isAdmin): ?>
            <a href="manage_users.php" class="btn manage-users">Manage Users</a>
            <?php endif; ?>
        </div>

        <!-- Personal Tasks -->
        <div class="task-container">
            <h2>Your Tasks</h2>
            <?php if (empty($tasksWithChecklist)): ?>
            <p>No tasks available.</p>
            <?php else: ?>
            <ul class="task-list">
                <?php foreach ($tasksWithChecklist as $task): ?>
                <?php
                    $dueDateTime = new DateTime($task['due_date'], new DateTimeZone('UTC'));
                    $dueDateTime->setTimezone($userTimezone);
                    $now = new DateTime("now", $userTimezone);
                    $interval = $now->diff($dueDateTime);
                    $taskClass = $task['completed'] ? 'task-item completed' : 'task-item';
                    if ($interval->invert == 1) {
                        $taskClass .= ' task-past-due';
                    } elseif ($interval->days == 0 && $interval->h < 3) {
                        $taskClass .= ' task-soon';
                    } elseif ($interval->days == 0) {
                        $taskClass .= ' task-today';
                    } else {
                        $taskClass .= ' task-item-green';
                    }
                ?>
                <li id="task-<?php echo $task['id']; ?>" class="<?php echo $taskClass; ?>">
                    <?php echo htmlspecialchars($task['summary']) . " - Due: " . $dueDateTime->format('Y-m-d h:i A'); ?>
                    <a href="edit_task.php?id=<?php echo $task['id']; ?>" class="edit-link">Edit</a>
                    <button type="button" class="complete-task" data-task-id="<?php echo $task['id']; ?>" onclick="toggleTaskCompletion(
this, <?php echo $task['id']; ?>)">
                        <?php echo $task['completed'] ? 'Uncomplete Task' : 'Complete Task'; ?>
                    </button>
                    <?php if (!empty($task['checklist_items'])): ?>
                    <ul>
                        <?php foreach ($task['checklist_items'] as $item): ?>
                        <li id="item-<?php echo $item['id']; ?>" class="<?php echo $item['completed'] ? 'completed' : ''; ?>">
                            <?php echo htmlspecialchars($item['content']); ?>
                            <button type="button" class="complete-checklist-item" data-item-id="<?php echo $item['id']; ?>" data-task-id
="<?php echo $task['id']; ?>">
                                <?php echo $item['completed'] ? 'Uncomplete' : 'Complete'; ?>
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <!-- Group Tasks -->
        <div class="task-container">
            <?php foreach ($groupTasksWithChecklist as $groupName => $tasks): ?>
            <h2><?php echo htmlspecialchars($groupName); ?>'s Tasks</h2>
            <?php if (empty($tasks)): ?>
            <p>No tasks available.</p>
            <?php else: ?>
            <ul class="task-list">
                <?php foreach ($tasks as $task): ?>
                <?php
                    $dueDateTime = new DateTime($task['due_date'], new DateTimeZone('UTC'));
                    $dueDateTime->setTimezone($userTimezone);
                    $taskClass = $task['completed'] ? 'task-item completed' : 'task-item';
                    $now = new DateTime("now", $userTimezone);
                    $interval = $now->diff($dueDateTime);
                    if ($interval->invert == 1) {
                        $taskClass .= ' task-past-due';
                    } elseif ($interval->days == 0 && $interval->h < 3) {
                        $taskClass .= ' task-soon';
                    } elseif ($interval->days == 0) {
                        $taskClass .= ' task-today';
                    } else {
                        $taskClass .= ' task-item-green';
                    }
                ?>
                <li id="task-<?php echo $task['id']; ?>" class="<?php echo $taskClass; ?>">
                    <?php echo htmlspecialchars($task['summary']) . " - Due: " . $dueDateTime->format('Y-m-d h:i A'); ?>
                    <a href="edit_task.php?id=<?php echo $task['id']; ?>" class="edit-link">Edit</a>
                    <button type="button" class="complete-task" data-task-id="<?php echo $task['id']; ?>" onclick="toggleTaskCompletion(
this, <?php echo $task['id']; ?>)">
                        <?php echo $task['completed'] ? 'Uncomplete Task' : 'Complete Task'; ?>
                    </button>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="logout-container" style="margin-top: 20px;">
            <a href="calendar.php" class="btn">Calendar</a>
            <a href="?logout" class="btn">Logout</a>
        </div>
    </div>
    <script>
    function toggleTaskCompletion(button, taskId) {
        const taskElement = document.getElementById('task-' + taskId);
        taskElement.classList.toggle('completed');
        button.textContent = taskElement.classList.contains('completed') ? 'Uncomplete Task' : 'Complete Task';

        fetch('task_complete.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'task_id=' + taskId
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                taskElement.classList.toggle('completed');
                button.textContent = taskElement.classList.contains('completed') ? 'Uncomplete Task' : 'Complete Task';
                alert('Error: ' + data.error);
            }
        })
        .catch((error) => {
            taskElement.classList.toggle('completed');
            button.textContent = taskElement.classList.contains('completed') ? 'Uncomplete Task' : 'Complete Task';
            console.error('Error:', error);
        });
    }

    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll('.complete-checklist-item').forEach(button => {
            button.addEventListener('click', function() {
                const itemId = this.getAttribute('data-item-id');
                const itemElement = document.getElementById('item-' + itemId);
                const isCompleted = itemElement.classList.contains('completed') ? 0 : 1;
                fetch('checklist_item_complete.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'item_id=' + itemId + '&is_completed=' + isCompleted
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        itemElement.classList.toggle('completed', isCompleted === 1);
                        this.textContent = isCompleted === 1 ? 'Uncomplete' : 'Complete';
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch((error) => {
                    console.error('Error:', error);
                });
            });
        });
    });
    </script>
</body>
</html>
