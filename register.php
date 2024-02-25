<?php
session_start();

// Redirect to main.php if already logged in
if (isset($_SESSION['user_id']) || isset($_COOKIE['rememberMe'])) {
    header('Location: main.php');
    exit();
}

require 'config.php';

$message = '';
$successMessage = ''; // Initialize success message variable

if (isset($_POST['register'])) {
    // Honeypot field check
    if (!empty($_POST['faxNumber'])) {
        // Looks like a bot filled out the form, so don't process
        exit('No bots allowed!');
    }

    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $verifyPassword = $_POST['verifyPassword'];
    $timezone = $_POST['timezone']; // Get the selected timezone

    if ($password !== $verifyPassword) {
        $message = "The passwords do not match. Please try again.";
    } elseif (!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/", $password)) {
        $message = "Password must be at least 8 characters long and include at least one uppercase letter, one lowercase letter, one number, and one special character.";
    } else {
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Check if username or email already exists
            $userCheckStmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
            $userCheckStmt->execute([$username, $email]);
            if ($userCheckStmt->fetch()) {
                $message = "Username or Email already exists.";
            } else {
                // Insert the new user into the users table
                $verificationToken = bin2hex(random_bytes(16));
                $stmt = $pdo->prepare("INSERT INTO users (name, username, email, password, role, verification_token, timezone) VALUES (?, ?, ?, ?, 'user', ?, ?)");
                $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $verificationToken, $timezone]);

                // Send the verification email
                $verificationLink = $base_url . "verify.php?token=" . $verificationToken;
                $subject = "Verify Your Email";
                $emailMessage = "Hello $name,\n\nPlease click the following link to verify your email and activate your account:\n$verificationLink\n\nThank you!";
                $headers = "From: " . $from_email;

                if (mail($email, $subject, $emailMessage, $headers)) {
                    $successMessage = "Registration successful! Please check your email to verify your account.";
                } else {
                    $message = "Registration completed, but the verification email could not be sent. Please check your server's email settings.";
                }
            }
        } catch (PDOException $e) {
            $message = "Registration failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <link rel="stylesheet" href="stylesheet.css">
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var form = document.querySelector('form');
        var password = document.getElementById('password');
        var verifyPassword = document.getElementById('verifyPassword');
        var message = document.getElementById('passwordMessage');
        var successMessage = "<?php echo $successMessage; ?>"; // Get the PHP successMessage

        function validatePassword() {
            // If there's a success message, don't validate the passwords to clear the message
            if (successMessage !== "") {
                message.innerHTML = '';
                return;
            }

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
</head>
<body>
    <div class="container">
        <h2>To Do Register</h2>
        <form method="post">
            <!-- Honeypot field, hidden from users -->
            <div style="display:none;">
                <input type="text" name="faxNumber" id="faxNumber" placeholder="Leave this field empty">
            </div>
            <input type="text" name="name" placeholder="Name" required>
            <input type="text" name="username" placeholder="Username" required>
            <input type="email" name="email" placeholder="Email" required>
            <select name="timezone" required>
                <option value="" disabled selected>Select Timezone</option>
                <!-- Timezone options -->
                <option value="America/New_York">Eastern Time (US & Canada)</option>
                <option value="America/Chicago">Central Time (US & Canada)</option>
                <option value="America/Denver">Mountain Time (US & Canada)</option>
                <option value="America/Los_Angeles">Pacific Time (US & Canada)</option>
                <option value="America/Anchorage">Alaska</option>
                <option value="America/Halifax">Atlantic Time (Canada)</option>
                <option value="America/Buenos_Aires">Buenos Aires</option>
                <option value="America/Sao_Paulo">Sao Paulo</option>
                <option value="America/Lima">Lima</option>
                <option value="Pacific/Honolulu">Hawaii</option>
                <option value="Europe/London">London</option>
                <option value="Europe/Berlin">Berlin, Frankfurt, Paris, Rome, Madrid</option>
                <option value="Europe/Athens">Athens, Istanbul, Minsk</option>
                <option value="Europe/Moscow">Moscow, St. Petersburg, Volgograd</option>
                <!-- Add more timezones as needed -->
            </select>
            <input type="password" id="password" name="password" placeholder="Password" required>
            <input type="password" id="verifyPassword" name="verifyPassword" placeholder="Verify Password" required>
            <div id="passwordMessage" class="message">Password must be at least 8 characters long and include at least one uppercase letter, one lowercase letter, one number, and one special character.</div>
            <button type="submit" name="register">Register</button>
        </form>
        <?php if ($message): ?>
            <div class="message error">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        <?php if ($successMessage): ?>
            <div class="message success">
                <?php echo $successMessage; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
