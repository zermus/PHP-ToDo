<?php use App\Csrf; ?>
<div class="container manage-users-container wide">
    <h1>Manage Users</h1>

    <form action="<?= e(url('/admin/users')) ?>" method="post" class="inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="toggle_registration">
        User Registration:
        <input type="checkbox" name="registration_status" <?= $registrationEnabled ? 'checked' : '' ?> onchange="this.form.submit()">
    </form>

    <form action="<?= e(url('/admin/users')) ?>" method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create_group">
        <input type="text" name="group_name" placeholder="Group Name" required>
        <button type="submit" class="btn">Create Group</button>
    </form>

    <form action="<?= e(url('/admin/users')) ?>" method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="assign_user">
        <select name="user_id" required>
            <option value="">Select User</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="group_id" required>
            <option value="">Select Group</option>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>"><?= e($group['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Assign to Group</button>
    </form>

    <form action="<?= e(url('/admin/users')) ?>" method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="delete_group">
        <select name="group_id" required>
            <option value="">Select Group to Delete</option>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>"><?= e($group['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Delete Group</button>
    </form>

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
                <?php foreach ($users as $u): ?>
                <?php $canManageUser = $isSuperAdmin || $u['role'] !== 'super_admin'; ?>
                <tr>
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['username']) ?></td>
                    <td><?= e($u['role']) ?></td>
                    <td>
                        <?php if ($canManageUser): ?>
                        <form action="<?= e(url('/admin/users')) ?>" method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="update_timezone">
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <select name="timezone">
                                <?= timezone_options($u['timezone']) ?>
                            </select>
                            <button type="submit" class="btn">Update Timezone</button>
                        </form>
                        <?php else: ?>
                            <?= e($u['timezone'] ?? '') ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $memberOf = $userGroups[(int) $u['id']] ?? []; ?>
                        <?php if (empty($memberOf)): ?>
                            None
                        <?php else: ?>
                            <?php foreach ($memberOf as $group): ?>
                                <?= e($group['name']) ?><br>
                                <?php
                                $canRemove = $isSuperAdmin
                                    || (int) $u['id'] === (int) $currentUser['id']
                                    || ($u['role'] !== 'super_admin'
                                        && !in_array((int) $group['id'], $superAdminGroups, true));
                                ?>
                                <?php if ($canRemove): ?>
                                <form action="<?= e(url('/admin/users')) ?>" method="post" class="inline">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="remove_from_group">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                    <button type="submit" class="btn">Remove from <?= e($group['name']) ?></button>
                                </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['role'] !== 'super_admin'): ?>
                            <?php if ($u['role'] === 'admin'): ?>
                            <form action="<?= e(url('/admin/users')) ?>" method="post" class="inline">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="demote_user">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <button type="submit" class="btn">Demote to User</button>
                            </form>
                            <?php else: ?>
                            <form action="<?= e(url('/admin/users')) ?>" method="post" class="inline">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="make_admin">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <button type="submit" class="btn">Make Admin</button>
                            </form>
                            <?php endif; ?>
                            <form action="<?= e(url('/admin/users')) ?>" method="post" class="inline"
                                  onsubmit="return confirm('Delete this user and all of their tasks?');">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
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
        <a href="<?= e(url('/tasks')) ?>" class="btn">Back</a>
    </div>
</div>
