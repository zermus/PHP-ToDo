<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) && !isset($_COOKIE['rememberMe'])) {
    header('Location: login.php');
    exit();
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $errorMessage = '';

    // Fetch groups the user is part of
    $groupStmt = $pdo->prepare("SELECT g.id, g.name FROM user_groups g INNER JOIN group_memberships m ON g.id = m.group_id WHERE m.user_id = ?");
    $groupStmt->execute([$_SESSION['user_id']]);
    $groups = $groupStmt->fetchAll();

    $userTimezoneQuery = $pdo->prepare("SELECT timezone FROM users WHERE id = ?");
    $userTimezoneQuery->execute([$_SESSION['user_id']]);
    $userTimezoneResult = $userTimezoneQuery->fetch();
    $userTimezone = new DateTimeZone($userTimezoneResult['timezone'] ?? 'UTC');

    $isChecklist = false;
    $checklistItems = [];
    $taskId = null;
    $localDueDate = '';
    $localDueTime = '';
    $reminderPreference = '';
    $taskDetails = ''; // Initialize taskDetails variable

    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $taskId = $_GET['id'];

        $taskStmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
        $taskStmt->execute([$taskId]);
        $task = $taskStmt->fetch();

        if ($task) {
            $dueDateTime = new DateTime($task['due_date'], new DateTimeZone('UTC'));
            $dueDateTime->setTimezone($userTimezone);
            $localDueDate = $dueDateTime->format('Y-m-d');
            $localDueTime = $dueDateTime->format('H:i');

            $reminderPreference = $task['reminder_preference'];
            $taskDetails = $task['details']; // Fetch task details

            $checklistStmt = $pdo->prepare("SELECT COUNT(*) FROM checklist_items WHERE task_id = ?");
            $checklistStmt->execute([$taskId]);
            $isChecklist = (bool) $checklistStmt->fetchColumn();

            if ($isChecklist) {
                $checklistItemsStmt = $pdo->prepare("SELECT content FROM checklist_items WHERE task_id = ?");
                $checklistItemsStmt->execute([$taskId]);
                $checklistItems = $checklistItemsStmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } else {
            $errorMessage = 'Task not found.';
        }
    } else {
        $errorMessage = 'Task ID not provided.';
    }

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $taskName = filter_input(INPUT_POST, 'taskName', FILTER_SANITIZE_STRING);
        $taskDetails = filter_input(INPUT_POST, 'taskDetails', FILTER_SANITIZE_STRING); // Capture updated task details
        $dueDate = filter_input(INPUT_POST, 'dueDate', FILTER_SANITIZE_STRING);
        $dueTime = filter_input(INPUT_POST, 'dueTime', FILTER_SANITIZE_STRING);
        $reminderPreference = filter_input(INPUT_POST, 'reminderPreference', FILTER_SANITIZE_STRING);
        $isChecklist = isset($_POST['isChecklist']) ? 1 : 0;
        $checklistItems = isset($_POST['checklist']) ? $_POST['checklist'] : [];
        $completed = isset($_POST['completed']) ? 1 : 0;
        $groupId = !empty($_POST['group_id']) ? $_POST['group_id'] : null;

        $validReminderPreferences = ['15m', '30m', '1h', '2h', '4h', '12h', '24h'];
        if (!in_array($reminderPreference, $validReminderPreferences)) {
            $reminderPreference = NULL;
        }

        $dueDateTime = new DateTime($dueDate . ' ' . $dueTime, $userTimezone);
        $dueDateTime->setTimezone(new DateTimeZone('UTC'));

        $updateStmt = $pdo->prepare("UPDATE tasks SET summary = ?, group_id = ?, due_date = ?, reminder_preference = ?, completed = ?, details = ? WHERE id = ?");
        $updateStmt->execute([$taskName, $groupId, $dueDateTime->format('Y-m-d H:i:s'), $reminderPreference, $completed, $taskDetails, $taskId]);

        $deleteStmt = $pdo->prepare("DELETE FROM checklist_items WHERE task_id = ?");
        $deleteStmt->execute([$taskId]);

        if ($isChecklist) {
            $checklistStmt = $pdo->prepare("INSERT INTO checklist_items (task_id, content) VALUES (?, ?)");
            foreach ($checklistItems as $item) {
                if (!empty($item)) {
                    $checklistStmt->execute([$taskId, $item]);
                }
            }
        }

        header('Location: main.php');
        exit();
    }
} catch (PDOException $e) {
    $errorMessage = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Task</title>
    <link rel="stylesheet" href="stylesheet.css">
</head>
<body>
    <div class="container">
        <h1>Edit Task</h1>
        <form action="" method="post">
            <div class="form-group">
                <label for="taskName">Task Name:</label>
                <input type="text" id="taskName" name="taskName" value="<?php echo htmlspecialchars($task['summary'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <input type="checkbox" id="isChecklist" name="isChecklist" onchange="toggleTaskType()" <?php echo $isChecklist ? 'checked' : ''; ?>>
                <label for="isChecklist">Is this a checklist?</label>
            </div>

            <div id="taskDetailsContainer" class="form-group" <?php echo $isChecklist ? 'style="display:none;"' : ''; ?>>
                <label for="taskDetails">Task Details:</label>
                <textarea id="taskDetails" name="taskDetails" rows="8" style="width: 100%;"><?php echo htmlspecialchars($taskDetails); ?></textarea>
            </div>

            <div id="checklistContainer" class="form-group" <?php echo $isChecklist ? 'style="display:block;"' : ''; ?>>
                <label>Checklist Items:</label>
                <div id="checklistItems">
                    <?php foreach ($checklistItems as $item): ?>
                        <input type="text" name="checklist[]" value="<?php echo htmlspecialchars($item); ?>">
                    <?php endforeach; ?>
                </div>
                <button type="button" onclick="addChecklistItem()">Add Checklist Item</button>
            </div>

            <div class="form-group">
                <label for="dueDate">Due Date:</label>
                <input type="date" id="dueDate" name="dueDate" value="<?php echo $localDueDate ?? ''; ?>">
                <label for="dueTime">Time:</label>
                <input type="time" id="dueTime" name="dueTime" value="<?php echo $localDueTime ?? ''; ?>">
            </div>

            <div class="form-group">
                <label for="reminderPreference">Reminder Preference:</label>
                <select id="reminderPreference" name="reminderPreference">
                    <option value="">None</option>
                    <?php foreach ($validReminderPreferences as $option): ?>
                        <option value="<?php echo $option; ?>" <?php echo ($reminderPreference === $option ? 'selected' : ''); ?>><?php echo htmlspecialchars($optio
n); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!empty($groups)): ?>
            <div class="form-group">
                <label for="group_id">Assign to Group:</label>
                <select id="group_id" name="group_id">
                    <option value="">None</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?php echo $group['id']; ?>" <?php echo ($task['group_id'] == $group['id'] ? 'selected' : ''); ?>><?php echo htmlspecialchars
($group['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <input type="checkbox" id="completed" name="completed" <?php echo $task['completed'] ? 'checked' : ''; ?>>
                <label for="completed">Completed</label>
            </div>

            <button type="submit" class="btn">Update Task</button>
            <button type="button" class="btn" onclick="window.location.href='main.php'">Cancel</button>
        </form>

        <?php if ($errorMessage): ?>
            <div class="error-message"><?php echo $errorMessage; ?></div>
        <?php endif; ?>
    </div>

    <script>
        function toggleTaskType() {
            var isChecklist = document.getElementById('isChecklist').checked;
            var checklistContainer = document.getElementById('checklistContainer');
            var taskDetailsContainer = document.getElementById('taskDetailsContainer');
            if (isChecklist) {
                taskDetailsContainer.style.display = 'none';
                checklistContainer.style.display = 'block';
                if (document.getElementById('checklistItems').children.length === 0) {
                    addChecklistItem();
                }
            } else {
                checklistContainer.style.display = 'none';
                taskDetailsContainer.style.display = 'block';
            }
        }

        function addChecklistItem() {
            var checklistItems = document.getElementById('checklistItems');
            var newItem = document.createElement('input');
            newItem.setAttribute('type', 'text');
            newItem.setAttribute('name', 'checklist[]');
            checklistItems.appendChild(newItem);
        }
    </script>
</body>
</html>
