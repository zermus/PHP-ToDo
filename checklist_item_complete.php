<?php
session_start();
require 'config.php';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Error: User not logged in.");
}

// Check if the request is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $itemId = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
	$isCompleted = isset($_POST['is_completed']) ? (int)boolval($_POST['is_completed']) : 0;

        // Verify that the checklist item exists and belongs to a task of the logged-in user
        $verifyStmt = $pdo->prepare("SELECT ci.id FROM checklist_items ci JOIN tasks t ON ci.task_id = t.id WHERE ci.id = ? AND t.user_id = ?");
        $verifyStmt->execute([$itemId, $_SESSION['user_id']]);
        if ($verifyStmt->rowCount() == 0) {
            throw new Exception("Invalid item ID or access denied.");
        }

        // Update the completion status of the checklist item
        $updateStmt = $pdo->prepare("UPDATE checklist_items SET completed = ? WHERE id = ?");
        $updateStmt->execute([$isCompleted, $itemId]);

        echo json_encode(["success" => true]);
    } catch (PDOException $e) {
        echo json_encode(["error" => "Database error: " . $e->getMessage()]);
    } catch (Exception $e) {
        echo json_encode(["error" => $e->getMessage()]);
    }
} else {
    echo json_encode(["error" => "Invalid request method."]);
}
?>
