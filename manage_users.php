<?php
session_start();
require 'config.php';

// Timezone options as extracted from install.php
$timezones = [
    "America/New_York" => "Eastern Time (US & Canada)",
    "America/Chicago" => "Central Time (US & Canada)",
    "America/Denver" => "Mountain Time (US & Canada)",
    "America/Los_Angeles" => "Pacific Time (US & Canada)",
    "America/Anchorage" => "Alaska",
    "America/Halifax" => "Atlantic Time (Canada)",
    "America/Buenos_Aires" => "Buenos Aires",
    "America/Sao_Paulo" => "Sao Paulo",
    "America/Lima" => "Lima",
    "Pacific/Honolulu" => "Hawaii",
    "Europe/London" => "London",
    "Europe/Berlin" => "Berlin, Frankfurt, Paris, Rome, Madrid",
    "Europe/Athens" => "Athens, Istanbul, Minsk",
    "Europe/Moscow" => "Moscow, St. Petersburg, Volgograd",
];

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id']) && !isset($_COOKIE['rememberMe'])) {
    header('Location: login.php');
    exit();
}

// Database connection
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Retrieve the registration setting at the beginning
    $settingsStmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'user_registration'");
    $settingsStmt->execute();
    $registrationEnabled = $settingsStmt->fetchColumn() === '1';

    // Check if the user is an admin or super admin
    $userStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userRole = $userStmt->fetchColumn();

    if ($userRole !== 'admin' && $userRole !== 'super_admin') {
        echo "Access denied. You must be an admin or super admin to view this page.";
        exit();
    }

    // Fetch all users with the 'is_super_admin' flag and their timezone
    $usersStmt = $pdo->prepare("SELECT id, name, username, role, timezone, (role = 'super_admin') as is_super_admin FROM users");
    $usersStmt->execute();
    $users = $usersStmt->fetchAll();

    // Handle POST requests for role changes, user deletion, or timezone updates
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'toggle_registration') {
            $newStatus = isset($_POST['registration_status']) ? '1' : '0';
            $updateStmt = $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'user_registration'");
            $updateStmt->execute([$newStatus]);
            header('Location: manage_users.php');
            exit();
        }

        $userId = $_POST['user_id'] ?? null;
        if ($userId) {
            // Fetch the role of the user to be affected
            $roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $roleStmt->execute([$userId]);
            $role = $roleStmt->fetchColumn();

            // Prevent action if the user is a super admin
            if ($role === 'super_admin') {
                echo "Action not allowed on super admin account.";
                exit();
            }

            if ($_POST['action'] === 'make_admin') {
                // Update user role to admin
                $updateStmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
                $updateStmt->execute([$userId]);
            } elseif ($_POST['action'] === 'demote_user') {
                // Update user role to user
                $updateStmt = $pdo->prepare("UPDATE users SET role = 'user' WHERE id = ?");
                $updateStmt->execute([$userId]);
            } elseif ($_POST['action'] === 'delete_user') {
                // Delete user from the database
                $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $deleteStmt->execute([$userId]);
            } elseif ($_POST['action'] === 'update_timezone') {
                // Update user timezone
                $newTimezone = $_POST['timezone'];
                if (array_key_exists($newTimezone, $timezones)) {
                    $updateTimezoneStmt = $pdo->prepare("UPDATE users SET timezone = ? WHERE id = ?");
                    $updateTimezoneStmt->execute([$newTimezone, $userId]);
                }
            }
        }

        // Redirect to prevent form resubmission
        header('Location: manage_users.php');
        exit();
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>To Do Manage Users</title>
    <link rel="stylesheet" href="stylesheet.css">
</head>
<body>
    <div class="container manage-users-container">
        <h1>Manage Users</h1>
        <!-- User Registration Toggle Form -->
        <form action="manage_users.php" method="post">
            User Registration:
            <input type="hidden" name="action" value="toggle_registration">
            <input type="checkbox" name="registration_status" <?php echo ($registrationEnabled ? 'checked' : ''); ?> onchange="this.form.submit()">
        </form>
        <div class="user-list">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Timezone</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo htmlspecialchars($user['name']); ?></td>
                    <td><?php echo htmlspecialchars($user['role']); ?></td>
                    <td>
                        <form action="manage_users.php" method="post">
                            <select name="timezone">
                                <?php foreach ($timezones as $tz => $name): ?>
                                <option value="<?php echo $tz; ?>" <?php if ($user['timezone'] == $tz) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="action" value="update_timezone">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <button type="submit" class="btn">Update Timezone</button>
                        </form>
                    </td>
                    <td>
                        <!-- Buttons for managing user roles and deletion, excluding super admin -->
                        <?php if (!$user['is_super_admin']): ?>
                            <?php if ($user['role'] === 'user'): ?>
                                <form action="manage_users.php" method="post">
                                    <input type="hidden" name="action" value="make_admin">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn">Make Admin</button>
                                </form>
                            <?php elseif ($userRole === 'super_admin'): ?>
                                <form action="manage_users.php" method="post">
                                    <input type="hidden" name="action" value="demote_user">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn">Demote to User</button>
                                </form>
                            <?php endif; ?>
                            <form action="manage_users.php" method="post">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                <button type="submit" class="btn">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="button-container" style="margin-top: 20px;">
                <a href="main.php" class="btn">Back</a>
            </div>
        </div>
    </div>
</body>
</html>
