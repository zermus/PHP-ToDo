<?php
session_start();
require 'config.php';

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

if (!isset($_SESSION['user_id']) && !isset($_COOKIE['rememberMe'])) {
    header('Location: login.php');
    exit();
}

$errorMessage = '';
$successMessage = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $settingsStmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'user_registration'");
    $settingsStmt->execute();
    $registrationEnabled = $settingsStmt->fetchColumn() === '1';

    $userStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userRole = $userStmt->fetchColumn();

    if ($userRole !== 'admin' && $userRole !== 'super_admin') {
        echo "Access denied. You must be an admin or super admin to view this page.";
        exit();
    }

    $usersStmt = $pdo->prepare("SELECT id, name, username, role, timezone FROM users");
    $usersStmt->execute();
    $users = $usersStmt->fetchAll();

    $isSuperAdmin = $userRole === 'super_admin';
    $exclusionQuery = $isSuperAdmin ? "" : "WHERE id NOT IN (SELECT group_id FROM group_memberships gm INNER JOIN users u ON gm.user_id = u.id WHERE u.role = 'super_admin
')";
    $groupsStmt = $pdo->prepare("SELECT id, name FROM user_groups $exclusionQuery");
    $groupsStmt->execute();
    $groups = $groupsStmt->fetchAll();

    $membershipQuery = $pdo->prepare("SELECT g.id, g.name FROM user_groups g INNER JOIN group_memberships m ON g.id = m.group_id WHERE m.user_id = ?");

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'toggle_registration':
                $newStatus = isset($_POST['registration_status']) ? '1' : '0';
                $updateStmt = $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'user_registration'");
                $updateStmt->execute([$newStatus]);
                header('Location: manage_users.php');
                exit();
                break;
            case 'make_admin':
            case 'demote_user':
            case 'delete_user':
            case 'update_timezone':
                handleUserActions($pdo, $_POST);
                $usersStmt->execute();
                $users = $usersStmt->fetchAll();
                break;
            case 'assign_user':
                if (isset($_POST['user_id'], $_POST['group_id'])) {
                    $assignStmt = $pdo->prepare("INSERT INTO group_memberships (user_id, group_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE group_id = VALUES(group_id)");
                    $assignStmt->execute([$_POST['user_id'], $_POST['group_id']]);
                    $successMessage = "User assigned to group successfully.";
                }
                break;
            case 'remove_from_group':
                if (isset($_POST['user_id'], $_POST['group_id'])) {
                    $removeStmt = $pdo->prepare("DELETE FROM group_memberships WHERE user_id = ? AND group_id = ?");
                    $removeStmt->execute([$_POST['user_id'], $_POST['group_id']]);
                    $successMessage = "User removed from group successfully.";
                }
                break;
            case 'delete_group':
                $response = handleGroupDeletion($pdo, $_POST['group_id'], $groupsStmt);
                if (isset($response['error'])) {
                    $errorMessage = $response['error'];
                }
                if (isset($response['success'])) {
                    $successMessage = $response['success'];
                }
                break;
            case 'create_group':
                $groupName = filter_input(INPUT_POST, 'group_name', FILTER_SANITIZE_STRING);
                if (!empty($groupName)) {
                    $createGroupStmt = $pdo->prepare("INSERT INTO user_groups (name) VALUES (?)");
                    $createGroupStmt->execute([$groupName]);
                    $successMessage = "Group created successfully.";
                    $groupsStmt->execute();
                    $groups = $groupsStmt->fetchAll();
                }
                break;
        }
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

function handleUserActions($pdo, $postData) {
    $userId = $postData['user_id'] ?? null;
    if (!$userId) return;

    $targetUserRoleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $targetUserRoleStmt->execute([$userId]);
    $targetUserRole = $targetUserRoleStmt->fetchColumn();

    if ($GLOBALS['userRole'] === 'admin' && $targetUserRole === 'super_admin') {
        return;
    }

    switch ($postData['action']) {
        case 'make_admin':
            $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$userId]);
            break;
        case 'demote_user':
            $pdo->prepare("UPDATE users SET role = 'user' WHERE id = ?")->execute([$userId]);
            break;
        case 'delete_user':
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            break;
        case 'update_timezone':
            $newTimezone = $postData['timezone'];
            if (array_key_exists($newTimezone, $GLOBALS['timezones'])) {
                $pdo->prepare("UPDATE users SET timezone = ? WHERE id = ?")->execute([$newTimezone, $userId]);
            }
            break;
    }
}

function handleGroupDeletion($pdo, $groupId, $groupsStmt) {
    $errorMessage = '';

    $memberCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM group_memberships WHERE group_id = ?");
    $memberCheckStmt->execute([$groupId]);
    if ($memberCheckStmt->fetchColumn() > 0) {
        return ["error" => "Cannot delete group: There are users in the group."];
    }

    $taskCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE group_id = ?");
    $taskCheckStmt->execute([$groupId]);
    if ($taskCheckStmt->fetchColumn() > 0) {
        return ["error" => "Cannot delete group: There are tasks associated with the group."];
    }

    $deleteGroupStmt = $pdo->prepare("DELETE FROM user_groups WHERE id = ?");
    $deleteGroupStmt->execute([$groupId]);

    $groupsStmt->execute();
    $GLOBALS['groups'] = $groupsStmt->fetchAll();

    return ["success" => "Group deleted successfully."];
}

function groupHasSuperAdmin($pdo, $groupId) {
    $query = $pdo->prepare("SELECT COUNT(*) FROM group_memberships gm INNER JOIN users u ON gm.user_id = u.id WHERE u.role = 'super_admin' AND gm.group_id = ?");
    $query->execute([$groupId]);
    return $query->fetchColumn() > 0;
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
        <form action="manage_users.php" method="post">
            User Registration:
            <input type="hidden" name="action" value="toggle_registration">
            <input type="checkbox" name="registration_status" <?php echo $registrationEnabled ? 'checked' : ''; ?> onchange="this.form.submit()">
        </form>

        <form action="manage_users.php" method="post">
            <input type="text" name="group_name" placeholder="Group Name" required>
            <input type="hidden" name="action" value="create_group">
            <button type="submit" class="btn">Create Group</button>
        </form>

        <!-- Display success message if there is one -->
        <?php if (!empty($successMessage)): ?>
            <div style="color: green;"><?php echo $successMessage; ?></div>
        <?php endif; ?>

        <form action="manage_users.php" method="post">
            <select name="user_id" required>
                <option value="">Select User</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="group_id" required>
                <option value="">Select Group</option>
                <?php foreach ($groups as $group): ?>
                    <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="action" value="assign_user">
            <button type="submit" class="btn">Assign to Group</button>
        </form>

        <!-- Form to delete a group -->
        <form action="manage_users.php" method="post">
            <select name="group_id" required>
                <option value="">Select Group to Delete</option>
                <?php foreach ($groups as $group): ?>
                    <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="action" value="delete_group">
            <button type="submit" class="btn">Delete Group</button>
        </form>

        <!-- Display error message if there is one -->
        <?php if (!empty($errorMessage)): ?>
            <div style="color: red;"><?php echo $errorMessage; ?></div>
        <?php endif; ?>

        <div class="user-list">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Timezone</th>
                        <th>Group</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['role']); ?></td>
                        <td>
                            <?php if ($isSuperAdmin || ($userRole === 'admin' && $user['role'] !== 'super_admin')): ?>
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
                            <?php else: ?>
                            <?php echo htmlspecialchars($user['timezone']); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $membershipQuery->execute([$user['id']]);
                            $userGroups = $membershipQuery->fetchAll();
                            foreach ($userGroups as $group) {
                                echo htmlspecialchars($group['name']) . "<br>";
                                if ($isSuperAdmin || $user['id'] == $_SESSION['user_id'] || ($userRole === 'admin' && $user['role'] !== 'super_admin' && !groupHasSuperAdm
in($pdo, $group['id']))) {
                                    echo "<form action='manage_users.php' method='post'>
                                        <input type='hidden' name='action' value='remove_from_group'>
                                        <input type='hidden' name='user_id' value='{$user['id']}'>
                                        <input type='hidden' name='group_id' value='{$group['id']}'>
                                        <button type='submit' class='btn'>Remove from {$group['name']}</button>
                                    </form>";
                                }
                            }
                            if (count($userGroups) === 0) echo 'None';
                            ?>
                        </td>
                        <td>
                            <?php if ($user['role'] != 'super_admin'): ?>
                                <?php if ($user['role'] == 'admin'): ?>
                                    <form action="manage_users.php" method="post">
                                        <input type="hidden" name="action" value="demote_user">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn">Demote to User</button>
                                    </form>
                                <?php else: ?>
                                    <form action="manage_users.php" method="post">
                                        <input type="hidden" name="action" value="make_admin">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn">Make Admin</button>
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
        </div>
        <div class="button-container" style="margin-top: 20px;">
            <a href="main.php" class="btn">Back</a>
        </div>
    </div>
</body>
</html>
