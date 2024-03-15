<?php
require 'config.php';

$message = '';
$messageClass = 'error';

if (isset($_GET['token'])) {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $token = $_GET['token'];

    // Retrieve the user based on the verification token
    $stmt = $pdo->prepare("SELECT id, new_email FROM users WHERE verification_token = ? AND new_email_verified = FALSE");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        // Update the user's email to the new email and clear the verification fields
        $updateStmt = $pdo->prepare("UPDATE users SET email = ?, email_verified = TRUE, new_email = NULL, verification_token = NULL, new
_email_token = NULL, new_email_token_expiry = NULL WHERE id = ?");
        $updateStmt->execute([$user['new_email'], $user['id']]);

        $message = "Your new email address has been successfully verified. You may now log in with your new email address. You will be r
edirected to the login page.";
        $messageClass = 'success';

        // Redirect to login page after 10 seconds
        header("refresh:10;url=login.php");
    } else {
        $message = "This verification link is invalid or has expired.";
    }
} else {
    $message = "No verification token provided.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Email Verification</title>
    <link rel="stylesheet" href="stylesheet.css">
</head>
<body>
    <div class="verification-message <?php echo $messageClass; ?>">
        <p><?php echo $message; ?></p>
    </div>
</body>
</html>
