<?php

declare(strict_types=1);

use App\App;
use App\Auth;
use App\Controllers\AuthController;
use App\Controllers\SettingsController;
use App\Controllers\TaskController;
use App\Controllers\AdminController;
use App\Database;
use App\Installer\Installer;
use App\Installer\Migrator;
use App\Mailer;
use App\Sanitizer;
use PHPMailer\PHPMailer\PHPMailer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';

function check(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException($name);
    }
    echo "PASS: {$name}\n";
}

$runtime = APP_ROOT . '/var/regression';
@mkdir($runtime, 0700, true);
App::init(['base_url' => 'http://localhost', 'mail' => [
    'transport' => 'log', 'log_path' => $runtime . '/mail.log',
]]);
if (in_array('--database', $argv, true) || in_array($argv[1] ?? '', ['--action', '--migration'], true)) {
    if (!getenv('DB_DSN')) {
        throw new RuntimeException('Use a disposable database and explicitly set DB_DSN, DB_USER and DB_PASSWORD.');
    }
    $pdo = new PDO(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $pdo);
}
if (($argv[1] ?? '') === '--migration') {
    $migration = require APP_ROOT . '/migrations/004_token_hashes.php';
    $migration['up']($pdo, new Migrator($pdo));
    exit;
}
if (($argv[1] ?? '') === '--action') {
    $data = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    session_save_path($runtime);
    session_start();
    $_SESSION = $data['session'] ?? [];
    $_SESSION['csrf_token'] = 'regression-csrf';
    $_POST = $data['post'] ?? [];
    $_GET = $data['get'] ?? [];
    $_COOKIE = $data['cookies'] ?? [];
    $_SERVER['REMOTE_ADDR'] = $data['ip'] ?? '127.0.0.1';
    if (!isset($data['no_csrf'])) {
        $_POST['csrf_token'] = 'regression-csrf';
    }
    ob_start();
    register_shutdown_function(static function (): void {
        $body = ob_get_clean();
        echo json_encode(['body' => $body, 'session' => $_SESSION, 'status' => http_response_code() ?: 200]);
    });
    [$class, $method] = explode(':', $data['call']);
    (new $class())->$method();
    exit;
}

function action(string $call, array $post = [], array $session = [], array $extra = []): array
{
    $payload = base64_encode(json_encode(['call' => $call, 'post' => $post, 'session' => $session] + $extra));
    $process = proc_open([PHP_BINARY, __FILE__, '--action', $payload], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || $errors !== '') {
        throw new RuntimeException('Controller process failed: ' . $errors);
    }
    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
}

check(password_meets_policy('Abcdef1 '), 'space symbol accepted');
check(password_meets_policy('Ab1!' . str_repeat('x', 68)), '72 bytes accepted');
check(!password_meets_policy('Ab1!' . str_repeat('x', 69)), '73 bytes rejected');
check(password_meets_policy('Ab1!' . str_repeat('é', 34)), '72 UTF-8 bytes accepted');
check(!password_meets_policy('Ab1!' . str_repeat('é', 35)), 'multibyte byte ceiling');
check(!password_meets_policy("Abcdef1\0"), 'NUL rejected');
check(old_strings(['x' => ['bad'], 'y' => 'ok']) === ['y' => 'ok'], 'old arrays discarded');
check(within_character_limit(str_repeat('é', 255), 255) && !within_character_limit(str_repeat('é', 256), 255), 'VARCHAR character boundaries');
check(str_contains(file_get_contents(APP_ROOT . '/build-release.sh'), 'VERSION="' . App::VERSION . '"')
    && str_contains(file_get_contents(APP_ROOT . '/README.md'), 'Version **' . App::VERSION . '**')
    && is_file(APP_ROOT . '/releases/release-notes-' . App::VERSION . '.md')
    && App::SCHEMA_VERSION === 4, 'release versions agree');
check(!str_contains(Sanitizer::html('<p>safe<script>alert(1)</script></p>'), '<script'), 'real sanitizer');
$mail = new PHPMailer(true);
$mail->CharSet = PHPMailer::CHARSET_UTF8;
$mail->setFrom('test@example.test', 'Test');
$mail->addAddress('recipient@example.test', 'Recipient');
$mail->Subject = 'Regression';
$mail->Body = 'Test message';
check($mail->preSend() && str_contains($mail->getSentMIMEMessage(), 'Test message'), 'updated PHPMailer MIME execution');
check(Mailer::send('test@example.test', 'Test', 'Regression', 'Test message'), 'application log transport');
foreach ([1, 2, 3] as $version) {
    $html = \App\View::render('install/upgrade', ['title' => 'Upgrade', 'fromVersion' => $version, 'toVersion' => 4, 'appVersion' => App::VERSION]);
    check(str_contains($html, 'name="cleanup_legacy"') === ($version === 1)
        && !str_contains($html, 'value="1" checked'), "cleanup visibility schema {$version}");
}
check(str_contains(file_get_contents(APP_ROOT . '/public/install.php'), '$version === 1 && !empty($_POST[\'cleanup_legacy\'])'), 'server cleanup guard');
if (!in_array('--database', $argv, true)) {
    echo "SKIP: database checks (use --database with an empty disposable DB_DSN).\n";
    exit;
}
check($pdo->query('SHOW TABLES')->fetchAll() === [], 'database is empty before destructive fixtures');
$m = new Migrator($pdo);
foreach ([1, 2] as $fixtureVersion) {
    foreach (glob(APP_ROOT . '/migrations/*.php') as $file) {
        $definition = require $file;
        if ($definition['version'] <= $fixtureVersion) {
            $definition['up']($pdo, $m);
            $m->setVersion($definition['version']);
        }
    }
    $m->migrate();
    check($m->currentVersion() === 4, "baseline fixture schema {$fixtureVersion} upgrades to 4");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE `' . str_replace('`', '``', $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
$m->migrate();
check($m->currentVersion() === 4 && $m->migrate() === [], 'fresh and repeat migrations');
$installer = new Installer();
check($installer->createAdmin($pdo, 'Admin', 'admin', 'admin@example.test', 'America/Chicago', 'Abcdef1 ', 'Abcdef1 ') === null, 'fresh administrator');
check($pdo->query("SELECT value FROM settings WHERE name='user_registration'")->fetchColumn() === '0', 'fresh registration disabled');
check($installer->createAdmin($pdo, str_repeat('x', 256), 'bad', 'bad@example.test', 'America/Chicago', 'Abcdef1 ', 'Abcdef1 ') !== null, 'installer name bounds');
$pdo->exec("UPDATE settings SET value='1' WHERE name='user_registration'");
$pdo->prepare("INSERT INTO users (name, username, email, password, timezone, email_verified) VALUES ('User', 'user', 'user@example.test', ?, 'America/Chicago', 1)")
    ->execute([password_hash('Abcdef1 ', PASSWORD_BCRYPT, ['cost' => 10])]);
$id = (int) $pdo->lastInsertId();
$raw = str_repeat('a', 64);
foreach ([1, 2, 3] as $version) {
    $pdo->prepare('UPDATE users SET verification_token=?, reset_token=?, new_email_token=?, verification_token_expiry=DATE_ADD(NOW(), INTERVAL 1 DAY), reset_token_expiry=DATE_ADD(NOW(), INTERVAL 1 HOUR), new_email_token_expiry=DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id=?')->execute([$raw, $raw, $raw, $id]);
    $m->setVersion($version);
    $m->migrate();
    $row = $pdo->query("SELECT * FROM users WHERE id={$id}")->fetch();
    check($row['verification_token'] === hash('sha256', $raw) && $row['reset_token'] === hash('sha256', $raw) && $row['new_email_token'] === hash('sha256', $raw), "synthetic schema {$version} tokens preserved");
    $migration = require APP_ROOT . '/migrations/004_token_hashes.php';
    $migration['up']($pdo, $m);
    check($pdo->query("SELECT reset_token FROM users WHERE id={$id}")->fetchColumn() === hash('sha256', $raw), 'migration body once-only');
}
$pdo->prepare('UPDATE users SET verification_token=?,reset_token=?,new_email_token=? WHERE id=?')->execute([$raw, $raw, $raw, $id]);
$expiry = $pdo->query("SELECT reset_token_expiry FROM users WHERE id={$id}")->fetchColumn();
$m->setVersion(3);
$pdo->beginTransaction();
$pdo->query("SELECT value FROM settings WHERE name='schema_version' FOR UPDATE");
$workers = [];
for ($i = 0; $i < 2; ++$i) {
    $workers[] = proc_open([PHP_BINARY, __FILE__, '--migration'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
}
usleep(200000);
$pdo->commit();
foreach ($workers as $worker) {
    check(proc_close($worker) === 0, 'concurrent migration worker completes');
}
check($pdo->query("SELECT reset_token FROM users WHERE id={$id}")->fetchColumn() === hash('sha256', $raw)
    && $pdo->query("SELECT reset_token_expiry FROM users WHERE id={$id}")->fetchColumn() === $expiry
    && $pdo->query("SELECT reset_token FROM users WHERE username='admin'")->fetchColumn() === null, 'concurrent migration hashes once and preserves expiry and NULL');
$pdo->exec("UPDATE users SET email_verified=0, new_email='changed@example.test' WHERE id={$id}");
check(str_contains(action(AuthController::class . ':verifyEmail', [], [], ['get' => ['token' => $raw]])['body'], 'successfully verified'), 'pre-upgrade verification link works');
check(str_contains(action(AuthController::class . ':verifyEmail', [], [], ['get' => ['token' => $raw]])['body'], 'already been used'), 'verification link single-use');
check(str_contains(action(SettingsController::class . ':verifyNewEmail', [], [], ['get' => ['token' => $raw]])['body'], 'updated successfully'), 'pre-upgrade email change works');
check(str_contains(action(SettingsController::class . ':verifyNewEmail', [], [], ['get' => ['token' => $raw]])['body'], 'invalid or has expired'), 'email change single-use');
$pdo->exec("UPDATE users SET email='user@example.test' WHERE id={$id}");
check(str_contains(action(AuthController::class . ':resetPasswordForm', [], [], ['get' => ['token' => $raw]])['body'], 'New Password'), 'pre-upgrade reset link works');
$pdo->exec("UPDATE users SET reset_token_expiry=DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id={$id}");
check(str_contains(action(AuthController::class . ':resetPasswordForm', [], [], ['get' => ['token' => $raw]])['body'], 'Invalid or expired'), 'expired reset refused');
check($pdo->query("SELECT value FROM settings WHERE name='user_registration'")->fetchColumn() === '1', 'upgrade registration preserved');
$login = action(AuthController::class . ':login', ['username' => 'user', 'password' => 'Abcdef1 ']);
$session = $login['session'];
$hash = $pdo->query("SELECT password FROM users WHERE id={$id}")->fetchColumn();
check(!password_needs_rehash($hash, PASSWORD_DEFAULT) && $session['password_fingerprint'] === Auth::passwordFingerprint($hash), 'cost-10 login rehash and fingerprint');
$second = action(AuthController::class . ':login', ['username' => 'user', 'password' => 'Abcdef1 '])['session'];
$settings = action(SettingsController::class . ':save', ['newPassword' => 'Changed1 ', 'currentPassword' => 'Abcdef1 ', 'urgency_green' => '1440', 'urgency_critical' => '240'], $session);
check(str_contains($settings['body'], 'Password updated successfully'), 'own password updated');
check(str_contains(action(SettingsController::class . ':form', [], $settings['session'])['body'], 'User Settings'), 'current session remains');
check(action(SettingsController::class . ':form', [], $second)['body'] === '', 'second independent session rejected');
$pdo->prepare('INSERT INTO user_remember_tokens (user_id,selector,token_hash,expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))')->execute([$id, str_repeat('b', 24), hash('sha256', 'validator')]);
$remember = ['phptodo_remember' => str_repeat('b', 24) . ':validator'];
check(action(SettingsController::class . ':form', [], [], ['cookies' => $remember])['session']['password_fingerprint'] === $settings['session']['password_fingerprint'], 'remember-only login fingerprint');
check(action(SettingsController::class . ':form', [], $second, ['cookies' => $remember])['body'] === '', 'invalid session cannot fall back to remember');
$pdo->prepare('UPDATE users SET reset_token=?,reset_token_expiry=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?')->execute([hash('sha256', $raw), $id]);
action(AuthController::class . ':resetPassword', ['token' => $raw, 'password' => 'Resetpass1 ', 'confirmPassword' => 'Resetpass1 ']);
check(action(SettingsController::class . ':form', [], $settings['session'])['body'] === '' && (int) $pdo->query('SELECT COUNT(*) FROM user_remember_tokens')->fetchColumn() === 0, 'reset ends sessions and persistent clients');
check(str_contains(action(AuthController::class . ':resetPasswordForm', [], [], ['get' => ['token' => $raw]])['body'], 'Invalid or expired'), 'reset token cannot be reused');
$pdo->exec('DELETE FROM auth_attempts');
$generic = [];
foreach (['admin', 'admin@example.test', 'missing', 'user'] as $identifier) {
    $generic[] = action(AuthController::class . ':requestVerification', ['identifier' => $identifier])['body'];
}
check(count(array_unique($generic)) === 1, 'generic recovery for unknown verified unverified and limited requests');
$pdo->exec('DELETE FROM auth_attempts');
$adminId = (int) $pdo->query("SELECT id FROM users WHERE username='admin'")->fetchColumn();
for ($i = 1; $i <= 4; ++$i) {
    $before = $pdo->query("SELECT verification_token FROM users WHERE id={$adminId}")->fetchColumn();
    action(AuthController::class . ':requestVerification', ['identifier' => $i % 2 ? 'admin' : 'admin@example.test'], [], ['ip' => '127.0.0.' . $i]);
    $after = $pdo->query("SELECT verification_token FROM users WHERE id={$adminId}")->fetchColumn();
    check(($before !== $after) === ($i <= 3), "canonical account quota request {$i}");
}
$pdo->exec('DELETE FROM auth_attempts');
for ($i = 1; $i <= 3; ++$i) {
    action(AuthController::class . ':forgotPassword', ['email' => 'admin@example.test']);
}
$before = $pdo->query("SELECT verification_token FROM users WHERE id={$adminId}")->fetchColumn();
action(AuthController::class . ':requestVerification', ['identifier' => 'admin']);
check($before === $pdo->query("SELECT verification_token FROM users WHERE id={$adminId}")->fetchColumn(), 'resends share reset IP quota');
check(action(AuthController::class . ':requestVerification', ['identifier' => 'admin'], [], ['no_csrf' => true])['status'] === 403, 'recovery CSRF required');
$pdo->exec('DELETE FROM auth_attempts');
$pdo->exec("UPDATE users SET email_verified=1 WHERE id={$adminId}");
$admin = action(AuthController::class . ':login', ['username' => 'admin', 'password' => 'Abcdef1 '])['session'];
$pdo->exec("UPDATE users SET email_verified=0 WHERE id={$id}");
$listing = action(AdminController::class . ':users', [], $admin)['body'];
check(substr_count($listing, 'value="resend_verification"') === 1, 'admin button only for unverified target');
check(action(AdminController::class . ':handleActions', ['action' => 'resend_verification', 'user_id' => $id], $admin, ['no_csrf' => true])['status'] === 403, 'admin resend CSRF');
action(AdminController::class . ':handleActions', ['action' => 'resend_verification', 'user_id' => $id, 'email' => 'other@example.test'], $admin);
$log = file_get_contents($runtime . '/mail.log');
check(str_contains($log, '<user@example.test>') && !str_contains($log, '<other@example.test>'), 'admin sends only to stored address');
$pdo->exec("UPDATE users SET role='admin',email_verified=1 WHERE id={$id}");
$regularAdmin = action(AuthController::class . ':login', ['username' => 'user', 'password' => 'Resetpass1 '])['session'];
$pdo->exec("UPDATE users SET email_verified=0 WHERE id={$adminId}");
$before = $pdo->query("SELECT verification_token FROM users WHERE id={$adminId}")->fetchColumn();
action(AdminController::class . ':handleActions', ['action' => 'resend_verification', 'user_id' => $adminId], $regularAdmin);
check($before === $pdo->query("SELECT verification_token FROM users WHERE id={$adminId}")->fetchColumn(), 'regular admin cannot resend for super-admin');
check(!str_contains(action(AdminController::class . ':users', [], $regularAdmin)['body'], 'value="resend_verification"'), 'restricted target button hidden');
$pdo->exec("UPDATE users SET email_verified=1 WHERE id={$adminId}");
action(AdminController::class . ':handleActions', ['action' => 'create_group', 'group_name' => str_repeat('é', 256)], $admin);
check((int) $pdo->query('SELECT COUNT(*) FROM user_groups')->fetchColumn() === 0, 'oversized group rejected before insert');
$taskMethod = new ReflectionMethod(TaskController::class, 'validateTaskInput');
$taskUser = ['id' => $id, 'timezone' => 'America/Chicago'];
$base = ['taskName' => 'Task', 'dueDate' => '2027-01-01', 'dueTime' => '12:00'];
foreach ([['taskName', 65535], ['taskDetails', 65535]] as [$field, $limit]) {
    $_POST = [$field => str_repeat('x', $limit)] + $base;
    check(is_array($taskMethod->invoke(new TaskController(), $taskUser, $pdo)), "{$field} exact byte limit");
    $_POST[$field] .= 'x';
    check(is_string($taskMethod->invoke(new TaskController(), $taskUser, $pdo)), "{$field} oversized validation");
}
$_POST = $base + ['checklist_new' => [str_repeat('é', 256)]];
check(is_string($taskMethod->invoke(new TaskController(), $taskUser, $pdo)), 'checklist character bound');
$_POST = $base + ['checklist_new' => [str_repeat('é', 255)]];
check(is_array($taskMethod->invoke(new TaskController(), $taskUser, $pdo)), 'checklist exact character limit');
$_POST = $base + ['taskDetails' => str_repeat('&', 14000)];
check(is_string($taskMethod->invoke(new TaskController(), $taskUser, $pdo)), 'details checked after sanitizer expansion');
foreach (['name' => 255, 'username' => 190, 'email' => 190] as $field => $limit) {
    $post = [$field => str_repeat('x', $limit + 1)] + ['name' => 'Name', 'username' => 'new-user', 'email' => 'new@example.test', 'timezone' => 'America/Chicago'];
    check(str_contains(action(AuthController::class . ':register', $post)['body'], 'at most'), "registration {$field} bound");
}
$oversizedEmail = action(SettingsController::class . ':save', ['email' => str_repeat('x', 191), 'urgency_green' => '1440', 'urgency_critical' => '240'], $regularAdmin);
check(str_contains($oversizedEmail['body'], 'Email must be at most'), 'settings email bound');
check(action(SettingsController::class . ':form', [], ['user_id' => $id])['body'] === '', 'legacy fingerprint-free session refused');
$pdo->exec('DELETE FROM auth_attempts');
$pdo->prepare('UPDATE users SET email_verified=0,verification_token=?,verification_token_expiry=DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id=?')->execute([hash('sha256', $raw), $id]);
check(str_contains(action(AuthController::class . ':verifyEmail', [], [], ['get' => ['token' => $raw]])['body'], 'expired'), 'expired verification offers recovery');
action(AuthController::class . ':resendVerification', [], [], ['get' => ['token' => $raw]]);
check($pdo->query("SELECT verification_token FROM users WHERE id={$id}")->fetchColumn() !== hash('sha256', $raw), 'legacy token resend remains operational');
$log = file_get_contents($runtime . '/mail.log');
preg_match_all('~/verify\?token=([a-f0-9]{64})~', $log, $links);
$freshToken = end($links[1]);
check($pdo->query("SELECT verification_token FROM users WHERE id={$id}")->fetchColumn() === hash('sha256', $freshToken), 'fresh verification digest matches outgoing link');
check(str_contains(action(AuthController::class . ':verifyEmail', [], [], ['get' => ['token' => $freshToken]])['body'], 'successfully verified'), 'fresh verification link works');
$registration = action(AuthController::class . ':register', ['name' => ['bad'], 'username' => ['bad'], 'email' => ['bad'], 'password' => ['bad']]);
check(str_contains($registration['body'], 'All fields are required'), 'malformed registration arrays render safely');
echo "Database regression checks completed.\n";
