<?php
session_start();
require 'config.php';

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
    $userStmt = $pdo->prepare("SELECT id, email, timezone, password FROM users WHERE remember_token = ?");
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
    $userStmt = $pdo->prepare("SELECT email, timezone, password, new_email_token FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $userDetails = $userStmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $newEmail = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $newTimezone = filter_input(INPUT_POST, 'timezone', FILTER_SANITIZE_STRING);
    $currentPassword = filter_input(INPUT_POST, 'currentPassword', FILTER_SANITIZE_STRING);
    $newPassword = filter_input(INPUT_POST, 'newPassword', FILTER_SANITIZE_STRING);

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

            if (!empty($userDetails['new_email_token'])) {
                $message = "Previous email update request was overwritten. ";
            }

            $subject = "Confirm Your Email Change";
            $emailMessage = "Hello,\n\nYou have requested to change your email address to $newEmail.\n\nPlease click th
e following link to confirm your email change:\n" . $base_url . "confirm_email_change.php?token=$verificationToken\n\nT
hank you!";
            $headers = "From: " . $from_email;
            mail($userDetails['email'], $subject, $emailMessage, $headers);

            $updateUserStmt = $pdo->prepare("UPDATE users SET new_email = ?, new_email_token = ?, new_email_token_expir
y = ? WHERE id = ?");
            $updateUserStmt->execute([$newEmail, $verificationToken, $tokenExpiry, $userId]);

            $successMessage .= "A confirmation email has been sent to your current email address ($userDetails[email]).
 Please confirm the change to update your email to $newEmail.";
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
            <label for="email">Email:</label>
            <input type="email" name="email" id="email" placeholder="Email" value="<?php echo htmlspecialchars($userDet
ails['email']); ?>">

            <label for="timezone">Timezone:</label>
            <select name="timezone" id="timezone">
                <!-- Timezone options from register.php -->
                <option value="America/New_York" <?php echo $userDetails['timezone'] == 'America/New_York' ? 'selected'
 : ''; ?>>Eastern Time (US & Canada)</option>
                <option value="America/Chicago" <?php echo $userDetails['timezone'] == 'America/Chicago' ? 'selected' :
 ''; ?>>Central Time (US & Canada)</option>
                <option value="America/Denver" <?php echo $userDetails['timezone'] == 'America/Denver' ? 'selected' : '
'; ?>>Mountain Time (US & Canada)</option>
                <option value="America/Los_Angeles" <?php echo $userDetails['timezone'] == 'America/Los_Angeles' ? 'sel
ected' : ''; ?>>Pacific Time (US & Canada)</option>
                <option value="America/Anchorage" <?php echo $userDetails['timezone'] == 'America/Anchorage' ? 'selecte
d' : ''; ?>>Alaska</option>
                <option value="America/Halifax" <?php echo $userDetails['timezone'] == 'America/Halifax' ? 'selected' :
 ''; ?>>Atlantic Time (Canada)</option>
                <option value="America/Buenos_Aires" <?php echo $userDetails['timezone'] == 'America/Buenos_Aires' ? 's
elected' : ''; ?>>Buenos Aires</option>
                <option value="America/Sao_Paulo" <?php echo $userDetails['timezone'] == 'America/Sao_Paulo' ? 'selecte
d' : ''; ?>>Sao Paulo</option>
                <option value="America/Lima" <?php echo $userDetails['timezone'] == 'America/Lima' ? 'selected' : ''; ?
>>Lima</option>
                <option value="Pacific/Honolulu" <?php echo $userDetails['timezone'] == 'Pacific/Honolulu' ? 'selected'
 : ''; ?>>Hawaii</option>
                <option value="Europe/London" <?php echo $userDetails['timezone'] == 'Europe/London' ? 'selected' : '';
 ?>>London</option>
                <option value="Europe/Berlin" <?php echo $userDetails['timezone'] == 'Europe/Berlin' ? 'selected' : '';
 ?>>Berlin, Frankfurt, Paris, Rome, Madrid</option>
                <option value="Europe/Athens" <?php echo $userDetails['timezone'] == 'Europe/Athens' ? 'selected' : '';
 ?>>Athens, Istanbul, Minsk</option>
                <option value="Europe/Moscow" <?php echo $userDetails['timezone'] == 'Europe/Moscow' ? 'selected' : '';
 ?>>Moscow, St. Petersburg, Volgograd</option>
            </select>

            <label for="currentPassword">Current Password (for password change only):</label>
            <input type="password" name="currentPassword" id="currentPassword" placeholder="Current Password">

            <label for="newPassword">New Password:</label>
            <input type="password" name="newPassword" id="newPassword" placeholder="New Password">
            <input type="password" id="verifyPassword" placeholder="Verify New Password">
            <div id="passwordMessage" class="message">Password requirements message</div>

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
