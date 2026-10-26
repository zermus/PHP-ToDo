<?php

declare(strict_types=1);

use App\Installer\Migrator;

return [
    'version' => 4,
    'description' => 'Hash account links and refresh sign-in state',
    'up' => function (PDO $pdo, Migrator $m): void {
        $pdo->beginTransaction();
        try {
            $version = $pdo->query("SELECT value FROM settings WHERE name = 'schema_version' FOR UPDATE")
                ->fetchColumn();
            if ((int) $version < 4) {
                $pdo->exec('UPDATE users SET
                    verification_token = SHA2(verification_token, 256),
                    reset_token = SHA2(reset_token, 256),
                    new_email_token = SHA2(new_email_token, 256)');
                $pdo->exec('DELETE FROM user_remember_tokens');
                $m->setVersion(4);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    },
];
