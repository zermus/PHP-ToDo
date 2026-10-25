<?php use App\Csrf; ?>
<div class="container">
    <h2>Setup authorization</h2>
    <?php if (!$configured): ?>
    <p>Add a private random setup_key to config.php (or $setup_key in a legacy configuration), then reload.</p>
    <?php else: ?>
    <?php if ($rejected): ?><p>Setup key missing or incorrect.</p><?php endif; ?>
    <form method="post">
        <?= Csrf::field() ?>
        <label for="setup_key">Setup key:</label>
        <input type="password" name="setup_key" id="setup_key" required autocomplete="off">
        <button type="submit">Continue</button>
    </form>
    <?php endif; ?>
</div>
