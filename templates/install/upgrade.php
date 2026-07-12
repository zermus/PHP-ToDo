<?php use App\Csrf; ?>
<div class="container">
    <h2>Upgrade To-Do App</h2>
    <p>An existing installation was detected (schema v<?= e((string) $fromVersion) ?>).</p>
    <p>Upgrading to version <?= e($appVersion) ?> (schema v<?= e((string) $toVersion) ?>) will update the
       database in place. Your tasks, users, and groups are preserved.
       <strong>Back up your database first.</strong></p>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="upgrade">
        <div class="checkbox-row">
            <input type="checkbox" id="cleanup_legacy" name="cleanup_legacy" value="1" checked>
            <label for="cleanup_legacy">Remove leftover 0.96 files (old top-level .php pages)</label>
        </div>
        <button type="submit">Upgrade Database</button>
    </form>
</div>
