<?php
require 'config.php';

$message = '';

if (isset($_POST['reset_password'])) {
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirmPassword'];
    $token = $_GET['token'];

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Retrieve user information by valid token
        $stmt = $pdo->prepare("SELECT * FROM users WHERE reset_token = ? AND reset_token_expiry > NOW()");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if ($user) {
            // Verify password and update it
            if ($password === $confirmPassword) {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
                $updateStmt->execute([$hashedPassword, $user['id']]);
                $message = "Password has been reset successfully.";
                // Delay redirection by 5 seconds
                sleep(5);
                // Redirect to login page after 5-second delay
                header("Location: login.php");
                exit();
            } else {
                $message = "Passwords do not match.";
            }
        } else {
            $message = "Invalid or expired reset token.";
        }
    } catch (PDOException $e) {
        $message = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="stylesheet" href="stylesheet.css">
    <style>
        input[type="password"]::placeholder {
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Reset Password</h2>
        <form action="" method="post">
            <input type="password" id="password" name="password" placeholder="New Password" required>
            <input type="password" id="verifyPassword" name="confirmPassword" placeholder="Confirm New Password" required>
            <div id="passwordMessage" class="message">Password must be at least 8 characters long and include at least one uppercase letter, one lowercase letter, one num
ber, and one special character.
</div>
            <button type="submit" name="reset_password">Reset Password</button>
        </form>
        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, 'Error') !== false ? 'error' : 'success'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
