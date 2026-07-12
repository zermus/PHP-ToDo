<?php

declare(strict_types=1);

use App\Installer\Migrator;

/**
 * Schema v2 (release 0.97). Every step is guarded so a partially applied
 * run can be retried safely, and so it is a no-op where already satisfied.
 */
return [
    'version' => 2,
    'description' => 'utf8mb4, hashed remember-me tokens, token expiries, integrity fixes',
    'up' => function (PDO $pdo, Migrator $m): void {

        // 1. Shrink unique-indexed columns so utf8mb4 fits index limits
        //    on older InnoDB row formats.
        $pdo->exec('ALTER TABLE users
            MODIFY username VARCHAR(190) NOT NULL,
            MODIFY email VARCHAR(190) NOT NULL');

        // 2. Convert database and tables to utf8mb4.
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $pdo->exec('ALTER DATABASE `' . str_replace('`', '``', $dbName)
            . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        foreach (['settings', 'users', 'user_groups', 'tasks', 'checklist_items', 'group_memberships'] as $table) {
            $pdo->exec("ALTER TABLE {$table} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        // 3. checklist_items.task_id must cascade on task deletion.
        $fk = $m->foreignKeyOn('checklist_items', 'task_id');
        if ($fk !== null && $fk['delete_rule'] !== 'CASCADE') {
            $pdo->exec("ALTER TABLE checklist_items DROP FOREIGN KEY `{$fk['name']}`");
            $fk = null;
        }
        if ($fk === null) {
            $pdo->exec('ALTER TABLE checklist_items
                ADD CONSTRAINT fk_checklist_task FOREIGN KEY (task_id)
                REFERENCES tasks(id) ON DELETE CASCADE');
        }

        // 4. Remember-me: hashed, expiring, rotating tokens in their own table.
        $pdo->exec('CREATE TABLE IF NOT EXISTS user_remember_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            selector CHAR(24) NOT NULL UNIQUE,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_remember_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        if ($m->columnExists('users', 'remember_token')) {
            $pdo->exec('ALTER TABLE users DROP COLUMN remember_token');
        }

        // 5. Email verification tokens expire from now on.
        if (!$m->columnExists('users', 'verification_token_expiry')) {
            $pdo->exec('ALTER TABLE users
                ADD COLUMN verification_token_expiry DATETIME DEFAULT NULL
                AFTER verification_token');
        }

        // 6. Single-hop email-change flow no longer needs this flag.
        if ($m->columnExists('users', 'new_email_verified')) {
            $pdo->exec('ALTER TABLE users DROP COLUMN new_email_verified');
        }

        // 7. Group membership integrity: dedupe, then enforce uniqueness.
        if (!$m->indexExists('group_memberships', 'uq_user_group')) {
            $pdo->exec('DELETE gm1 FROM group_memberships gm1
                INNER JOIN group_memberships gm2
                   ON gm1.user_id = gm2.user_id
                  AND gm1.group_id = gm2.group_id
                  AND gm1.id > gm2.id');
            $pdo->exec('ALTER TABLE group_memberships
                ADD UNIQUE KEY uq_user_group (user_id, group_id)');
        }

        // 8. Reminder redesign: per-recipient sends, dead column removed.
        if ($m->columnExists('tasks', 'last_notification_sent')) {
            $pdo->exec('ALTER TABLE tasks DROP COLUMN last_notification_sent');
        }
    },
];
