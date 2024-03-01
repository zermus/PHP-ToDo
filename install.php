<?php

// Check if 'config.php' exists and is readable
if (!file_exists('config.php') || !is_readable('config.php')) {
    die("Error: 'config.php' file is missing or not readable. Please ensure it exists and has the correct permissions.");
}

require 'config.php';

// Check if the required configurations are set
if (empty($host) || empty($dbname) || empty($db_username) || empty($db_password) || empty($base_url) || empty($from_email)) {
    die("Error: 'config.php' is missing required configurations. Please ensure it is correctly updated.");
}

$message = '';
$installationSuccessful = false;

if (isset($_POST['install'])) {
    $adminName = filter_input(INPUT_POST, 'adminName', FILTER_SANITIZE_STRING);
    $adminUsername = filter_input(INPUT_POST, 'adminUsername', FILTER_SANITIZE_STRING);
    $adminEmail = filter_input(INPUT_POST, 'adminEmail', FILTER_SANITIZE_EMAIL);
    $adminTimezone = filter_input(INPUT_POST, 'adminTimezone', FILTER_SANITIZE_STRING);
    $adminPassword = $_POST['adminPassword'];
    $verifyPassword = $_POST['verifyPassword'];
    $installationPath = filter_input(INPUT_POST, 'installPath', FILTER_SANITIZE_STRING);

    // Ensure $base_url ends with a slash
    $base_url = rtrim($base_url, '/') . '/';

    if ($adminPassword !== $verifyPassword) {
        $message = "The passwords do not match. Please try again.";
    } elseif (!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/", $adminPassword)) {
        $message = "Password must be at least 8 characters long and include at least one uppercase letter, one lowercase letter, one number, and one
special character.";
    } else {
        try {
            $pdo = new PDO("mysql:host=$host", $db_username, $db_password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Create the database if it doesn't exist
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8 COLLATE utf8_general_ci");
            $pdo->exec("USE `$dbname`");

            // Create the settings table
            $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                value TEXT NOT NULL
            ) CHARACTER SET utf8 COLLATE utf8_general_ci");

            // Create the users table
            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                username VARCHAR(255) NOT NULL UNIQUE,
                email VARCHAR(255) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                role ENUM('super_admin', 'admin', 'user') DEFAULT 'user',
                email_verified BOOLEAN DEFAULT FALSE,
                verification_token VARCHAR(255) DEFAULT NULL,
                remember_token VARCHAR(255) DEFAULT NULL,
                reset_token VARCHAR(255) DEFAULT NULL,
                reset_token_expiry DATETIME DEFAULT NULL,
                timezone VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) CHARACTER SET utf8 COLLATE utf8_general_ci");

            // Create the tasks table
            $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                summary TEXT NOT NULL,
                details TEXT DEFAULT NULL,
                due_date DATETIME NOT NULL,
                reminder_preference ENUM('15m', '30m', '1h', '2h', '4h', '12h', '24h') DEFAULT NULL,
                reminder_sent BOOLEAN DEFAULT FALSE,
                completed BOOLEAN DEFAULT FALSE,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) CHARACTER SET utf8 COLLATE utf8_general_ci");

            // Create the checklist_items table
            $pdo->exec("CREATE TABLE IF NOT EXISTS checklist_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                content VARCHAR(255) NOT NULL,
                completed BOOLEAN DEFAULT FALSE,
                FOREIGN KEY (task_id) REFERENCES tasks(id)
            ) CHARACTER SET utf8 COLLATE utf8_general_ci");

            // Insert the admin user into the users table
            $verificationToken = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("INSERT INTO users (name, username, email, password, role, verification_token, timezone) VALUES (?, ?, ?, ?, 'super
_admin', ?, ?)");

            $stmt->execute([$adminName, $adminUsername, $adminEmail, password_hash($adminPassword, PASSWORD_DEFAULT), $verificationToken, $adminTimez
one]);

            // After creating tables in install.php, insert a default setting for user registration
            $pdo->exec("INSERT INTO settings (name, value) VALUES ('user_registration', '1')");

            // Send the verification email to the admin
            $verificationLink = $base_url . "verify.php?token=" . $verificationToken;
            $subject = "Verify Your Email";
            $emailMessage = "Hello $adminName,\n\nPlease click the following link to verify your email and activate your admin account:\n$verificatio
nLink\n\nThank you!";
            $headers = "From: " . $from_email;
            if (mail($adminEmail, $subject, $emailMessage, $headers)) {
                $message = "Installation completed successfully! Please check your email to verify your account.<br><br>" .
                    "<strong>Post-Installation Steps:</strong><br>" .
                    "1. <strong>Verify your email</strong> by clicking the link sent to your email address.<br>" .
                    "2. <strong>Delete the 'install.php' file</strong> from your server for security purposes.<br>" .
                    "3. <strong>Set up a cron job</strong> to run 'send_reminders.php' every minute. You can do this by adding the following line to
your crontab:<br>" .
                    "<code>* * * * * apache /usr/bin/php " . htmlspecialchars($installationPath) . "send_reminders.php</code><br>" .
                    "Be sure to replace apache with whatever user your webserver runs as.";
                $installationSuccessful = true;
            } else {
                $message = "Installation completed, but the verification email could not be sent. Please check your server's email settings.";
            }
        } catch (PDOException $e) {
            $message = "Installation failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install To-Do App</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #121212;
            color: #c0c0c0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .container {
            background-color: #222831;
            padding: 30px;
            border-radius: 8px;
            width: 100%;
            max-width: 600px;
            box-sizing: border-box;
            text-align: center;
            color: #00ddff;
            margin: auto;
        }
        input[type="text"], input[type="email"], input[type="password"] {
            width: 90%;
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
            border: 1px solid #00ddff;
            background-color: #333;
            color: #ffffff;
            display: block;
            margin: 15px auto;
        }
        button {
            background-color: #4CAF50;
            color: #ffffff;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            display: block;
            margin: 20px auto;
        }
        .password-requirements {
            color: #FFA500;
            font-size: 0.9em;
            margin: 10px 0;
        }
        .message {
            margin-top: 20px;
            color: #FF6347;
        }
        .success {
            color: #00FF00;
        }
        button:hover {
        background-color: #367c2b; /* Darker green for hover */
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var adminPassword = document.getElementById('adminPassword');
            var verifyPassword = document.getElementById('verifyPassword');
            var message = document.getElementById('passwordMessage');

            function validatePassword() {
                var passwordValue = adminPassword.value;
                var passwordRequirements = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/;
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

                if (adminPassword.value === verifyPassword.value && adminPassword.value.length > 0) {
                    message.innerHTML += "<br>Passwords match.";
                    message.style.color = "#00FF00";
                } else if (verifyPassword.value.length > 0) {
                    message.innerHTML += "<br>Passwords do not match.";
                    message.style.color = "#FF6347";
                }
            }

            adminPassword.addEventListener('input', validatePassword);
            verifyPassword.addEventListener('input', validatePassword);
        });
    </script>
</head>
<body>
    <div class="container">
        <h2>Install To-Do App</h2>
        <form method="post">
            <div>
                <label for="adminName">Admin Name:</label>
                <input type="text" id="adminName" name="adminName" required>
            </div>
            <div>
                <label for="adminUsername">Admin Username:</label>
                <input type="text" id="adminUsername" name="adminUsername" required>
            </div>
            <div>
                <label for="adminEmail">Admin Email:</label>
                <input type="email" id="adminEmail" name="adminEmail" required>
            </div>
            <div>
                <label for="adminTimezone">Admin Timezone:</label>
                <select id="adminTimezone" name="adminTimezone" required>
                <!-- North America -->
                <option value="America/New_York">Eastern Time (US & Canada)</option>
                <option value="America/Chicago">Central Time (US & Canada)</option>
                <option value="America/Denver">Mountain Time (US & Canada)</option>
                <option value="America/Los_Angeles">Pacific Time (US & Canada)</option>
                <option value="America/Anchorage">Alaska</option>
                <option value="America/Halifax">Atlantic Time (Canada)</option>

                <!-- South America -->
                <option value="America/Buenos_Aires">Buenos Aires</option>
                <option value="America/Sao_Paulo">Sao Paulo</option>
                <option value="America/Lima">Lima</option>

                <!-- Hawaii -->
                <option value="Pacific/Honolulu">Hawaii</option>

                <!-- Europe -->
                <option value="Europe/London">London</option>
                <option value="Europe/Berlin">Berlin, Frankfurt, Paris, Rome, Madrid</option>
                <option value="Europe/Athens">Athens, Istanbul, Minsk</option>
                <option value="Europe/Moscow">Moscow, St. Petersburg, Volgograd</option>
                </select>
            </div>
            <div>
                <label for="adminPassword">Admin Password:</label>
                <input type="password" id="adminPassword" name="adminPassword" required>
                <div id="passwordMessage" class="password-requirements">Password must be at least 8 characters long and include at least one uppercas
e letter, one lowercase letter, one
 number, and one special character.</div>
            </div>
            <div>
                <label for="verifyPassword">Verify Password:</label>
                <input type="password" id="verifyPassword" name="verifyPassword" required>
            </div>
            <div>
                <label for="installPath">Installation Path:</label>
                <input type="text" id="installPath" name="installPath" required placeholder="e.g., /path/to/app/">
            </div>
            <button type="submit" name="install">Install</button>
        </form>
        <?php if ($message): ?>
            <div class="message <?php echo $installationSuccessful ? 'success' : 'error'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
