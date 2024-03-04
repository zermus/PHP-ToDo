<?php
require 'config.php';

session_start();

// Implement rate limiting
$rateLimit = 3; // Maximum attempts allowed within the time frame
$timeFrame = 60 * 15; // 15 minutes

// Initialize or update the count and timestamp in the session
if (!isset($_SESSION['reset_attempts'])) {
    $_SESSION['reset_attempts'] = 0;
    $_SESSION['reset_timestamp'] = time();
} else {
    // Reset count if the time frame has passed
    if ($_SESSION['reset_timestamp'] + $timeFrame < time()) {
        $_SESSION['reset_attempts'] = 0;
        $_SESSION['reset_timestamp'] = time();
    }
}

$message = '';

if (isset($_POST['forgot_password'])) {
    if ($_SESSION['reset_attempts'] < $rateLimit) {
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);

        if ($email) {
            try {
                $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Retrieve user information by email
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                // If user exists, send a password reset email
                if ($user) {
                    // Generate a unique token for password reset
                    $resetToken = bin2hex(random_bytes(32));

                    // Store the reset token in the database
                    $updateStmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?");
                    $updateStmt->execute([$resetToken, $user['id']]);

                    // Send email with password reset link
                    $resetLink = $base_url . "reset_password.php?token=$resetToken";
                    $to = $user['email'];
                    $subject = "Password Reset Request";
                    $message = "Hello,\n\n";
                    $message .= "You have requested to reset your password. Please click on the link below to reset your password:\n";
                    $message .= "$resetLink\n\n";
                    $message .= "If you did not request this, please ignore this email.\n\n";
                    $message .= "Thank you,\nThe To Do Team";
                    $headers = "From: $from_email";
                    mail($to, $subject, $message, $headers);
                }

                // Generic success message
                $message = "If your email is registered, you will receive a password reset link.";

                $_SESSION['reset_attempts'] += 1;
            } catch (PDOException $e) {
                // Log error (log to a file or another error handling mechanism)
                error_log($e->getMessage());

                // Generic error message
                $message = "An error occurred. Please try again later.";
            }
        } else {
            $message = "Invalid email format.";
        }
    } else {
        $message = "Too many requests. Please try again later.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="stylesheet.css">
    <style>
        input[type="email"]::placeholder {
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Forgot Password</h2>
        <form action="" method="post">
            <input type="email" name="email" placeholder="Email Address" required>
            <button type="submit" name="forgot_password">Reset Password</button>
        </form>
        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, 'Error') !== false ? 'error' : 'success'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        <!-- Back to Login Button -->
        <div class="back-to-login">
            <a href="login.php" class="btn">Back to Login</a>
        </div>
    </div>
</body>
</html>
