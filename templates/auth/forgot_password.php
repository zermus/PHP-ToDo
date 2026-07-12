<?php use App\Csrf; ?>
<div class="container">
    <h2>Forgot Password</h2>
    <form action="<?= e(url('/forgot-password')) ?>" method="post">
        <?= Csrf::field() ?>
        <input type="email" name="email" placeholder="Email Address" required>
        <button type="submit">Reset Password</button>
    </form>
    <?php if (!empty($message)): ?>
        <div class="message <?= $isError ? 'error' : 'success' ?>"><?= e($message) ?></div>
    <?php endif; ?>
    <div class="back-to-login">
        <a href="<?= e(url('/login')) ?>" class="btn">Back to Login</a>
    </div>
</div>
