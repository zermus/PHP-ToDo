<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) && !isset($_COOKIE['rememberMe'])) {
    header('Location: login.php');
    exit();
}

$errorMessage = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch groups the user is part of
    $groupStmt = $pdo->prepare("SELECT g.id, g.name FROM user_groups g INNER JOIN group_memberships m ON g.id = m.group_id WHERE m.user_id = ?");
    $groupStmt->execute([$_SESSION['user_id']]);
    $groups = $groupStmt->fetchAll();

    if (!isset($_SESSION['timezone'])) {
        $userTimezoneQuery = $pdo->prepare("SELECT timezone FROM users WHERE id = ?");
        $userTimezoneQuery->execute([$_SESSION['user_id']]);
        $userTimezoneResult = $userTimezoneQuery->fetch();
        $_SESSION['timezone'] = $userTimezoneResult['timezone'] ?? 'UTC';
    }

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $taskName = filter_input(INPUT_POST, 'taskName', FILTER_SANITIZE_STRING);
        $taskDetails = filter_input(INPUT_POST, 'taskDetails', FILTER_SANITIZE_STRING);
        $dueDate = filter_input(INPUT_POST, 'dueDate', FILTER_SANITIZE_STRING);
        $dueTime = filter_input(INPUT_POST, 'dueTime', FILTER_SANITIZE_STRING);
        $reminderPreference = filter_input(INPUT_POST, 'reminderPreference', FILTER_SANITIZE_STRING);
        $isChecklist = isset($_POST['isChecklist']) ? 1 : 0;
        $checklistItems = isset($_POST['checklist']) ? $_POST['checklist'] : [];
        $groupId = !empty($_POST['group_id']) ? $_POST['group_id'] : null;

        if (empty($taskName)) {
            $errorMessage = 'Please enter a task name.';
        } else {
            $userTimezone = new DateTimeZone($_SESSION['timezone']);
            $dueDateTime = new DateTime("$dueDate $dueTime", $userTimezone);
            $dueDateTime->setTimezone(new DateTimeZone('UTC'));
            $reminderPreference = !empty($reminderPreference) ? $reminderPreference : NULL;

            // Insert task with potential group ID
            $stmt = $pdo->prepare("INSERT INTO tasks (user_id, group_id, summary, due_date, reminder_preference, details) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $groupId, $taskName, $dueDateTime->format('Y-m-d H:i:s'), $reminderPreference, $taskDetails]);

            $taskId = $pdo->lastInsertId();

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
    <title>Create Task</title>
    <link rel="stylesheet" href="stylesheet.css">
</head>
<body>
    <div class="container">
        <h1>Create New Task</h1>
        <form action="" method="post">
            <!-- Task Name -->
            <div class="form-group">
                <label for="taskName">Task Name:</label>
                <input type="text" id="taskName" name="taskName" required>
            </div>

            <!-- Checklist Toggle -->
            <div class="form-group">
                <input type="checkbox" id="isChecklist" name="isChecklist" onchange="toggleTaskType()">
                <label for="isChecklist">Is this a checklist?</label>
            </div>

            <!-- Task Details -->
            <div id="taskDetailsContainer" class="form-group">
                <label for="taskDetails">Task Details:</label>
                <textarea id="taskDetails" name="taskDetails" rows="8" style="width: 100%;"></textarea>
            </div>

            <!-- Checklist Container -->
            <div id="checklistContainer" class="form-group" style="display:none;">
                <label>Checklist Items:</label>
                <div id="checklistItems"></div>
                <button type="button" onclick="addChecklistItem()">Add Checklist Item</button>
            </div>

            <!-- Due Date and Time -->
            <div class="form-group">
                <label for="dueDate">Due Date:</label>
                <input type="date" id="dueDate" name="dueDate">
                <label for="dueTime">Time:</label>
                <input type="time" id="dueTime" name="dueTime">
            </div>

            <!-- Reminder Preference -->
            <div class="form-group">
                <label for="reminderPreference">Reminder Preference:</label>
                <select id="reminderPreference" name="reminderPreference">
                    <option value="">None</option>
                    <option value="15m">15 minutes before</option>
                    <option value="30m">30 minutes before</option>
                    <option value="1h">1 hour before</option>
                    <option value="2h">2 hours before</option>
                    <option value="4h">4 hours before</option>
                    <option value="12h">12 hours before</option>
                    <option value="24h">24 hours before</option>
                </select>
            </div>

            <!-- Group Assignment Dropdown (visible only if user is part of a group) -->
            <?php if (!empty($groups)): ?>
            <div class="form-group">
                <label for="group_id">Assign to Group:</label>
                <select id="group_id" name="group_id">
                    <option value="">None</option>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Form Buttons -->
            <button type="submit" class="btn">Create Task</button>
            <button type="button" class="btn" onclick="window.location.href='main.php'">Cancel Task</button>
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
