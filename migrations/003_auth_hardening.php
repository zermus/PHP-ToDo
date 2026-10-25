<?php

declare(strict_types=1);

use App\Installer\Migrator;

return [
    'version' => 3,
    'description' => 'Authentication hardening',
    'up' => function (PDO $pdo, Migrator $m): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kind ENUM('login', 'reset') NOT NULL,
            ip VARCHAR(45) NOT NULL,
            identifier VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach (['auth_ip' => 'kind, ip, created_at',
                  'auth_identifier' => 'kind, identifier, created_at',
                  'auth_time' => 'created_at'] as $index => $columns) {
            if (!$m->indexExists('auth_attempts', $index)) {
                $pdo->exec("CREATE INDEX {$index} ON auth_attempts ({$columns})");
            }
        }
        $pdo->exec('DELETE FROM user_remember_tokens');
    },
];
