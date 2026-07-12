<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Sanitizer;
use App\View;
use DateTime;
use DateTimeZone;
use PDO;

final class TaskController
{
    private const REMINDER_PREFERENCES = ['15m', '30m', '1h', '2h', '4h', '12h', '24h'];

    public function index(): void
    {
        $user = Auth::requireLogin();
        $pdo = Database::pdo();
        $userId = (int) $user['id'];

        $groupStmt = $pdo->prepare(
            'SELECT t.*, g.name AS group_name FROM tasks t
             INNER JOIN group_memberships gm ON t.group_id = gm.group_id
             INNER JOIN user_groups g ON gm.group_id = g.id
             WHERE gm.user_id = ? AND t.completed = FALSE
             ORDER BY t.due_date ASC'
        );
        $groupStmt->execute([$userId]);
        $groupTasks = $groupStmt->fetchAll();

        $groupTaskIds = [];
        $groupedTasks = [];
        foreach ($groupTasks as $task) {
            $task['checklist_items'] = $this->checklistItems($pdo, (int) $task['id']);
            $groupedTasks[$task['group_name']][] = $task;
            $groupTaskIds[] = (int) $task['id'];
        }

        $sql = 'SELECT * FROM tasks WHERE user_id = ? AND completed = FALSE';
        $params = [$userId];
        if ($groupTaskIds !== []) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($groupTaskIds), '?')) . ')';
            $params = array_merge($params, $groupTaskIds);
        }
        $sql .= ' ORDER BY due_date ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $personalTasks = [];
        foreach ($stmt->fetchAll() as $task) {
            $task['checklist_items'] = $this->checklistItems($pdo, (int) $task['id']);
            $personalTasks[] = $task;
        }

        echo View::render('tasks/index', [
            'title'           => 'To Do Main Page',
            'user'            => $user,
            'isAdmin'         => Auth::isAdmin($user),
            'personalTasks'   => $personalTasks,
            'groupedTasks'    => $groupedTasks,
            'userTimezone'    => new DateTimeZone($user['timezone'] ?? 'UTC'),
            'urgencyGreen'    => (int) $user['urgency_green'],
            'urgencyCritical' => (int) $user['urgency_critical'],
            'scripts'         => [asset('js/tasks.js')],
        ]);
    }

    public function createForm(): void
    {
        $user = Auth::requireLogin();

        $this->renderForm($user, null, null);
    }

    public function create(): void
    {
        $user = Auth::requireLogin();
        Csrf::require();

        $pdo = Database::pdo();
        $input = $this->validateTaskInput($user, $pdo);

        if (is_string($input)) {
            $this->renderForm($user, null, $input);

            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO tasks (user_id, group_id, summary, due_date, reminder_preference,
                                details, receive_completion_email)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $user['id'],
            $input['group_id'],
            $input['summary'],
            $input['due_date_utc'],
            $input['reminder_preference'],
            $input['details'],
            $input['receive_completion_email'],
        ]);

        $taskId = (int) $pdo->lastInsertId();

        if ($input['is_checklist']) {
            $insert = $pdo->prepare('INSERT INTO checklist_items (task_id, content) VALUES (?, ?)');
            foreach ($input['new_checklist_items'] as $content) {
                $insert->execute([$taskId, $content]);
            }
        }

        redirect('/tasks');
    }

    public function editForm(): void
    {
        $user = Auth::requireLogin();
        $pdo = Database::pdo();

        $task = $this->authorizedTask($pdo, $user, (int) ($_GET['id'] ?? 0));
        if ($task === null) {
            $this->taskNotFound();

            return;
        }

        $this->renderForm($user, $task, null);
    }

    public function update(): void
    {
        $user = Auth::requireLogin();
        Csrf::require();

        $pdo = Database::pdo();
        $task = $this->authorizedTask($pdo, $user, (int) ($_POST['id'] ?? 0));
        if ($task === null) {
            $this->taskNotFound();

            return;
        }

        $input = $this->validateTaskInput($user, $pdo);
        if (is_string($input)) {
            $this->renderForm($user, $task, $input);

            return;
        }

        $taskId = (int) $task['id'];
        $completed = !empty($_POST['completed']) ? 1 : 0;
        // Re-arm the reminder if the schedule changed (due date OR preference).
        $scheduleChanged = $input['due_date_utc'] !== $task['due_date']
            || $input['reminder_preference'] !== $task['reminder_preference'];

        $stmt = $pdo->prepare(
            'UPDATE tasks SET summary = ?, group_id = ?, due_date = ?, reminder_preference = ?,
                    completed = ?, details = ?, receive_completion_email = ?,
                    reminder_sent = ?, completion_email_sent = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $input['summary'],
            $input['group_id'],
            $input['due_date_utc'],
            $input['reminder_preference'],
            $completed,
            $input['details'],
            $input['receive_completion_email'],
            $scheduleChanged ? 0 : (int) $task['reminder_sent'],
            $completed ? (int) $task['completion_email_sent'] : 0,
            $taskId,
        ]);

        $this->syncChecklist($pdo, $taskId, $input);

        redirect('/tasks');
    }

    /**
     * AJAX: toggle task completion.
     */
    public function complete(): void
    {
        $user = Auth::requireLoginJson();
        Csrf::requireJson();

        $pdo = Database::pdo();
        $taskId = (int) ($_POST['task_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT user_id, group_id, completed FROM tasks WHERE id = ?');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            json_response(['success' => false, 'error' => 'Task not found.'], 404);
        }
        if (!$this->canAccessTask($pdo, (int) $user['id'], $task)) {
            json_response(['success' => false, 'error' => 'Not authorized to complete this task.'], 403);
        }

        $newState = $task['completed'] ? 0 : 1;
        $pdo->prepare('UPDATE tasks SET completed = ?, completion_email_sent = 0 WHERE id = ?')
            ->execute([$newState, $taskId]);

        json_response(['success' => true, 'newState' => $newState]);
    }

    /**
     * AJAX: toggle a checklist item.
     */
    public function completeChecklistItem(): void
    {
        $user = Auth::requireLoginJson();
        Csrf::requireJson();

        $pdo = Database::pdo();
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $isCompleted = !empty($_POST['is_completed']) ? 1 : 0;

        $verify = $pdo->prepare(
            'SELECT ci.id FROM checklist_items ci
             INNER JOIN tasks t ON ci.task_id = t.id
             LEFT JOIN group_memberships gm ON t.group_id = gm.group_id AND gm.user_id = ?
             WHERE ci.id = ? AND (t.user_id = ? OR gm.user_id IS NOT NULL)'
        );
        $verify->execute([(int) $user['id'], $itemId, (int) $user['id']]);

        if ($verify->fetch() === false) {
            json_response(['success' => false, 'error' => 'Invalid item ID or access denied.'], 403);
        }

        $pdo->prepare('UPDATE checklist_items SET completed = ? WHERE id = ?')
            ->execute([$isCompleted, $itemId]);

        json_response(['success' => true]);
    }

    public function calendar(): void
    {
        $user = Auth::requireLogin();
        $pdo = Database::pdo();

        $userTimezone = new DateTimeZone($user['timezone'] ?? 'UTC');
        $now = new DateTime('now', $userTimezone);

        $month = (int) ($_GET['month'] ?? $now->format('m'));
        $year = (int) ($_GET['year'] ?? $now->format('Y'));
        if ($month < 1 || $month > 12) {
            $month = (int) $now->format('m');
        }
        if ($year < 1970 || $year > 2100) {
            $year = (int) $now->format('Y');
        }

        $firstDayOfMonth = new DateTime(sprintf('%04d-%02d-01', $year, $month), $userTimezone);
        $startDayOfWeek = clone $firstDayOfMonth;
        if ((int) $firstDayOfMonth->format('w') !== 0) {
            $startDayOfWeek->modify('-' . $firstDayOfMonth->format('w') . ' days');
        }
        $lastDayOfMonth = (clone $firstDayOfMonth)->modify('last day of this month');
        $endDayOfWeek = clone $lastDayOfMonth;
        if ((int) $lastDayOfMonth->format('w') !== 6) {
            $endDayOfWeek->modify('+' . (6 - (int) $lastDayOfMonth->format('w')) . ' days');
        }

        $stmt = $pdo->prepare(
            'SELECT t.* FROM tasks t
             LEFT JOIN group_memberships gm ON t.group_id = gm.group_id
             WHERE (t.user_id = ? OR gm.user_id = ?) AND t.due_date BETWEEN ? AND ?
             GROUP BY t.id
             ORDER BY t.due_date ASC'
        );
        // due_date is stored in UTC; the grid boundaries are local wall-clock
        // days. Convert the local start-of-first-day and end-of-last-day to UTC
        // so tasks near the edges aren't dropped for non-UTC users.
        $rangeStartUtc = (clone $startDayOfWeek)->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'));
        $rangeEndUtc = (clone $endDayOfWeek)->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'));

        $stmt->execute([
            (int) $user['id'],
            (int) $user['id'],
            $rangeStartUtc->format('Y-m-d H:i:s'),
            $rangeEndUtc->format('Y-m-d H:i:s'),
        ]);

        $prev = (clone $firstDayOfMonth)->modify('-1 month');
        $next = (clone $firstDayOfMonth)->modify('+1 month');

        echo View::render('tasks/calendar', [
            'title'           => 'To Do Calendar',
            'tasks'           => $stmt->fetchAll(),
            'userTimezone'    => $userTimezone,
            'now'             => $now,
            'urgencyGreen'    => (int) $user['urgency_green'],
            'urgencyCritical' => (int) $user['urgency_critical'],
            'firstDayOfMonth' => $firstDayOfMonth,
            'lastDayOfMonth'  => $lastDayOfMonth,
            'startDayOfWeek'  => $startDayOfWeek,
            'endDayOfWeek'    => $endDayOfWeek,
            'monthLabel'      => $firstDayOfMonth->format('F Y'),
            'prevLink'        => url('/calendar?year=' . $prev->format('Y') . '&month=' . $prev->format('m')),
            'nextLink'        => url('/calendar?year=' . $next->format('Y') . '&month=' . $next->format('m')),
        ]);
    }

    // ---------------------------------------------------------------------

    private function taskNotFound(): void
    {
        http_response_code(404);
        echo View::render('error', [
            'title'   => 'Task Not Found',
            'heading' => 'Task Not Found',
            'message' => 'The task does not exist or you do not have permission to edit it.',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function checklistItems(PDO $pdo, int $taskId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM checklist_items WHERE task_id = ? ORDER BY id');
        $stmt->execute([$taskId]);

        return $stmt->fetchAll();
    }

    /**
     * Fetch a task if the user owns it or belongs to its group.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    private function authorizedTask(PDO $pdo, array $user, int $taskId): ?array
    {
        if ($taskId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task || !$this->canAccessTask($pdo, (int) $user['id'], $task)) {
            return null;
        }

        $task['checklist_items'] = $this->checklistItems($pdo, $taskId);

        return $task;
    }

    /** @param array<string, mixed> $task */
    private function canAccessTask(PDO $pdo, int $userId, array $task): bool
    {
        if ((int) $task['user_id'] === $userId) {
            return true;
        }

        return $task['group_id'] !== null
            && $this->inGroup($pdo, $userId, (int) $task['group_id']);
    }

    private function inGroup(PDO $pdo, int $userId, int $groupId): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM group_memberships WHERE user_id = ? AND group_id = ?');
        $stmt->execute([$userId, $groupId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<array{id: int, name: string}> */
    private function userGroups(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT g.id, g.name FROM user_groups g
             INNER JOIN group_memberships m ON g.id = m.group_id
             WHERE m.user_id = ? ORDER BY g.name'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * Validate and normalize the create/edit form input.
     * Returns an error string, or the normalized values.
     *
     * @param array<string, mixed> $user
     * @return string|array<string, mixed>
     */
    private function validateTaskInput(array $user, PDO $pdo): string|array
    {
        $summary = input_string('taskName');
        if ($summary === '') {
            return 'Please enter a task name.';
        }

        $dueDate = input_string('dueDate');
        $dueTime = input_string('dueTime');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) || !preg_match('/^\d{2}:\d{2}$/', $dueTime)) {
            return 'Please provide a valid due date and time.';
        }

        try {
            $userTimezone = new DateTimeZone($user['timezone'] ?? 'UTC');
            $dueDateTime = new DateTime("{$dueDate} {$dueTime}", $userTimezone);
            $dueDateTime->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return 'Please provide a valid due date and time.';
        }

        $reminder = input_string('reminderPreference');
        if (!in_array($reminder, self::REMINDER_PREFERENCES, true)) {
            $reminder = null;
        }

        $groupId = (int) ($_POST['group_id'] ?? 0);
        if ($groupId > 0) {
            if (!$this->inGroup($pdo, (int) $user['id'], $groupId)) {
                return 'You are not a member of the selected group.';
            }
        } else {
            $groupId = null;
        }

        $details = Sanitizer::html((string) ($_POST['taskDetails'] ?? ''));
        // Quill's empty state.
        if (in_array(trim($details), ['', '<p></p>', '<p><br /></p>', '<p><br></p>'], true)) {
            $details = '';
        }

        $isChecklist = !empty($_POST['isChecklist']);

        // Existing items: checklist[<item_id>] => content. New: checklist_new[].
        $existingItems = [];
        if (is_array($_POST['checklist'] ?? null)) {
            foreach ($_POST['checklist'] as $itemId => $content) {
                $existingItems[(int) $itemId] = is_string($content) ? trim($content) : '';
            }
        }

        $newItems = [];
        if (is_array($_POST['checklist_new'] ?? null)) {
            foreach ($_POST['checklist_new'] as $content) {
                $content = is_string($content) ? trim($content) : '';
                if ($content !== '') {
                    $newItems[] = $content;
                }
            }
        }

        return [
            'summary'                  => $summary,
            'details'                  => $isChecklist ? '' : $details,
            'due_date_utc'             => $dueDateTime->format('Y-m-d H:i:s'),
            'reminder_preference'      => $reminder,
            'group_id'                 => $groupId,
            'receive_completion_email' => !empty($_POST['receiveCompletionEmail']) ? 1 : 0,
            'is_checklist'             => $isChecklist,
            'existing_checklist_items' => $existingItems,
            'new_checklist_items'      => $newItems,
        ];
    }

    /**
     * Update/insert/delete checklist items without touching the completion
     * state of items that were kept.
     *
     * @param array<string, mixed> $input
     */
    private function syncChecklist(PDO $pdo, int $taskId, array $input): void
    {
        if (!$input['is_checklist']) {
            $pdo->prepare('DELETE FROM checklist_items WHERE task_id = ?')->execute([$taskId]);

            return;
        }

        $current = array_map(
            static fn (array $item): int => (int) $item['id'],
            $this->checklistItems($pdo, $taskId)
        );

        $kept = [];
        $update = $pdo->prepare('UPDATE checklist_items SET content = ? WHERE id = ? AND task_id = ?');
        foreach ($input['existing_checklist_items'] as $itemId => $content) {
            if (!in_array($itemId, $current, true)) {
                continue; // not this task's item — ignore
            }
            if ($content === '') {
                continue; // cleared out — will be deleted below
            }
            $update->execute([$content, $itemId, $taskId]);
            $kept[] = $itemId;
        }

        $toDelete = array_diff($current, $kept);
        if ($toDelete !== []) {
            $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
            $stmt = $pdo->prepare(
                "DELETE FROM checklist_items WHERE task_id = ? AND id IN ({$placeholders})"
            );
            $stmt->execute([$taskId, ...array_values($toDelete)]);
        }

        $insert = $pdo->prepare('INSERT INTO checklist_items (task_id, content) VALUES (?, ?)');
        foreach ($input['new_checklist_items'] as $content) {
            $insert->execute([$taskId, $content]);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $task
     */
    private function renderForm(array $user, ?array $task, ?string $error): void
    {
        $pdo = Database::pdo();
        $userTimezone = new DateTimeZone($user['timezone'] ?? 'UTC');

        $localDueDate = '';
        $localDueTime = '';
        if ($task !== null) {
            $due = new DateTime($task['due_date'], new DateTimeZone('UTC'));
            $due->setTimezone($userTimezone);
            $localDueDate = $due->format('Y-m-d');
            $localDueTime = $due->format('H:i');
        }

        echo View::render('tasks/form', [
            'title'        => $task ? 'Edit Task' : 'Create Task',
            'task'         => $task,
            'error'        => $error,
            'groups'       => $this->userGroups($pdo, (int) $user['id']),
            'localDueDate' => $localDueDate,
            'localDueTime' => $localDueTime,
            'styles'       => [asset('vendor/quill/quill.snow.css')],
            'scripts'      => [
                asset('vendor/quill/quill.min.js'),
                asset('vendor/dompurify/purify.min.js'),
                asset('js/editor.js'),
            ],
        ]);
    }
}
