<?php
// Start the session
session_start();

require 'config.php';

$message = '';

// Check if the user is logged in via session or rememberMe cookie
if (isset($_SESSION['user_id'])) {
    header('Location: main.php');
    exit();
} elseif (isset($_COOKIE['rememberMe'])) {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Validate rememberMe cookie against the database including email verification check
    $rememberToken = $_COOKIE['rememberMe'];
    $userStmt = $pdo->prepare("SELECT id, username FROM users WHERE remember_token = ? AND email_verified = TRUE");
    $userStmt->execute([$rememberToken]);
    $userDetails = $userStmt->fetch();

    if ($userDetails) {
        // Set session variables and redirect to main.php
        $_SESSION['user_id'] = $userDetails['id'];
        $_SESSION['username'] = $userDetails['username'];
        header('Location: main.php');
        exit();
    }
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_POST['login'])) {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || trim($_POST['csrf_token']) !== $_SESSION['csrf_token']) {
        $message = "CSRF token mismatch.";
    } else {
        $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
        $password = trim($_POST['password']); // Trim password input
        $rememberMe = isset($_POST['rememberMe']);

        // Trim username input
        $username = trim($username);

        $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if (!$user['email_verified']) {
                $message = "Please verify your email address before logging in.";
            } else {
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
            }
        } else {
            $message = "Invalid username or password.";
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
            <a href="forgot_password.php" class="btn">Forgot Password?</a>
        </div>
        <div class="register">
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
