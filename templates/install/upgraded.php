<div class="container">
    <h2>Upgrade Complete</h2>
    <?php if (empty($applied)): ?>
        <p>The database was already up to date.</p>
    <?php else: ?>
        <p>Applied migrations:</p>
        <ul style="list-style: none; padding: 0;">
            <?php foreach ($applied as $item): ?>
                <li class="success"><?= e($item) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (!empty($removed)): ?>
        <p>Removed <?= count($removed) ?> leftover 0.96 file(s).</p>
    <?php endif; ?>
    <?php if (!empty($failed)): ?>
        <p class="message error">Could not remove: <?= e(implode(', ', $failed)) ?>.
           Please delete them manually.</p>
    <?php endif; ?>

    <p>Note: remember-me cookies from 0.96 are no longer valid — users simply log in again once.</p>
    <a href="<?= e(url('/login')) ?>" class="btn">Go to Login</a>
</div>
