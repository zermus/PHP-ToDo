<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\View;
use PDO;

final class AdminController
{
    public function users(): void
    {
        $user = Auth::requireAdmin();
        $pdo = Database::pdo();

        $isSuperAdmin = $user['role'] === 'super_admin';

        $registration = $pdo->prepare("SELECT value FROM settings WHERE name = 'user_registration'");
        $registration->execute();

        $users = $pdo->query('SELECT id, name, username, role, timezone FROM users ORDER BY name')->fetchAll();

        $memberships = $pdo->prepare(
            'SELECT g.id, g.name FROM user_groups g
             INNER JOIN group_memberships m ON g.id = m.group_id
             WHERE m.user_id = ? ORDER BY g.name'
        );
        $userGroups = [];
        foreach ($users as $row) {
            $memberships->execute([(int) $row['id']]);
            $userGroups[(int) $row['id']] = $memberships->fetchAll();
        }

        echo View::render('admin/users', [
            'title'               => 'To Do Manage Users',
            'currentUser'         => $user,
            'isSuperAdmin'        => $isSuperAdmin,
            'registrationEnabled' => $registration->fetchColumn() === '1',
            'users'               => $users,
            'groups'              => $this->visibleGroups($pdo, $isSuperAdmin),
            'userGroups'          => $userGroups,
            'superAdminGroups'    => $this->superAdminGroupIds($pdo),
        ]);
    }

    public function handleActions(): void
    {
        $user = Auth::requireAdmin();
        Csrf::require();

        $pdo = Database::pdo();
        $isSuperAdmin = $user['role'] === 'super_admin';
        $action = input_string('action');

        switch ($action) {
            case 'toggle_registration':
                $newStatus = !empty($_POST['registration_status']) ? '1' : '0';
                $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'user_registration'")
                    ->execute([$newStatus]);
                flash('User registration ' . ($newStatus === '1' ? 'enabled' : 'disabled') . '.');
                break;

            case 'create_group':
                $groupName = input_string('group_name');
                if ($groupName !== '') {
                    $pdo->prepare('INSERT INTO user_groups (name) VALUES (?)')->execute([$groupName]);
                    flash('Group created successfully.');
                }
                break;

            case 'assign_user':
                $targetId = (int) ($_POST['user_id'] ?? 0);
                $groupId = (int) ($_POST['group_id'] ?? 0);
                if ($targetId > 0 && $groupId > 0
                    && $this->canManageGroup($pdo, $isSuperAdmin, $groupId)
                    && ($isSuperAdmin || !$this->isSuperAdminUser($pdo, $targetId))
                ) {
                    $pdo->prepare('INSERT INTO group_memberships (user_id, group_id) VALUES (?, ?)
                                   ON DUPLICATE KEY UPDATE group_id = VALUES(group_id)')
                        ->execute([$targetId, $groupId]);
                    flash('User assigned to group successfully.');
                } else {
                    flash('Could not assign user to that group.', 'error');
                }
                break;

            case 'remove_from_group':
                $targetId = (int) ($_POST['user_id'] ?? 0);
                $groupId = (int) ($_POST['group_id'] ?? 0);
                $allowed = $targetId === (int) $user['id']
                    || $this->canManageGroup($pdo, $isSuperAdmin, $groupId);
                if ($targetId > 0 && $groupId > 0 && $allowed) {
                    $pdo->prepare('DELETE FROM group_memberships WHERE user_id = ? AND group_id = ?')
                        ->execute([$targetId, $groupId]);
                    flash('User removed from group successfully.');
                } else {
                    flash('Could not remove user from that group.', 'error');
                }
                break;

            case 'delete_group':
                $this->deleteGroup($pdo, $isSuperAdmin, (int) ($_POST['group_id'] ?? 0));
                break;

            case 'make_admin':
            case 'demote_user':
            case 'delete_user':
            case 'update_timezone':
                $this->userAction($pdo, $user, $action);
                break;
        }

        redirect('/admin/users');
    }

    // ---------------------------------------------------------------------

    /** @param array<string, mixed> $actor */
    private function userAction(PDO $pdo, array $actor, string $action): void
    {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if ($targetId <= 0) {
            return;
        }

        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $targetRole = $stmt->fetchColumn();

        if ($targetRole === false) {
            return;
        }

        // Nobody modifies a super_admin (except a super_admin fixing a timezone).
        if ($targetRole === 'super_admin'
            && !($action === 'update_timezone' && $actor['role'] === 'super_admin')
        ) {
            flash('Super admin accounts cannot be modified here.', 'error');

            return;
        }

        switch ($action) {
            case 'make_admin':
                $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$targetId]);
                flash('User promoted to admin.');
                break;
            case 'demote_user':
                $pdo->prepare("UPDATE users SET role = 'user' WHERE id = ?")->execute([$targetId]);
                flash('User demoted.');
                break;
            case 'delete_user':
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
                flash('User deleted.');
                break;
            case 'update_timezone':
                $timezone = input_string('timezone');
                if (valid_timezone($timezone)) {
                    $pdo->prepare('UPDATE users SET timezone = ? WHERE id = ?')
                        ->execute([$timezone, $targetId]);
                    flash('Timezone updated.');
                }
                break;
        }
    }

    private function deleteGroup(PDO $pdo, bool $isSuperAdmin, int $groupId): void
    {
        if ($groupId <= 0 || !$this->canManageGroup($pdo, $isSuperAdmin, $groupId)) {
            flash('Could not delete that group.', 'error');

            return;
        }

        $members = $pdo->prepare('SELECT COUNT(*) FROM group_memberships WHERE group_id = ?');
        $members->execute([$groupId]);
        if ((int) $members->fetchColumn() > 0) {
            flash('Cannot delete group: There are users in the group.', 'error');

            return;
        }

        $tasks = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE group_id = ?');
        $tasks->execute([$groupId]);
        if ((int) $tasks->fetchColumn() > 0) {
            flash('Cannot delete group: There are tasks associated with the group.', 'error');

            return;
        }

        $pdo->prepare('DELETE FROM user_groups WHERE id = ?')->execute([$groupId]);
        flash('Group deleted successfully.');
    }

    /**
     * Groups this admin may see/manage: all for super_admins, otherwise only
     * groups without a super_admin member.
     *
     * @return list<array<string, mixed>>
     */
    private function visibleGroups(PDO $pdo, bool $isSuperAdmin): array
    {
        if ($isSuperAdmin) {
            return $pdo->query('SELECT id, name FROM user_groups ORDER BY name')->fetchAll();
        }

        return $pdo->query(
            "SELECT id, name FROM user_groups
             WHERE id NOT IN (
                SELECT gm.group_id FROM group_memberships gm
                INNER JOIN users u ON gm.user_id = u.id
                WHERE u.role = 'super_admin'
             )
             ORDER BY name"
        )->fetchAll();
    }

    private function isSuperAdminUser(PDO $pdo, int $userId): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'super_admin'");
        $stmt->execute([$userId]);

        return $stmt->fetchColumn() !== false;
    }

    private function canManageGroup(PDO $pdo, bool $isSuperAdmin, int $groupId): bool
    {
        if ($isSuperAdmin) {
            return true;
        }

        return !in_array($groupId, $this->superAdminGroupIds($pdo), true);
    }

    /** @return list<int> */
    private function superAdminGroupIds(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT DISTINCT gm.group_id FROM group_memberships gm
             INNER JOIN users u ON gm.user_id = u.id
             WHERE u.role = 'super_admin'"
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $rows);
    }
}
