<?php
session_start();
require 'config.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables
$message = '';
$successMessage = '';

// Initialize PDO instance
$pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// User Authentication
if (!isset($_SESSION['user_id']) && !isset($_COOKIE['rememberMe'])) {
    header('Location: login.php');
    exit();
} elseif (!isset($_SESSION['user_id']) && isset($_COOKIE['rememberMe'])) {
    $rememberToken = $_COOKIE['rememberMe'];
    $userStmt = $pdo->prepare("SELECT id, email, timezone, password, urgency_green, urgency_critical FROM users WHERE remember_token = ?");
    $userStmt->execute([$rememberToken]);
    $userDetails = $userStmt->fetch();

    if ($userDetails) {
        $_SESSION['user_id'] = $userDetails['id'];
        $_SESSION['email'] = $userDetails['email'];
        $_SESSION['timezone'] = $userDetails['timezone'];
    } else {
        header('Location: login.php');
        exit();
    }
} else {
    $userId = $_SESSION['user_id'];
    $userStmt = $pdo->prepare("SELECT email, timezone, password, urgency_green, urgency_critical FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $userDetails = $userStmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['csrf_token'])) {
    if ($_SESSION['csrf_token'] !== $_POST['csrf_token']) {
        $message = "CSRF token mismatch.";
    } else {
        $newEmail = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
        $newTimezone = filter_input(INPUT_POST, 'timezone', FILTER_SANITIZE_STRING);
        $currentPassword = trim(filter_input(INPUT_POST, 'currentPassword', FILTER_SANITIZE_STRING));
        $newPassword = trim(filter_input(INPUT_POST, 'newPassword', FILTER_SANITIZE_STRING));
        $verifyPassword = trim(filter_input(INPUT_POST, 'verifyPassword', FILTER_SANITIZE_STRING));
        $urgencyGreen = filter_input(INPUT_POST, 'urgency_green', FILTER_SANITIZE_NUMBER_INT);
        $urgencyCritical = filter_input(INPUT_POST, 'urgency_critical', FILTER_SANITIZE_NUMBER_INT);

        if ($urgencyCritical >= $urgencyGreen) {
            $message = "Critical urgency must be less than Green urgency.";
        } else {
            // Update Timezone
            if (!empty($newTimezone) && $newTimezone !== $userDetails['timezone']) {
                $updateTimezoneStmt = $pdo->prepare("UPDATE users SET timezone = ? WHERE id = ?");
                $updateTimezoneStmt->execute([$newTimezone, $userId]);
                $successMessage = "Timezone updated successfully.";
            }

            // Update Email
            if (!empty($newEmail) && $newEmail !== $userDetails['email']) {
                if (filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    $verificationToken = bin2hex(random_bytes(16));
                    $tokenExpiry = (new DateTime())->add(new DateInterval('P1D'))->format('Y-m-d H:i:s');

                    $subject = "Confirm Your Email Change";
                    $emailMessage = "Hello,\n\nYou have requested to change your email address to $newEmail.\n\nPlease click the following link to confirm your email change:\n" . $base_url . "confirm_email_change.php?token=$verificationToken\n\nThank yo
u!";
                    $headers = "From: " . $from_email;
                    mail($userDetails['email'], $subject, $emailMessage, $headers);

                    $updateUserStmt = $pdo->prepare("UPDATE users SET new_email = ?, new_email_token = ?, new_email_token_expiry = ? WHERE id = ?");
                    $updateUserStmt->execute([$newEmail, $verificationToken, $tokenExpiry, $userId]);

                    $successMessage .= " A confirmation email has been sent to your current email address ($userDetails[email]). Please confirm the change to update your email to $newEmail.";
                } else {
                    $message = "Invalid email format.";
                }
            }

            // Update Password
            if (!empty($newPassword) && !empty($currentPassword)) {
                if (password_verify($currentPassword, $userDetails['password'])) {
                    if (preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/", $newPassword)) {
                        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $updatePasswordStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $updatePasswordStmt->execute([$newPasswordHash, $userId]);
                        $successMessage .= " Password updated successfully.";
                    } else {
                        $message .= " New password does not meet the requirements.";
                    }
                } else {
                    $message .= " Current password is incorrect.";
                }
            }

            // Update Urgency Settings
            if (empty($message)) {
                $updateUrgencyStmt = $pdo->prepare("UPDATE users SET urgency_green = ?, urgency_critical = ? WHERE id = ?");
                $updateUrgencyStmt->execute([$urgencyGreen, $urgencyCritical, $userId]);
                $successMessage .= " Urgency settings updated successfully.";

                // Re-fetch the updated user details to ensure the form reflects the current database state
                $userStmt = $pdo->prepare("SELECT email, timezone, password, urgency_green, urgency_critical FROM users WHERE id = ?");
                $userStmt->execute([$userId]);
                $userDetails = $userStmt->fetch();
            }
        } // Closing brace added here
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Settings</title>
    <link rel="stylesheet" href="stylesheet.css">
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var password = document.getElementById('newPassword');
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
</head>
<body>
    <div class="container">
        <h2>User Settings</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <label for="email">Email:</label>
            <input type="email" name="email" id="email" placeholder="Email" value="<?php echo htmlspecialchars($userDetails['email']); ?>" required>

            <label for="timezone">Timezone:</label>
            <select name="timezone" id="timezone">
                <!-- Timezone options -->
                <option value="America/New_York" <?php echo $userDetails['timezone'] == 'America/New_York' ? 'selected' : ''; ?>>Eastern Time (US & Canada)</option>
                <option value="America/Chicago" <?php echo $userDetails['timezone'] == 'America/Chicago' ? 'selected' : ''; ?>>Central Time (US & Canada)</option>
                <option value="America/Denver" <?php echo $userDetails['timezone'] == 'America/Denver' ? 'selected' : ''; ?>>Mountain Time (US & Canada)</option>
                <option value="America/Los_Angeles" <?php echo $userDetails['timezone'] == 'America/Los_Angeles' ? 'selected' : ''; ?>>Pacific Time (US & Canada)</option>
                <option value="America/Anchorage" <?php echo $userDetails['timezone'] == 'America/Anchorage' ? 'selected' : ''; ?>>Alaska</option>
                <option value="America/Halifax" <?php echo $userDetails['timezone'] == 'America/Halifax' ? 'selected' : ''; ?>>Atlantic Time (Canada)</option>
                <option value="America/Buenos_Aires" <?php echo $userDetails['timezone'] == 'America/Buenos_Aires' ? 'selected' : ''; ?>>Buenos Aires</option>
                <option value="America/Sao_Paulo" <?php echo $userDetails['timezone'] == 'America/Sao_Paulo' ? 'selected' : ''; ?>>Sao Paulo</option>
                <option value="America/Lima" <?php echo $userDetails['timezone'] == 'America/Lima' ? 'selected' : ''; ?>>Lima</option>
                <option value="Pacific/Honolulu" <?php echo $userDetails['timezone'] == 'Pacific/Honolulu' ? 'selected' : ''; ?>>Hawaii</option>
                <option value="Europe/London" <?php echo $userDetails['timezone'] == 'Europe/London' ? 'selected' : ''; ?>>London</option>
                <option value="Europe/Berlin" <?php echo $userDetails['timezone'] == 'Europe/Berlin' ? 'selected' : ''; ?>>Berlin, Frankfurt, Paris, Rome, Madrid</option>
                <option value="Europe/Athens" <?php echo $userDetails['timezone'] == 'Europe/Athens' ? 'selected' : ''; ?>>Athens, Istanbul, Minsk</option>
                <option value="Europe/Moscow" <?php echo $userDetails['timezone'] == 'Europe/Moscow' ? 'selected' : ''; ?>>Moscow, St. Petersburg, Volgograd</option>
            </select>

            <label for="currentPassword">Current Password (for password change only):</label>
            <input type="password" name="currentPassword" id="currentPassword" placeholder="Current Password">

            <label for="newPassword">New Password:</label>
            <input type="password" name="newPassword" id="newPassword" placeholder="New Password">
            <input type="password" id="verifyPassword" placeholder="Verify New Password">
            <div id="passwordMessage" class="message">Password requirements message</div>

            <h3>Task Urgency Settings</h3>
            <div class="urgency-setting">
                <div class="task-urgency-green">
                    <label for="urgency_green">Green Urgency (More than):</label>
                    <select name="urgency_green" id="urgency_green">
                        <!-- Options for urgency_green -->
                        <option value="60" <?php echo $userDetails['urgency_green'] == 60 ? 'selected' : ''; ?>>1 hour</option>
                        <option value="120" <?php echo $userDetails['urgency_green'] == 120 ? 'selected' : ''; ?>>2 hours</option>
                        <option value="240" <?php echo $userDetails['urgency_green'] == 240 ? 'selected' : ''; ?>>4 hours</option>
                        <option value="480" <?php echo $userDetails['urgency_green'] == 480 ? 'selected' : ''; ?>>8 hours</option>
                        <option value="720" <?php echo $userDetails['urgency_green'] == 720 ? 'selected' : ''; ?>>12 hours</option>
                        <option value="1440" <?php echo $userDetails['urgency_green'] == 1440 ? 'selected' : ''; ?>>1 day</option>
                        <option value="2880" <?php echo $userDetails['urgency_green'] == 2880 ? 'selected' : ''; ?>>2 days</option>
                        <option value="4320" <?php echo $userDetails['urgency_green'] == 4320 ? 'selected' : ''; ?>>3 days</option>
                    </select>
                </div>
                <div class="task-urgency-soon">Soon Urgency (Between Green and Critical)</div>
                <div class="task-urgency-critical">
                    <label for="urgency_critical">Critical Urgency (Less than):</label>
                    <select name="urgency_critical" id="urgency_critical">
                        <!-- Options for urgency_critical -->
                        <option value="15" <?php echo $userDetails['urgency_critical'] == 15 ? 'selected' : ''; ?>>15 minutes</option>
                        <option value="30" <?php echo $userDetails['urgency_critical'] == 30 ? 'selected' : ''; ?>>30 minutes</option>
                        <option value="60" <?php echo $userDetails['urgency_critical'] == 60 ? 'selected' : ''; ?>>1 hour</option>
                        <option value="120" <?php echo $userDetails['urgency_critical'] == 120 ? 'selected' : ''; ?>>2 hours</option>
                        <option value="240" <?php echo $userDetails['urgency_critical'] == 240 ? 'selected' : ''; ?>>4 hours</option>
                        <option value="480" <?php echo $userDetails['urgency_critical'] == 480 ? 'selected' : ''; ?>>8 hours</option>
                        <option value="720" <?php echo $userDetails['urgency_critical'] == 720 ? 'selected' : ''; ?>>12 hours</option>
                        <option value="1440" <?php echo $userDetails['urgency_critical'] == 1440 ? 'selected' : ''; ?>>1 day</option>
                    </select>
                </div>
            </div>

            <button type="submit">Update Settings</button>
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

        <div style="margin-top: 20px;">
            <a href="main.php" class="btn">Back</a>
        </div>
    </div>
</body>
</html>
