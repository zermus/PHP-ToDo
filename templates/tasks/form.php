<?php use App\Csrf; ?>
<div class="container">
    <h1><?= $task ? 'Edit Task' : 'Create New Task' ?></h1>
    <form action="<?= e($task ? url('/tasks/edit') : url('/tasks/create')) ?>" method="post" id="task-form">
        <?= Csrf::field() ?>
        <?php if ($task): ?>
            <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="taskName">Task Name:</label>
            <input type="text" id="taskName" name="taskName" required
                   value="<?= e($task['summary'] ?? '') ?>">
        </div>

        <div class="form-group checkbox-row">
            <input type="checkbox" id="isChecklist" name="isChecklist"
                   <?= !empty($task['checklist_items']) ? 'checked' : '' ?>>
            <label for="isChecklist">Is this a checklist?</label>
        </div>

        <div id="taskDetailsContainer" class="form-group">
            <label for="taskDetails">Task Details:</label>
            <div id="editor"></div>
            <textarea name="taskDetails" id="taskDetails" style="display:none;"></textarea>
        </div>

        <div id="checklistContainer" class="form-group checklist-editor" style="display:none;">
            <label>Checklist Items:</label>
            <div id="checklistItems">
                <?php foreach ($task['checklist_items'] ?? [] as $item): ?>
                <div class="checklist-row">
                    <input type="text" name="checklist[<?= (int) $item['id'] ?>]"
                           value="<?= e($item['content']) ?>"
                           <?= $item['completed'] ? 'class="completed"' : '' ?>>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="addChecklistItem">Add Checklist Item</button>
        </div>

        <div class="form-group">
            <label for="dueDate">Due Date:</label>
            <input type="date" id="dueDate" name="dueDate" required value="<?= e($localDueDate) ?>">
            <label for="dueTime">Time:</label>
            <input type="time" id="dueTime" name="dueTime" required value="<?= e($localDueTime) ?>">
        </div>

        <div class="form-group">
            <label for="reminderPreference">Reminder Preference:</label>
            <select id="reminderPreference" name="reminderPreference">
                <option value="">None</option>
                <?php foreach (['15m' => '15 minutes', '30m' => '30 minutes', '1h' => '1 hour', '2h' => '2 hours', '4h' => '4 hours', '12h' => '12 hours', '24h' => '24 hours'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= ($task['reminder_preference'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?> before</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group checkbox-row">
            <input type="checkbox" id="receiveCompletionEmail" name="receiveCompletionEmail"
                   <?= !empty($task['receive_completion_email']) ? 'checked' : '' ?>>
            <label for="receiveCompletionEmail">Receive email upon task completion</label>
        </div>

        <?php if (!empty($groups)): ?>
        <div class="form-group">
            <label for="group_id">Assign to Group:</label>
            <select id="group_id" name="group_id">
                <option value="">None</option>
                <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>" <?= (int) ($task['group_id'] ?? 0) === (int) $group['id'] ? 'selected' : '' ?>><?= e($group['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php if ($task): ?>
        <div class="form-group checkbox-row">
            <input type="checkbox" id="completed" name="completed" <?= $task['completed'] ? 'checked' : '' ?>>
            <label for="completed">Completed</label>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn"><?= $task ? 'Update Task' : 'Create Task' ?></button>
        <a href="<?= e(url('/tasks')) ?>" class="btn">Cancel</a>
    </form>
    <?php if (!empty($error)): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>
</div>
<script>
    window.taskFormConfig = {
        details: <?= json_encode($task['details'] ?? '') ?>
    };
</script>
