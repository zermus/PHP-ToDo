<?php use App\Csrf; ?>
<div class="login-form">
    <h2>To Do Login Page</h2>
    <form action="<?= e(url('/login')) ?>" method="post">
        <?= Csrf::field() ?>
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <div class="remember-me">
            <input type="checkbox" name="rememberMe" id="rememberMe">
            <label for="rememberMe">Remember Me</label>
        </div>
        <button type="submit">Login</button>
    </form>
    <div class="forgot-password">
        <a href="<?= e(url('/forgot-password')) ?>" class="btn">Forgot Password?</a>
    </div>
    <div class="register">
        <a href="<?= e(url('/register')) ?>" class="btn">Register</a>
    </div>
    <?php if (!empty($error)): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>
</div>
