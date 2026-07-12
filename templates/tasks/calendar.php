<div class="calendar-container">
    <h1>Calendar</h1>
    <div class="month-navigation">
        <a href="<?= e($prevLink) ?>" class="btn calendar-navigation-btn previous-month">Previous</a>
        <span><?= e($monthLabel) ?></span>
        <a href="<?= e($nextLink) ?>" class="btn calendar-navigation-btn next-month">Next</a>
    </div>
    <div class="calendar">
        <table>
            <thead>
                <tr>
                    <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $currentDate = clone $startDayOfWeek;
                while ($currentDate <= $endDayOfWeek) {
                    if ($currentDate > $lastDayOfMonth && (int) $currentDate->format('w') === 0) {
                        break;
                    }
                    echo '<tr>';
                    for ($i = 0; $i < 7; $i++) {
                        echo '<td><div class="date-box">';
                        if ($currentDate >= $firstDayOfMonth && $currentDate <= $lastDayOfMonth) {
                            $isToday = $currentDate->format('Y-m-d') === $now->format('Y-m-d');
                            $dateClass = $isToday ? 'date current-day' : 'date';
                            echo '<div class="' . $dateClass . '">' . $currentDate->format('j') . '</div>';
                        }
                        echo '<div class="tasks">';
                        foreach ($tasks as $task) {
                            $dueDate = new DateTime($task['due_date'], new DateTimeZone('UTC'));
                            $dueDate->setTimezone($userTimezone);
                            if ($dueDate->format('Y-m-d') !== $currentDate->format('Y-m-d')) {
                                continue;
                            }
                            $interval = $now->diff($dueDate);
                            $minutesToDue = (int) $interval->days * 1440 + (int) $interval->h * 60 + (int) $interval->i;
                            $taskClass = task_urgency_class(
                                (bool) $task['completed'],
                                (bool) $interval->invert,
                                $minutesToDue,
                                $urgencyGreen,
                                $urgencyCritical
                            );
                            echo '<div class="task ' . e($taskClass) . '">'
                                . '<a href="' . e(url('/tasks/edit?id=' . (int) $task['id'])) . '" class="task-name">'
                                . e($task['summary'])
                                . '</a></div>';
                        }
                        echo '</div></div></td>';
                        $currentDate->modify('+1 day');
                    }
                    echo '</tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <a href="<?= e(url('/tasks')) ?>" class="btn">Tasks</a>
</div>
