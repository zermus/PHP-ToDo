<?php
session_start();
require 'config.php';

$response = ['success' => false];

if (isset($_POST['task_id']) && isset($_SESSION['user_id'])) {
    $taskId = $_POST['task_id'];
    $userId = $_SESSION['user_id'];

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check current state of the task for both individual users and group members
        $checkStmt = $pdo->prepare("
            SELECT completed
            FROM tasks t
            LEFT JOIN group_memberships gm ON t.group_id = gm.group_id
            WHERE t.id = ? AND (t.user_id = ? OR (gm.user_id = ? AND gm.group_id = t.group_id))
        ");
        $checkStmt->execute([$taskId, $userId, $userId]);
        $task = $checkStmt->fetch();

        if ($task) {
            $newState = $task['completed'] ? 0 : 1; // Toggle the state and convert to integer
            // Update task completion state for both individual users and group tasks
            $updateStmt = $pdo->prepare("
                UPDATE tasks t
                LEFT JOIN group_memberships gm ON t.group_id = gm.group_id
                SET completed = ?
                WHERE t.id = ? AND (t.user_id = ? OR (gm.user_id = ? AND gm.group_id = t.group_id))
            ");
            $updateStmt->execute([$newState, $taskId, $userId, $userId]);

            $response['success'] = true;
            $response['newState'] = $newState;
        }
    } catch (PDOException $e) {
        $response['error'] = $e->getMessage();
    }
} else {
    $response['error'] = "Invalid request.";
}

echo json_encode($response);
?>
