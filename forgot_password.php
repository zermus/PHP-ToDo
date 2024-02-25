<?php
require 'config.php';

$message = '';

if (isset($_POST['forgot_password'])) {
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);

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
            $resetLink = $base_url . "reset_password.php?token=$resetToken"; // Updated to use $base_url
            $to = $user['email'];
            $subject = "Password Reset Request";
            $message = "Hello {$user['name']},\n\n";
            $message .= "You have requested to reset your password. Please click on the link below to reset your password:\n";
            $message .= "$resetLink\n\n";
            $message .= "If you did not request this, please ignore this email.\n\n";
            $message .= "Thank you,\nThe To Do Team";
            $headers = "From: $from_email"; // Updated to use $from_email
            mail($to, $subject, $message, $headers);

            // Display success message
            $message = "An email has been sent to your registered email address with instructions to reset your password.";
        } else {
            $message = "Invalid email address. Please try again.";
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
    <title>Forgot Password</title>
    <link rel="stylesheet" href="stylesheet.css">
    <style>
        /* Match the styles from reset_password.php */
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
    </div>
</body>
</html>
