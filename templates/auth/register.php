<?php use App\Csrf; ?>
<div class="container">
    <h2>To Do Register</h2>
    <?php if ($registrationEnabled): ?>
        <form action="<?= e(url('/register')) ?>" method="post">
            <div style="display:none;">
                <input type="text" name="faxNumber" id="faxNumber" tabindex="-1" autocomplete="off">
            </div>
            <?= Csrf::field() ?>
            <input type="text" name="name" placeholder="Name" required value="<?= e($old['name'] ?? '') ?>">
            <input type="text" name="username" placeholder="Username" required value="<?= e($old['username'] ?? '') ?>">
            <input type="email" name="email" placeholder="Email" required value="<?= e($old['email'] ?? '') ?>">
            <select name="timezone" required>
                <option value="" disabled <?= empty($old['timezone']) ? 'selected' : '' ?>>Select Timezone</option>
                <?= timezone_options($old['timezone'] ?? null) ?>
            </select>
            <input type="password" id="password" name="password" placeholder="Password" required>
            <input type="password" id="verifyPassword" name="verifyPassword" placeholder="Verify Password" required>
            <div id="passwordMessage" class="password-requirements">Password must be at least 8 characters long and include at least one uppercase letter, one lowercase letter, one number, and one special character.</div>
            <button type="submit">Register</button>
        </form>
    <?php else: ?>
        <p class="message error">User Registration is closed at this time.</p>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="message success"><?= e($success) ?></div>
    <?php endif; ?>
    <div style="margin-top: 20px;">
        <a href="<?= e(url('/login')) ?>" class="btn">Back to Login</a>
    </div>
</div>
<script src="<?= e(asset('js/password.js')) ?>"></script>
