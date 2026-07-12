<div class="container">
    <div class="message <?= !empty($success) ? 'success' : 'error' ?>">
        <p><?= e($message) ?></p>
    </div>
    <?php if (!empty($action)): ?>
        <a href="<?= e($action['href']) ?>" class="btn"><?= e($action['label']) ?></a>
    <?php endif; ?>
</div>
