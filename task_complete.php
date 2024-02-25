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

        // Check current state of the task
        $checkStmt = $pdo->prepare("SELECT completed FROM tasks WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$taskId, $userId]);
        $task = $checkStmt->fetch();

        if ($task) {
    	    $newState = $task['completed'] ? 0 : 1; // Toggle the state and convert to integer
            $updateStmt = $pdo->prepare("UPDATE tasks SET completed = ? WHERE id = ? AND user_id = ?");
            $updateStmt->execute([$newState, $taskId, $userId]);

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
