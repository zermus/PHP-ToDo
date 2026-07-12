<?php

declare(strict_types=1);

use App\Installer\Migrator;

/**
 * Schema v1: the 0.96 baseline. Fresh installs create this and are then
 * immediately upgraded by the later migrations — the exact same path an
 * existing 0.96 database takes, so there is a single source of truth.
 */
return [
    'version' => 1,
    'description' => 'Baseline schema (0.96)',
    'up' => function (PDO $pdo, Migrator $m): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            value TEXT NOT NULL
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            username VARCHAR(190) NOT NULL UNIQUE,
            email VARCHAR(190) NOT NULL UNIQUE,
            new_email VARCHAR(255) DEFAULT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('super_admin', 'admin', 'user') DEFAULT 'user',
            email_verified BOOLEAN DEFAULT FALSE,
            new_email_verified BOOLEAN DEFAULT FALSE,
            verification_token VARCHAR(255) DEFAULT NULL,
            new_email_token VARCHAR(255) DEFAULT NULL,
            new_email_token_expiry DATETIME DEFAULT NULL,
            remember_token VARCHAR(255) DEFAULT NULL,
            reset_token VARCHAR(255) DEFAULT NULL,
            reset_token_expiry DATETIME DEFAULT NULL,
            timezone VARCHAR(50) DEFAULT NULL,
            urgency_green INT DEFAULT 1440,
            urgency_critical INT DEFAULT 240,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS user_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            group_id INT DEFAULT NULL,
            summary TEXT NOT NULL,
            details TEXT DEFAULT NULL,
            due_date DATETIME NOT NULL,
            reminder_preference ENUM('15m', '30m', '1h', '2h', '4h', '12h', '24h') DEFAULT NULL,
            reminder_sent BOOLEAN DEFAULT FALSE,
            completed BOOLEAN DEFAULT FALSE,
            receive_completion_email BOOLEAN DEFAULT FALSE,
            completion_email_sent BOOLEAN DEFAULT FALSE,
            last_notification_sent DATETIME DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS checklist_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            content VARCHAR(255) NOT NULL,
            completed BOOLEAN DEFAULT FALSE,
            FOREIGN KEY (task_id) REFERENCES tasks(id)
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS group_memberships (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            group_id INT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    },
];
