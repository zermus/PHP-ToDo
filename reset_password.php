<?php
require 'config.php';

session_start();

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';

if (isset($_POST['reset_password'], $_POST['csrf_token'])) {
    // CSRF token validation
    if ($_SESSION['csrf_token'] !== $_POST['csrf_token']) {
        $message = "CSRF token mismatch.";
    } else {
        $password = trim($_POST['password']);
        $confirmPassword = trim($_POST['confirmPassword']);
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
                    $updateStmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE
id = ?");
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
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="stylesheet" href="stylesheet.css">
</head>
<body>
    <div class="container">
        <h2>Reset Password</h2>
        <form action="" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="password" id="password" name="password" placeholder="New Password" required>
            <input type="password" id="verifyPassword" name="confirmPassword" placeholder="Confirm New Password" required>
            <div id="passwordMessage" class="message">Password requirements message</div>
            <button type="submit" name="reset_password">Reset Password</button>
        </form>
        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, 'Error') !== false ? 'error' : 'success'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
    // Include the password validation script from register.php
    document.addEventListener('DOMContentLoaded', function() {
        var password = document.getElementById('password');
        var verifyPassword = document.getElementById('verifyPassword');
        var message = document.getElementById('passwordMessage');

        function validatePassword() {
            var passwordValue = password.value;
            var messages = [];

            if (passwordValue.length < 8) {
                messages.push("at least 8 characters");
            }
            if (!/(?=.*[a-z])/.test(passwordValue)) {
                messages.push("one lowercase letter");
            }
            if (!/(?=.*[A-Z])/.test(passwordValue)) {
                messages.push("one uppercase letter");
            }
            if (!/(?=.*\d)/.test(passwordValue)) {
                messages.push("one number");
            }
            if (!/(?=.*[@$!%*?&])/.test(passwordValue)) {
                messages.push("one special character (@, $, !, %, *, ?, or &)");
            }

            if (messages.length > 0) {
                message.innerHTML = "Password must include " + messages.join(", ") + ".";
                message.style.color = "#FF6347";
            } else {
                message.innerHTML = "Password meets all requirements.";
                message.style.color = "#00FF00";
            }

            if (password.value === verifyPassword.value && password.value.length > 0) {
                message.innerHTML += "<br>Passwords match.";
                message.style.color = "#00FF00";
            } else if (verifyPassword.value.length > 0) {
                message.innerHTML += "<br>Passwords do not match.";
                message.style.color = "#FF6347";
            }
        }

        password.addEventListener('input', validatePassword);
        verifyPassword.addEventListener('input', validatePassword);
    });
    </script>
</body>
</html>
