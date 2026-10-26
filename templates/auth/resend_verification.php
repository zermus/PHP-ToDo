<?php use App\Csrf; ?>
<div class="login-form">
    <h2>Send a new verification link</h2>
    <form action="<?= e(url('/resend-verification')) ?>" method="post">
        <?= Csrf::field() ?>
        <label for="identifier">Username or email</label>
        <input type="text" id="identifier" name="identifier" required>
        <button type="submit">Send a new verification link</button>
    </form>
    <?php if ($message !== null): ?>
        <div class="message"><?= e($message) ?></div>
    <?php endif; ?>
</div>
