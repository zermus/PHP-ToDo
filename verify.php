<?php
require 'config.php';

$message = '';
$redirect = false;

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT id FROM users WHERE verification_token = ? AND email_verified = FALSE");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if ($user) {
            $updateStmt = $pdo->prepare("UPDATE users SET email_verified = TRUE, verification_token = NULL WHERE id = ?");
            $updateStmt->execute([$user['id']]);

            $message = "Your email has been successfully verified. You will be redirected to the login page in 5 seconds.";
            $redirect = true;
        } else {
            $message = "This verification link is invalid or expired.";
        }
    } catch (PDOException $e) {
        $message = "Error: " . $e->getMessage();
    }
} else {
    $message = "No verification token provided.";
}

if ($redirect) {
    header("refresh:5;url=login.php");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification</title>
    <link rel="stylesheet" href="stylesheet.css">
</head>
<body>
    <div class="verification-message <?php echo $redirect ? 'success' : 'error'; ?>">
        <p><?php echo $message; ?></p>
    </div>
</body>
</html>
