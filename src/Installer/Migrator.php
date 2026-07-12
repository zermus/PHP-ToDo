<?php

declare(strict_types=1);

namespace App\Installer;

use App\App;
use PDO;

final class Migrator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Current schema version of the connected database.
     *
     * 0 = fresh (no settings table), 1 = a 0.96 install (settings table but
     * no schema_version row), otherwise the stored version.
     */
    public function currentVersion(): int
    {
        // Once a schema_version is recorded it is authoritative.
        if ($this->tableExists('settings')) {
            $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE name = 'schema_version'");
            $stmt->execute();
            $value = $stmt->fetchColumn();
            if ($value !== false) {
                return (int) $value;
            }
        }

        // No schema_version row. Treat it as a 0.96 install (version 1) only if
        // the complete baseline schema is present — group_memberships is the
        // last table created by migration 001. Otherwise it's a fresh (or a
        // half-built, interrupted) install and 001 must run.
        return $this->tableExists('group_memberships') ? 1 : 0;
    }

    /**
     * Run all pending migrations. Returns descriptions of the ones applied.
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        $current = $this->currentVersion();
        $applied = [];

        foreach ($this->migrations() as $migration) {
            if ($migration['version'] <= $current) {
                continue;
            }

            ($migration['up'])($this->pdo, $this);
            $this->setVersion($migration['version']);
            $applied[] = "v{$migration['version']}: {$migration['description']}";
        }

        return $applied;
    }

    public function setVersion(int $version): void
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM settings WHERE name = 'schema_version'");
        $stmt->execute();

        if ((int) $stmt->fetchColumn() > 0) {
            $this->pdo->prepare("UPDATE settings SET value = ? WHERE name = 'schema_version'")
                ->execute([(string) $version]);
        } else {
            $this->pdo->prepare("INSERT INTO settings (name, value) VALUES ('schema_version', ?)")
                ->execute([(string) $version]);
        }
    }

    /**
     * Ordered migration definitions loaded from the migrations/ directory.
     *
     * @return list<array{version: int, description: string, up: callable}>
     */
    private function migrations(): array
    {
        $files = glob(APP_ROOT . '/migrations/*.php') ?: [];
        sort($files);

        $migrations = [];
        foreach ($files as $file) {
            $migration = require $file;
            if (is_array($migration) && isset($migration['version'], $migration['up'])) {
                $migrations[] = $migration;
            }
        }

        usort($migrations, static fn (array $a, array $b): int => $a['version'] <=> $b['version']);

        return $migrations;
    }

    // --- information_schema helpers for re-runnable migrations ----------

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function indexExists(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $stmt->execute([$table, $index]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Name and delete rule of the foreign key on $table.$column, or null.
     *
     * @return array{name: string, delete_rule: string}|null
     */
    public function foreignKeyOn(string $table, string $column): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rc.constraint_name AS name, rc.delete_rule
             FROM information_schema.referential_constraints rc
             INNER JOIN information_schema.key_column_usage kcu
                ON kcu.constraint_schema = rc.constraint_schema
               AND kcu.constraint_name = rc.constraint_name
             WHERE rc.constraint_schema = DATABASE()
               AND kcu.table_name = ? AND kcu.column_name = ?'
        );
        $stmt->execute([$table, $column]);
        $row = $stmt->fetch();

        return $row ? ['name' => $row['name'], 'delete_rule' => $row['delete_rule']] : null;
    }
}
