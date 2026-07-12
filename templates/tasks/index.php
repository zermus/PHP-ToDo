<?php use App\Csrf; use App\View; ?>
<div class="container" id="task-page" data-csrf="<?= e(Csrf::token()) ?>"
     data-complete-url="<?= e(url('/tasks/complete')) ?>"
     data-checklist-url="<?= e(url('/checklist/complete')) ?>">
    <h1>Welcome, <?= e($user['name']) ?>!</h1>
    <div class="button-container">
        <a href="<?= e(url('/tasks/create')) ?>" class="btn new-task">Create New Task</a>
        <a href="<?= e(url('/settings')) ?>" class="btn">User Settings</a>
        <?php if ($isAdmin): ?>
        <a href="<?= e(url('/admin/users')) ?>" class="btn manage-users">Manage Users</a>
        <?php endif; ?>
    </div>

    <!-- Personal Tasks -->
    <div class="task-container">
        <h2>Your Tasks</h2>
        <?php if (empty($personalTasks)): ?>
        <p>No tasks available.</p>
        <?php else: ?>
        <ul class="task-list">
            <?php foreach ($personalTasks as $task): ?>
                <?= View::partial('tasks/partials/task_card', [
                    'task'            => $task,
                    'userTimezone'    => $userTimezone,
                    'urgencyGreen'    => $urgencyGreen,
                    'urgencyCritical' => $urgencyCritical,
                ]) ?>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- Group Tasks -->
    <?php foreach ($groupedTasks as $groupName => $tasks): ?>
    <div class="task-container">
        <h2><?= e($groupName) ?>'s Tasks</h2>
        <ul class="task-list">
            <?php foreach ($tasks as $task): ?>
                <?= View::partial('tasks/partials/task_card', [
                    'task'            => $task,
                    'userTimezone'    => $userTimezone,
                    'urgencyGreen'    => $urgencyGreen,
                    'urgencyCritical' => $urgencyCritical,
                ]) ?>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endforeach; ?>

    <div class="logout-container">
        <a href="<?= e(url('/calendar')) ?>" class="btn">Calendar</a>
        <form action="<?= e(url('/logout')) ?>" method="post" class="inline">
            <?= Csrf::field() ?>
            <button type="submit" class="btn">Logout</button>
        </form>
    </div>
</div>
