<?php
session_start();
require 'config.php';

$pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id']) && !isset($_COOKIE['rememberMe'])) {
    header('Location: login.php');
    exit();
} elseif (!isset($_SESSION['user_id']) && isset($_COOKIE['rememberMe'])) {
    $rememberToken = $_COOKIE['rememberMe'];
    $userStmt = $pdo->prepare("SELECT id, name, role, timezone, urgency_green, urgency_critical FROM users WHERE remember_token = ?");
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
$isAdmin = $userDetails['role'] === 'admin' || $userDetails['role'] === 'super_admin';
$urgencyGreen = $userDetails['urgency_green'];
$urgencyCritical = $userDetails['urgency_critical'];

$groupTaskIds = [];
$groupTasksStmt = $pdo->prepare("SELECT t.*, g.name as group_name FROM tasks t INNER JOIN group_memberships gm ON t.group_id = gm.group_id INNER JOIN user_groups g ON gm.group_id = g.id WHERE gm.user_id = ? AND t.completed = FALSE ORDER BY t.due_date AS
C");
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

$tasksWithChecklist = [];
$placeholders = implode(',', array_fill(0, count($groupTaskIds), '?'));
$query = "SELECT * FROM tasks WHERE user_id = ? AND completed = FALSE " . (!empty($groupTaskIds) ? "AND id NOT IN ($placeholders)" : "") . "ORDER BY due_date ASC";
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
    // CSRF token check for logout action
    if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
        echo "CSRF token mismatch.";
        exit;
    }

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
                    $minutesToDue = (int)$interval->days * 1440 + (int)$interval->h * 60 + (int)$interval->i;
                    $taskClass = 'task-item';

                    if ($task['completed']) {
                        $taskClass .= ' task-completed';
                    } elseif ($interval->invert) {
                        $taskClass .= ' task-past-due';
                    } elseif ($minutesToDue <= $urgencyCritical) {
                        $taskClass .= ' task-urgency-critical';
                    } elseif ($minutesToDue <= $urgencyGreen) {
                        $taskClass .= ' task-urgency-soon';
                    } else {
                        $taskClass .= ' task-urgency-green';
                    }
                ?>
                <li id="task-<?php echo $task['id']; ?>" class="<?php echo $taskClass; ?>">
                     <div class="task-summary">
                         <?php echo htmlspecialchars($task['summary']); ?>
                         <br>
                         <span class="due-date">Due on <?php echo $dueDateTime->format('m-d-Y h:i A'); ?></span>
                    </div>

                    <?php if (!empty($task['details'])): ?>
                    <div class="task-details" style="max-height: 100px; overflow-y: auto;">
                        <?php echo $task['details']; ?>
                    </div>
                    <?php endif; ?>
                    <a href="edit_task.php?id=<?php echo $task['id']; ?>" class="edit-link">Edit</a>
                    <button type="button" class="complete-task" data-task-id="<?php echo $task['id']; ?>" onclick="toggleTaskCompletion(this, <?php echo $task['id']; ?>)">
                        <?php echo $task['completed'] ? 'Uncomplete Task' : 'Complete Task'; ?>
                    </button>
                    <?php if (!empty($task['checklist_items'])): ?>
                    <ul>
                        <?php foreach ($task['checklist_items'] as $item): ?>
                        <li id="item-<?php echo $item['id']; ?>" class="<?php echo $item['completed'] ? 'completed' : ''; ?>">
                            <?php
                            // Check if the item content is a URL
                            if (filter_var($item['content'], FILTER_VALIDATE_URL)) {
                                echo '<a href="' . htmlspecialchars($item['content']) . '" target="_blank">' . htmlspecialchars($item['content']) . '</a>';
                            } else {
                                echo htmlspecialchars($item['content']);
                            }
                            ?>
                            <button type="button" class="complete-checklist-item" data-item-id="<?php echo $item['id']; ?>" data-task-id="<?php echo $task['id']; ?>" onclick="toggleChecklistItemCompletion(this, <?php echo $item['id']; ?>)">
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
        <?php foreach ($groupTasksWithChecklist as $groupName => $tasks): ?>
        <div class="task-container">
            <h2><?php echo htmlspecialchars($groupName); ?>'s Tasks</h2>
            <?php if (empty($tasks)): ?>
            <p>No tasks available.</p>
            <?php else: ?>
            <ul class="task-list">
                <?php foreach ($tasks as $task): ?>
                <?php
                    // Repeating the urgency logic for group tasks
                    $dueDateTime = new DateTime($task['due_date'], new DateTimeZone('UTC'));
                    $dueDateTime->setTimezone($userTimezone);
                    $now = new DateTime("now", $userTimezone);
                    $interval = $now->diff($dueDateTime);
                    $minutesToDue = (int)$interval->days * 1440 + (int)$interval->h * 60 + (int)$interval->i;
                    $taskClass = 'task-item';

                    if ($task['completed']) {
                        $taskClass .= ' task-completed';
                    } elseif ($interval->invert) {
                        $taskClass .= ' task-past-due';
                    } elseif ($minutesToDue <= $urgencyCritical) {
                        $taskClass .= ' task-urgency-critical';
                    } elseif ($minutesToDue <= $urgencyGreen) {
                        $taskClass .= ' task-urgency-soon';
                    } else {
                        $taskClass .= ' task-urgency-green';
                    }
                ?>
                <li id="task-<?php echo $task['id']; ?>" class="<?php echo $taskClass; ?>">
                    <div class="task-summary">
                        <?php echo htmlspecialchars($task['summary']); ?>
                        <br>
                        <span class="due-date">Due on <?php echo $dueDateTime->format('m-d-Y h:i A'); ?></span>
                    </div>

                    <?php if (!empty($task['details'])): ?>
                    <div class="task-details" style="max-height: 100px; overflow-y: auto;">
                        <?php echo $task['details']; ?>
                    </div>
                    <?php endif; ?>
                    <a href="edit_task.php?id=<?php echo $task['id']; ?>" class="edit-link">Edit</a>
                    <button type="button" class="complete-task" data-task-id="<?php echo $task['id']; ?>" onclick="toggleTaskCompletion(this, <?php echo $task['id']; ?>)">
                        <?php echo $task['completed'] ? 'Uncomplete Task' : 'Complete Task'; ?>
                    </button>
                    <?php if (!empty($task['checklist_items'])): ?>
                    <ul>
                        <?php foreach ($task['checklist_items'] as $item): ?>
                        <li id="item-<?php echo $item['id']; ?>" class="<?php echo $item['completed'] ? 'completed' : ''; ?>">
                            <?php
                            // Check if the item content is a URL
                            if (filter_var($item['content'], FILTER_VALIDATE_URL)) {
                                echo '<a href="' . htmlspecialchars($item['content']) . '" target="_blank">' . htmlspecialchars($item['content']) . '</a>';
                            } else {
                                echo htmlspecialchars($item['content']);
                            }
                            ?>
                            <button type="button" class="complete-checklist-item" data-item-id="<?php echo $item['id']; ?>" data-task-id="<?php echo $task['id']; ?>" onclick="toggleChecklistItemCompletion(this, <?php echo $item['id']; ?>)">
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
        <?php endforeach; ?>

        <div class="logout-container" style="margin-top: 20px;">
            <a href="calendar.php" class="btn">Calendar</a>
            <a href="?logout&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn">Logout</a>
        </div>
    </div>
    <script>
    function toggleTaskCompletion(button, taskId) {
        const taskElement = document.getElementById('task-' + taskId);
        const isCompleted = taskElement.classList.toggle('task-completed');
        button.textContent = isCompleted ? 'Uncomplete Task' : 'Complete Task';

        fetch('task_complete.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'task_id=' + taskId + '&completed=' + isCompleted + '&csrf_token=' + encodeURIComponent('<?php echo $_SESSION['csrf_token']; ?>')
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                taskElement.classList.toggle('task-completed', !isCompleted);
                button.textContent = isCompleted ? 'Complete Task' : 'Uncomplete Task';
                alert('Error: ' + data.error);
            }
        })
        .catch((error) => {
            console.error('Error:', error);
        });
    }

    function toggleChecklistItemCompletion(button, itemId) {
        const itemElement = document.getElementById('item-' + itemId);
        const isCompleted = !itemElement.classList.contains('completed');
        fetch('checklist_item_complete.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'item_id=' + itemId + '&is_completed=' + (isCompleted ? 1 : 0) + '&csrf_token=' + encodeURIComponent('<?php echo $_SESSION['csrf_token']; ?>')
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                itemElement.classList.toggle('completed', isCompleted);
                button.textContent = isCompleted ? 'Uncomplete' : 'Complete';
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch((error) => {
            console.error('Error:', error);
        });
    }

    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll('.complete-checklist-item').forEach(button => {
            button.removeEventListener('click');
            button.addEventListener('click', function() {
                toggleChecklistItemCompletion(this, this.getAttribute('data-item-id'));
            });
        });
    });
    </script>
</body>
</html>
