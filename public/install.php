<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\App;
use App\Csrf;
use App\Installer\Installer;
use App\Installer\Migrator;
use App\View;

$key = App::config('setup_key', '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$verified = is_string($key) && $key !== ''
    && hash_equals(hash('sha256', $key), (string) ($_SESSION['setup_key_hash'] ?? ''));
if ($isPost) {
    $submitted = $_POST['setup_key'] ?? null;
    $verified = is_string($key) && $key !== '' && is_string($submitted) && hash_equals($key, $submitted);
    if (!$verified) {
        unset($_SESSION['setup_key_hash']);
        http_response_code(403);
    } else {
        Csrf::require();
        $_SESSION['setup_key_hash'] = hash('sha256', $key);
    }
}
if (!$verified) {
    echo View::render('install/key', [
        'title'      => 'Setup authorization',
        'configured' => is_string($key) && $key !== '',
        'rejected'   => $isPost,
    ]);
    exit;
}

$installer = new Installer();

try {
    $pdo = $installer->connect();
} catch (PDOException $e) {
    error_log('[php-todo] Installer DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo View::render('install/message', [
        'title'   => 'Install To-Do App',
        'heading' => 'Database Connection Failed',
        'body'    => 'Could not connect to the database with the credentials in config.php. '
            . 'Check the db settings (host, name, user, pass) and that your database user may '
            . 'create the database, then reload this page.',
    ]);
    exit;
}

$migrator = new Migrator($pdo);
$version = $migrator->currentVersion();
$hasAdmin = $installer->superAdminExists($pdo, $migrator);

// ---------------------------------------------------------------- POST --

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $action = input_string('action');

    if ($action === 'upgrade' && $version > 0 && $version < App::SCHEMA_VERSION) {
        $applied = $migrator->migrate();

        $removed = [];
        $failed = [];
        if ($version === 1 && !empty($_POST['cleanup_legacy'])) {
            [$removed, $failed] = $installer->removeLegacyFiles();
        }

        echo View::render('install/upgraded', [
            'title'   => 'Upgrade Complete',
            'applied' => $applied,
            'removed' => $removed,
            'failed'  => $failed,
        ]);
        exit;
    }

    if ($action === 'install' && !$hasAdmin) {
        if ($version < App::SCHEMA_VERSION) {
            $migrator->migrate();
        }

        $error = $installer->createAdmin(
            $pdo,
            input_string('adminName'),
            input_string('adminUsername'),
            input_string('adminEmail'),
            input_string('adminTimezone'),
            input_password('adminPassword'),
            input_password('verifyPassword')
        );

        if ($error === null) {
            echo View::render('install/installed', [
                'title'    => 'Installation Complete',
                'cronPath' => realpath(APP_ROOT . '/bin/send_reminders.php') ?: APP_ROOT . '/bin/send_reminders.php',
            ]);
            exit;
        }

        echo View::render('install/form', [
            'title' => 'Install To-Do App',
            'error' => $error,
            'old'   => old_strings($_POST),
        ]);
        exit;
    }

    // Fall through to GET rendering for stale/invalid posts.
}

// ----------------------------------------------------------------- GET --

if ($version > 0 && $version < App::SCHEMA_VERSION) {
    echo View::render('install/upgrade', [
        'title'       => 'Upgrade To-Do App',
        'fromVersion' => $version,
        'toVersion'   => App::SCHEMA_VERSION,
        'appVersion'  => App::VERSION,
    ]);
    exit;
}

if ($hasAdmin) {
    echo View::render('install/message', [
        'title'   => 'To-Do App',
        'heading' => 'Already Installed',
        'body'    => 'This To-Do App installation (version ' . App::VERSION . ') is complete and '
            . 'up to date. For security you may delete install.php from the public/ directory. '
            . 'It will refuse to reinstall either way.',
        'homeLink' => true,
    ]);
    exit;
}

echo View::render('install/form', [
    'title' => 'Install To-Do App',
    'error' => null,
    'old'   => [],
]);
