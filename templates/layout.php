<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'To-Do App') ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php foreach ($styles ?? [] as $style): ?>
    <link rel="stylesheet" href="<?= e($style) ?>">
    <?php endforeach; ?>
</head>
<body>
    <?php foreach (flash_pull() as $msg): ?>
    <div class="flash message <?= e($msg['type']) ?>"><?= e($msg['message']) ?></div>
    <?php endforeach; ?>

    <?= $content ?>

    <?php foreach ($scripts ?? [] as $script): ?>
    <script src="<?= e($script) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
