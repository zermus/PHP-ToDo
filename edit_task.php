<?php
session_start();
require 'config.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!isset($_SESSION['user_id']) && !isset($_COOKIE['rememberMe'])) {
    header('Location: login.php');
    exit();
} elseif (!isset($_SESSION['user_id']) && isset($_COOKIE['rememberMe'])) {
    $rememberToken = $_COOKIE['rememberMe'];
    $userStmt = $pdo->prepare("SELECT id, name, role, timezone FROM users WHERE remember_token = ?");
    $userStmt->execute([$rememberToken]);
    $userDetails = $userStmt->fetch();

    if ($userDetails) {
        $_SESSION['user_id'] = $userDetails['id'];
    } else {
        header('Location: login.php');
        exit();
    }
}

$errorMessage = '';

if (isset($_SESSION['user_id'])) {
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
    $receiveCompletionEmail = false;
    $taskDetails = '';

    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $taskId = $_GET['id'];
        $authQuery = $pdo->prepare("SELECT user_id, group_id FROM tasks WHERE id = ?");
        $authQuery->execute([$taskId]);
        $taskInfo = $authQuery->fetch();

        $authorized = ($taskInfo['user_id'] == $_SESSION['user_id']) || ($taskInfo['group_id'] && inGroup($pdo, $_SESSION['user_id'], $taskInfo['group_id']));
        if (!$authorized) {
            $errorMessage = "You do not have permission to edit this task.";
        } else {
            $taskStmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
            $taskStmt->execute([$taskId]);
            $task = $taskStmt->fetch();
            if ($task) {
                $dueDateTime = new DateTime($task['due_date'], new DateTimeZone('UTC'));
                $dueDateTime->setTimezone($userTimezone);
                $localDueDate = $dueDateTime->format('Y-m-d');
                $localDueTime = $dueDateTime->format('H:i');
                $reminderPreference = $task['reminder_preference'];
                $receiveCompletionEmail = $task['receive_completion_email'];
                $taskDetails = $task['details'];
                $isChecklist = (bool)$pdo->query("SELECT COUNT(*) FROM checklist_items WHERE task_id = $taskId")->fetchColumn();
                if ($isChecklist) {
                    $checklistItems = $pdo->query("SELECT content FROM checklist_items WHERE task_id = $taskId")->fetchAll(PDO::FETCH_COLUMN);
                }
            } else {
                $errorMessage = 'Task not found.';
            }
        }
    } else {
        $errorMessage = 'Task ID not provided.';
    }

    try {
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && $authorized && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
            $taskName = filter_input(INPUT_POST, 'taskName', FILTER_SANITIZE_STRING);
            $rawTaskDetails = $_POST['taskDetails'] ?? '';
            $allowedTags = '<p><strong><em><u><a><ul><ol><li><br><h1><h2><h3><blockquote><code><div><s><strike>';
            $taskDetails = strip_tags($rawTaskDetails, $allowedTags);

            $dueDate = filter_input(INPUT_POST, 'dueDate', FILTER_SANITIZE_STRING);
            $dueTime = filter_input(INPUT_POST, 'dueTime', FILTER_SANITIZE_STRING);
            $reminderPreference = filter_input(INPUT_POST, 'reminderPreference', FILTER_SANITIZE_STRING);
            $isChecklist = isset($_POST['isChecklist']) ? 1 : 0;
            $checklistItems = isset($_POST['checklist']) ? $_POST['checklist'] : [];
            $completed = isset($_POST['completed']) ? 1 : 0;
            $groupId = !empty($_POST['group_id']) ? $_POST['group_id'] : null;
            $receiveCompletionEmail = isset($_POST['receiveCompletionEmail']) ? 1 : 0;

            $validReminderPreferences = ['15m', '30m', '1h', '2h', '4h', '12h', '24h'];
            if (!in_array($reminderPreference, $validReminderPreferences)) {
                $reminderPreference = NULL;
            }

            $dueDateTime = new DateTime($dueDate . ' ' . $dueTime, $userTimezone);
            $dueDateTime->setTimezone(new DateTimeZone('UTC'));

            $updateStmt = $pdo->prepare("UPDATE tasks SET summary = ?, group_id = ?, due_date = ?, reminder_preference = ?, completed = ?, details = ?, receive_completion_email = ?, reminder_sent = ? WHERE id = ?");
            $updateStmt->execute([$taskName, $groupId, $dueDateTime->format('Y-m-d H:i:s'), $reminderPreference, $completed, $taskDetails, $receiveCompletionEmail, ($dueDate !== $localDueDate || $dueTime !== $localDueTime) ? 0 : $task['reminder_sent'],
$taskId]);

            if ($isChecklist) {
                $pdo->exec("DELETE FROM checklist_items WHERE task_id = $taskId");
                $insertStmt = $pdo->prepare("INSERT INTO checklist_items (task_id, content) VALUES (?, ?)");
                foreach ($checklistItems as $itemContent) {
                    $itemContent = strip_tags($itemContent, $allowedTags);
                    if (!empty($itemContent)) {
                        $insertStmt->execute([$taskId, $itemContent]);
                    }
                }
            }

            header('Location: main.php');
            exit();
        }
    } catch (PDOException $e) {
        $errorMessage = "Database error: " . $e->getMessage();
    }
}

function inGroup($pdo, $userId, $groupId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM group_memberships WHERE user_id = ? AND group_id = ?");
    $stmt->execute([$userId, $groupId]);
    return $stmt->fetchColumn() > 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Task</title>
    <link rel="stylesheet" href="stylesheet.css">
    <!-- Include Quill library from CDN -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <!-- Include DOMPurify from CDN -->
    <script src="https://cdn.jsdelivr.net/npm/dompurify@2.3.3/dist/purify.min.js"></script>
</head>
<body>
    <div class="container">
        <h1>Edit Task</h1>
        <?php if ($errorMessage): ?>
        <div class="error-message"><?php echo $errorMessage; ?></div>
        <?php else: ?>
        <form action="" method="post">
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <!-- Task Name -->
            <div class="form-group">
                <label for="taskName">Task Name:</label>
                <input type="text" id="taskName" name="taskName" value="<?php echo htmlspecialchars($task['summary'] ?? ''); ?>" required>
            </div>

            <!-- Checklist Toggle -->
            <div class="form-group">
                <input type="checkbox" id="isChecklist" name="isChecklist" <?php echo $isChecklist ? 'checked' : ''; ?> onchange="toggleTaskType()">
                <label for="isChecklist">Is this a checklist?</label>
            </div>

            <!-- Task Details (Rich Text Editor) -->
            <div id="taskDetailsContainer" class="form-group">
                <label for="taskDetails">Task Details:</label>
                <div id="editor"></div>
                <textarea name="taskDetails" id="taskDetails" style="display:none;"></textarea>
            </div>

            <!-- Checklist Container -->
            <div id="checklistContainer" class="form-group" style="<?php echo $isChecklist ? '' : 'display:none;'; ?>">
                <label>Checklist Items:</label>
                <div id="checklistItems">
                    <?php foreach ($checklistItems as $item): ?>
                    <input type="text" name="checklist[]" value="<?php echo htmlspecialchars($item); ?>">
                    <?php endforeach; ?>
                </div>
                <button type="button" onclick="addChecklistItem()">Add Checklist Item</button>
            </div>

            <!-- Due Date and Time -->
            <div class="form-group">
                <label for="dueDate">Due Date:</label>
                <input type="date" id="dueDate" name="dueDate" value="<?php echo $localDueDate; ?>">
                <label for="dueTime">Time:</label>
                <input type="time" id="dueTime" name="dueTime" value="<?php echo $localDueTime; ?>">
            </div>

            <!-- Reminder Preference -->
            <div class="form-group">
                <label for="reminderPreference">Reminder Preference:</label>
                <select id="reminderPreference" name="reminderPreference">
                    <option value="">None</option>
                    <option value="15m" <?php echo ($reminderPreference === '15m' ? 'selected' : ''); ?>>15 minutes before</option>
                    <option value="30m" <?php echo ($reminderPreference === '30m' ? 'selected' : ''); ?>>30 minutes before</option>
                    <option value="1h" <?php echo ($reminderPreference === '1h' ? 'selected' : ''); ?>>1 hour before</option>
                    <option value="2h" <?php echo ($reminderPreference === '2h' ? 'selected' : ''); ?>>2 hours before</option>
                    <option value="4h" <?php echo ($reminderPreference === '4h' ? 'selected' : ''); ?>>4 hours before</option>
                    <option value="12h" <?php echo ($reminderPreference === '12h' ? 'selected' : ''); ?>>12 hours before</option>
                    <option value="24h" <?php echo ($reminderPreference === '24h' ? 'selected' : ''); ?>>24 hours before</option>
                </select>
            </div>

            <!-- Completion Email Preference -->
            <div class="form-group">
                <input type="checkbox" id="receiveCompletionEmail" name="receiveCompletionEmail" <?php echo $receiveCompletionEmail ? 'checked' : ''; ?>>
                <label for="receiveCompletionEmail">Receive email upon task completion</label>
            </div>

            <!-- Group Assignment Dropdown -->
            <?php if (!empty($groups)): ?>
            <div class="form-group">
                <label for="group_id">Assign to Group:</label>
                <select id="group_id" name="group_id">
                    <option value="">None</option>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?php echo $group['id']; ?>" <?php echo ($task['group_id'] == $group['id'] ? 'selected' : ''); ?>><?php echo htmlspecialchars($group['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Task Completion -->
            <div class="form-group">
                <input type="checkbox" id="completed" name="completed" <?php echo $task['completed'] ? 'checked' : ''; ?>>
                <label for="completed">Completed</label>
            </div>

            <button type="submit" class="btn">Update Task</button>
            <button type="button" class="btn" onclick="window.location.href='main.php'">Cancel</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Include Quill JS -->
    <script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
    <!-- Include DOMPurify from CDN -->
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.9/dist/purify.min.js"></script>

    <script>
        var quill = new Quill('#editor', {
            theme: 'snow',
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{'list': 'ordered'}, {'list': 'bullet'}],
                    [{'indent': '-1'}, {'indent': '+1'}],
                    [{'size': ['small', false, 'large', 'huge']}],
                    [{'align': []}],
                    ['link'],
                    ['clean']
                ]
            }
        });

        // When the form is submitted, populate the hidden textarea with
        // the sanitized content of the rich text editor.
        document.querySelector('form').onsubmit = function() {
            var html = quill.root.innerHTML;
            var cleanHtml = DOMPurify.sanitize(html);
            document.querySelector('textarea[name="taskDetails"]').value = cleanHtml;
        };

        // Load existing task details into Quill editor
        window.onload = function() {
            var taskDetailsValue = <?php echo json_encode($taskDetails); ?>;
            quill.root.innerHTML = taskDetailsValue;
        };

        function toggleTaskType() {
            var isChecklist = document.getElementById('isChecklist').checked;
            var checklistContainer = document.getElementById('checklistContainer');
            var taskDetailsContainer = document.getElementById('taskDetailsContainer');
            if (isChecklist) {
                taskDetailsContainer.style.display = 'none';
                checklistContainer.style.display = 'block';
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
