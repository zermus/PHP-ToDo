<?php
require 'config.php';

$message = '';
$messageClass = 'error';

$pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    $stmt = $pdo->prepare("SELECT id, new_email, new_email_token, new_email_token_expiry FROM users WHERE new_email_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user && $user['new_email_token'] === $token) {
        $currentDateTime = new DateTime();
        $tokenExpiryDateTime = new DateTime($user['new_email_token_expiry']);

        if ($currentDateTime < $tokenExpiryDateTime) {
            // Generate a new verification token for the new email
            $newVerificationToken = bin2hex(random_bytes(16));
            $verificationLink = $base_url . "verify_new_email.php?token=" . $newVerificationToken;

            // Update the user with the new email verification token
            $updateStmt = $pdo->prepare("UPDATE users SET new_email_verified = 0, verification_token = ? WHERE id = ?");
            $updateStmt->execute([$newVerificationToken, $user['id']]);

            // Send verification email to the new email address
            $subject = "Verify Your New Email Address";
            $emailMessage = "Hello,\n\nPlease click the following link to verify your new email address:\n$verificationLink\n\nThank you
!";
            $headers = "From: " . $from_email;
            mail($user['new_email'], $subject, $emailMessage, $headers);

            // Log out the user
            session_start();
            $_SESSION = array();
            session_destroy();
            setcookie('rememberMe', '', time() - 3600, '/');

            $message = "Your email address has been successfully updated. You have been logged out. Please log in with your new email ad
dress.";
            $messageClass = 'success';

            // Redirect to login page after 10 seconds
            header("refresh:10;url=login.php");
        } else {
            $message = "The token has expired. Please request a new email change.";
        }
    } else {
        $message = "This token is invalid. Please use the link sent to your email address.";
    }
} else {
    $message = "No token provided.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Change Confirmation</title>
    <link rel="stylesheet" href="stylesheet.css">
</head>
<body>
    <div class="verification-message <?php echo $messageClass; ?>">
        <p><?php echo $message; ?></p>
    </div>
</body>
</html>
