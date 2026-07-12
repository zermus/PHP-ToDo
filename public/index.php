<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\SettingsController;
use App\Controllers\TaskController;
use App\Router;

$router = new Router();

$auth = new AuthController();
$tasks = new TaskController();
$settings = new SettingsController();
$admin = new AdminController();

$router->get('/', static fn () => redirect('/tasks'));

$router->get('/login', [$auth, 'loginForm']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);
$router->get('/register', [$auth, 'registerForm']);
$router->post('/register', [$auth, 'register']);
$router->get('/verify', [$auth, 'verifyEmail']);
$router->get('/resend-verification', [$auth, 'resendVerification']);
$router->get('/forgot-password', [$auth, 'forgotPasswordForm']);
$router->post('/forgot-password', [$auth, 'forgotPassword']);
$router->get('/reset-password', [$auth, 'resetPasswordForm']);
$router->post('/reset-password', [$auth, 'resetPassword']);

$router->get('/tasks', [$tasks, 'index']);
$router->get('/tasks/create', [$tasks, 'createForm']);
$router->post('/tasks/create', [$tasks, 'create']);
$router->get('/tasks/edit', [$tasks, 'editForm']);
$router->post('/tasks/edit', [$tasks, 'update']);
$router->post('/tasks/complete', [$tasks, 'complete']);
$router->post('/checklist/complete', [$tasks, 'completeChecklistItem']);
$router->get('/calendar', [$tasks, 'calendar']);

$router->get('/settings', [$settings, 'form']);
$router->post('/settings', [$settings, 'save']);
$router->get('/settings/verify-new-email', [$settings, 'verifyNewEmail']);

$router->get('/admin/users', [$admin, 'users']);
$router->post('/admin/users', [$admin, 'handleActions']);

$router->dispatch();
