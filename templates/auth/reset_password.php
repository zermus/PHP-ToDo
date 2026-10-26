<?php use App\Csrf; ?>
<div class="container">
    <h2>Reset Password</h2>
    <form action="<?= e(url('/reset-password')) ?>" method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="password" id="password" name="password" placeholder="New Password" required>
        <input type="password" id="verifyPassword" name="confirmPassword" placeholder="Confirm New Password" required>
        <div id="passwordMessage" class="password-requirements">Password must be at least 8 characters long, at most 72 bytes, and include an uppercase letter, a lowercase letter, a number, and a non-alphanumeric character (spaces count).</div>
        <button type="submit">Reset Password</button>
    </form>
    <?php if (!empty($error)): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>
</div>
<script src="<?= e(asset('js/password.js')) ?>"></script>
