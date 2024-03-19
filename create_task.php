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

if (isset($_SESSION['user_id'])) {
    $groupStmt = $pdo->prepare("SELECT g.id, g.name FROM user_groups g INNER JOIN group_memberships m ON g.id = m.group_id WHERE m.user_id = ?");
    $groupStmt->execute([$_SESSION['user_id']]);
    $groups = $groupStmt->fetchAll();

    if (!isset($_SESSION['timezone'])) {
        $userTimezoneQuery = $pdo->prepare("SELECT timezone FROM users WHERE id = ?");
        $userTimezoneQuery->execute([$_SESSION['user_id']]);
        $userTimezoneResult = $userTimezoneQuery->fetch();
        $_SESSION['timezone'] = $userTimezoneResult['timezone'] ?? 'UTC';
    }
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = 'CSRF token mismatch.';
    } else {
        $taskName = filter_input(INPUT_POST, 'taskName', FILTER_SANITIZE_STRING);
        // Retrieve raw post data
        $rawTaskDetails = $_POST['taskDetails'] ?? '';
        // Allowed tags for Quill 'snow' theme
        $allowedTags = '<p><strong><em><u><a><ul><ol><li><br><h1><h2><h3><blockquote><code><div><s><strike>';
        // Sanitize the task details
        $taskDetails = strip_tags($rawTaskDetails, $allowedTags);

        $dueDate = filter_input(INPUT_POST, 'dueDate', FILTER_SANITIZE_STRING);
        $dueTime = filter_input(INPUT_POST, 'dueTime', FILTER_SANITIZE_STRING);
        $reminderPreference = filter_input(INPUT_POST, 'reminderPreference', FILTER_SANITIZE_STRING);
        $isChecklist = isset($_POST['isChecklist']) ? 1 : 0;
        $checklistItems = $_POST['checklist'] ?? [];
        $groupId = !empty($_POST['group_id']) ? $_POST['group_id'] : null;
        $receiveCompletionEmail = isset($_POST['receiveCompletionEmail']) ? 1 : 0;

        if (empty($taskName)) {
            $errorMessage = 'Please enter a task name.';
        } else {
            $userTimezone = new DateTimeZone($_SESSION['timezone']);
            $dueDateTime = new DateTime("$dueDate $dueTime", $userTimezone);
            $dueDateTime->setTimezone(new DateTimeZone('UTC'));
            $reminderPreference = !empty($reminderPreference) ? $reminderPreference : NULL;

            $stmt = $pdo->prepare("INSERT INTO tasks (user_id, group_id, summary, due_date, reminder_preference, details, receive_completion_email) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $groupId, $taskName, $dueDateTime->format('Y-m-d H:i:s'), $reminderPreference, $taskDetails, $receiveCompletionEmail]);

            $taskId = $pdo->lastInsertId();

            if ($isChecklist) {
                $checklistStmt = $pdo->prepare("INSERT INTO checklist_items (task_id, content) VALUES (?, ?)");
                foreach ($checklistItems as $itemContent) {
                    // Sanitize each checklist item individually
                    $itemContent = strip_tags($itemContent, $allowedTags);
                    if (!empty($itemContent)) {
                        $checklistStmt->execute([$taskId, $itemContent]);
                    }
                }
            }

            header('Location: main.php');
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Task</title>
    <link rel="stylesheet" href="stylesheet.css">
    <!-- Include Quill library from CDN -->
    <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
    <!-- Include DOMPurify from its CDN -->
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.9/dist/purify.min.js"></script>
</head>
<body>
    <div class="container">
        <h1>Create New Task</h1>
        <form action="" method="post">
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

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

            <!-- Task Details (Rich Text Editor) -->
            <div id="taskDetailsContainer" class="form-group">
                <label for="taskDetails">Task Details:</label>
                <div id="editor"></div>
                <textarea name="taskDetails" id="taskDetails" style="display:none;"></textarea>
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

            <!-- Completion Email Preference -->
            <div class="form-group">
                <input type="checkbox" id="receiveCompletionEmail" name="receiveCompletionEmail">
                <label for="receiveCompletionEmail">Receive email upon task completion</label>
            </div>

            <!-- Group Assignment Dropdown -->
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

    <!-- Include Quill JS -->
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

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

        // Set the default text alignment to center
        quill.format('align', 'center');

        document.querySelector('form').onsubmit = function() {
            var html = quill.root.innerHTML;
            var cleanHtml = DOMPurify.sanitize(html);
            document.querySelector('textarea[name="taskDetails"]').value = cleanHtml;
        };

        function toggleTaskType() {
            var isChecklist = document.getElementById('isChecklist').checked;
            var checklistContainer = document.getElementById('checklistContainer');
            if (isChecklist) {
                checklistContainer.style.display = 'block';
                if (document.getElementById('checklistItems').children.length === 0) {
                    addChecklistItem();
                }
            } else {
                checklistContainer.style.display = 'none';
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
