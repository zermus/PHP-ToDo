<?php
session_start();
require 'config.php';

$response = ['success' => false, 'newState' => null];

if (isset($_POST['task_id']) && isset($_SESSION['user_id'])) {
    $taskId = $_POST['task_id'];
    $userId = $_SESSION['user_id'];

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $taskStmt = $pdo->prepare("SELECT user_id, group_id, completed, receive_completion_email FROM tasks WHERE id = ?");
        $taskStmt->execute([$taskId]);
        $task = $taskStmt->fetch();

        if ($task) {
            $isAuthorized = ($task['user_id'] == $userId) || ($task['group_id'] && inGroup($pdo, $userId, $task['group_id']));

            if ($isAuthorized) {
                $newState = $task['completed'] ? 0 : 1;

                // Update the task as completed or not, and reset completion_email_sent if uncompleted
                $updateStmt = $pdo->prepare("UPDATE tasks SET completed = ?, completion_email_sent = ? WHERE id = ?");
                $updateStmt->execute([$newState, $newState ? 0 : null, $taskId]);

                $response['success'] = true;
                $response['newState'] = $newState;
            } else {
                $response['error'] = 'Not authorized to complete this task.';
            }
        } else {
            $response['error'] = 'Task not found.';
        }
    } catch (PDOException $e) {
        $response['error'] = $e->getMessage();
    }
} else {
    $response['error'] = "Invalid request.";
}

echo json_encode($response);

function inGroup($pdo, $userId, $groupId) {
    $groupStmt = $pdo->prepare("SELECT COUNT(*) FROM group_memberships WHERE user_id = ? AND group_id = ?");
    $groupStmt->execute([$userId, $groupId]);
    return $groupStmt->fetchColumn() > 0;
}
?>
