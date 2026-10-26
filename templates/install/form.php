<?php use App\Csrf; ?>
<div class="container">
    <h2>Install To-Do App</h2>
    <p>Welcome! This sets up the database and creates your administrator account.</p>
    <form method="post">
        <?= Csrf::field() ?>
        <label for="setup_key">Confirm setup key:</label>
        <input type="password" id="setup_key" name="setup_key" required autocomplete="off">
        <input type="hidden" name="action" value="install">
        <div>
            <label for="adminName">Admin Name:</label>
            <input type="text" id="adminName" name="adminName" required value="<?= e($old['adminName'] ?? '') ?>">
        </div>
        <div>
            <label for="adminUsername">Admin Username:</label>
            <input type="text" id="adminUsername" name="adminUsername" required value="<?= e($old['adminUsername'] ?? '') ?>">
        </div>
        <div>
            <label for="adminEmail">Admin Email:</label>
            <input type="email" id="adminEmail" name="adminEmail" required value="<?= e($old['adminEmail'] ?? '') ?>">
        </div>
        <div>
            <label for="adminTimezone">Admin Timezone:</label>
            <select id="adminTimezone" name="adminTimezone" required>
                <?= timezone_options($old['adminTimezone'] ?? null) ?>
            </select>
        </div>
        <div>
            <label for="password">Admin Password:</label>
            <input type="password" id="password" name="adminPassword" required>
            <div id="passwordMessage" class="password-requirements">Password must be at least 8 characters long, at most 72 bytes, and include an uppercase letter, a lowercase letter, a number, and a non-alphanumeric character (spaces count).</div>
        </div>
        <div>
            <label for="verifyPassword">Verify Password:</label>
            <input type="password" id="verifyPassword" name="verifyPassword" required>
        </div>
        <button type="submit">Install</button>
    </form>
    <?php if (!empty($error)): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>
</div>
<script src="<?= e(asset('js/password.js')) ?>"></script>
