<?php use App\Csrf; ?>
<div class="container">
    <h2>User Settings</h2>
    <form method="post" action="<?= e(url('/settings')) ?>">
        <?= Csrf::field() ?>
        <label for="email">Email:</label>
        <input type="email" name="email" id="email" placeholder="Email" value="<?= e($user['email']) ?>" required>

        <label for="timezone">Timezone:</label>
        <select name="timezone" id="timezone">
            <?= timezone_options($user['timezone']) ?>
        </select>

        <label for="currentPassword">Current Password (for password change only):</label>
        <input type="password" name="currentPassword" id="currentPassword" placeholder="Current Password" autocomplete="current-password">

        <label for="password">New Password:</label>
        <input type="password" name="newPassword" id="password" placeholder="New Password" autocomplete="new-password">
        <input type="password" id="verifyPassword" placeholder="Verify New Password" autocomplete="new-password">
        <div id="passwordMessage" class="password-requirements"></div>

        <h3>Task Urgency Settings</h3>
        <div class="urgency-setting">
            <div class="task-urgency-green">
                <label for="urgency_green">Green Urgency (More than):</label>
                <select name="urgency_green" id="urgency_green">
                    <?php foreach ($greenChoices as $minutes): ?>
                    <option value="<?= $minutes ?>" <?= (int) $user['urgency_green'] === $minutes ? 'selected' : '' ?>><?= e(urgency_label($minutes)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="task-urgency-soon">Soon Urgency (Between Green and Critical)</div>
            <div class="task-urgency-critical">
                <label for="urgency_critical">Critical Urgency (Less than):</label>
                <select name="urgency_critical" id="urgency_critical">
                    <?php foreach ($criticalChoices as $minutes): ?>
                    <option value="<?= $minutes ?>" <?= (int) $user['urgency_critical'] === $minutes ? 'selected' : '' ?>><?= e(urgency_label($minutes)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="submit">Update Settings</button>
    </form>

    <?php foreach ($errors as $error): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endforeach; ?>
    <?php foreach ($successes as $success): ?>
        <div class="message success"><?= e($success) ?></div>
    <?php endforeach; ?>

    <div style="margin-top: 20px;">
        <a href="<?= e(url('/tasks')) ?>" class="btn">Back</a>
    </div>
</div>
