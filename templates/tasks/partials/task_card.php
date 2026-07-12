<?php

use App\Sanitizer;

$dueDateTime = new DateTime($task['due_date'], new DateTimeZone('UTC'));
$dueDateTime->setTimezone($userTimezone);
$now = new DateTime('now', $userTimezone);
$interval = $now->diff($dueDateTime);
$minutesToDue = (int) $interval->days * 1440 + (int) $interval->h * 60 + (int) $interval->i;

$taskClass = 'task-item ' . task_urgency_class(
    (bool) $task['completed'],
    (bool) $interval->invert,
    $minutesToDue,
    $urgencyGreen,
    $urgencyCritical
);
?>
<li id="task-<?= (int) $task['id'] ?>" class="<?= e($taskClass) ?>">
    <div class="task-summary">
        <?= e($task['summary']) ?>
        <br>
        <span class="due-date">Due on <?= e($dueDateTime->format('m-d-Y h:i A')) ?></span>
    </div>

    <?php if (!empty($task['details'])): ?>
    <div class="task-details">
        <?= Sanitizer::html($task['details']) ?>
    </div>
    <?php endif; ?>

    <a href="<?= e(url('/tasks/edit?id=' . (int) $task['id'])) ?>" class="edit-link">Edit</a>
    <button type="button" class="complete-task" data-task-id="<?= (int) $task['id'] ?>">
        <?= $task['completed'] ? 'Uncomplete Task' : 'Complete Task' ?>
    </button>

    <?php if (!empty($task['checklist_items'])): ?>
    <ul>
        <?php foreach ($task['checklist_items'] as $item): ?>
        <li id="item-<?= (int) $item['id'] ?>" class="<?= $item['completed'] ? 'completed' : '' ?>">
            <?php if (filter_var($item['content'], FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $item['content'])): ?>
                <a href="<?= e($item['content']) ?>" target="_blank" rel="noopener noreferrer"><?= e($item['content']) ?></a>
            <?php else: ?>
                <?= e($item['content']) ?>
            <?php endif; ?>
            <button type="button" class="complete-checklist-item" data-item-id="<?= (int) $item['id'] ?>">
                <?= $item['completed'] ? 'Uncomplete' : 'Complete' ?>
            </button>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</li>
