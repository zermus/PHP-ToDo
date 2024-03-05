<?php
// Start the session
session_start();

// Redirect to main.php if already logged in
if (isset($_SESSION['user_id']) || isset($_COOKIE['rememberMe'])) {
    header('Location: main.php');
    exit();
}

require 'config.php';

$message = '';

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_POST['login'])) {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "CSRF token mismatch.";
    } else {
        $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
        $password = $_POST['password'];
        $rememberMe = isset($_POST['rememberMe']);

        try {
            $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];

                if ($rememberMe) {
                    $rememberToken = bin2hex(random_bytes(16));
                    // Set cookie with secure and httponly flags
                    setcookie('rememberMe', $rememberToken, time() + 86400 * 30, '/', '', true, true);

                    $updateStmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                    $updateStmt->execute([$rememberToken, $user['id']]);
                }

                header("Location: main.php");
                exit();
            } else {
                $message = "Invalid username or password.";
            }
        } catch (PDOException $e) {
            // Generic error message
            $message = "An error occurred. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>To Do Login Page</title>
    <link rel="stylesheet" href="stylesheet.css">
</head>
<body>
    <div class="login-form">
        <h2>To Do Login Page</h2>
        <form action="" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <div class="remember-me">
                <input type="checkbox" name="rememberMe" id="rememberMe">
                <label for="rememberMe">Remember Me</label>
            </div>
            <button type="submit" name="login">Login</button>
        </form>
        <div class="forgot-password">
            <!-- Update forgot password link -->
            <a href="forgot_password.php" class="btn">Forgot Password?</a>
        </div>
        <div class="register">
            <!-- Register button -->
            <a href="register.php" class="btn">Register</a>
        </div>
        <?php if ($message): ?>
            <div class="message error">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
