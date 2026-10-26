<?php

declare(strict_types=1);

use App\App;

function request_trusts_proxy(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', (array) App::config('trusted_proxies', []), true);
}

function request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (request_trusts_proxy() && strtolower(trim($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
}

function request_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (request_trusts_proxy()) {
        $chain = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        $candidate = trim((string) end($chain));
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
        }
    }

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function app_cookie_path(): string
{
    $path = parse_url((string) App::config('base_url', '/'), PHP_URL_PATH);

    return is_string($path) && str_starts_with($path, '/') ? $path : '/';
}

/**
 * HTML-escape for template output.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Absolute URL for an app route, e.g. url('/tasks') or url('/tasks/edit?id=5').
 */
function url(string $path = '/'): string
{
    $base = rtrim((string) App::config('base_url', '/'), '/');

    return $base . '/' . ltrim($path, '/');
}

/**
 * URL for a static asset inside public/, e.g. asset('css/app.css').
 */
function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

/**
 * Queue a one-shot message for the next rendered page.
 */
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

/** @return list<array{message: string, type: string}> */
function flash_pull(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $messages;
}

/**
 * Send a JSON response and stop.
 *
 * @param array<string, mixed> $payload
 */
function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/**
 * Read a trimmed string field from an input array (default $_POST).
 *
 * @param array<string, mixed>|null $source
 */
function input_string(string $key, ?array $source = null): string
{
    $source ??= $_POST;
    $value = $source[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

function input_password(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? $value : '';
}

function within_character_limit(string $value, int $limit): bool
{
    return mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= $limit;
}

function old_strings(array $values): array
{
    return array_filter($values, 'is_string');
}

/**
 * The timezones offered throughout the app.
 *
 * @return array<string, string> IANA zone => label
 */
function timezone_choices(): array
{
    return [
        'America/New_York'     => 'Eastern Time (US & Canada)',
        'America/Chicago'      => 'Central Time (US & Canada)',
        'America/Denver'       => 'Mountain Time (US & Canada)',
        'America/Los_Angeles'  => 'Pacific Time (US & Canada)',
        'America/Anchorage'    => 'Alaska',
        'America/Halifax'      => 'Atlantic Time (Canada)',
        'America/Buenos_Aires' => 'Buenos Aires',
        'America/Sao_Paulo'    => 'Sao Paulo',
        'America/Lima'         => 'Lima',
        'Pacific/Honolulu'     => 'Hawaii',
        'Europe/London'        => 'London',
        'Europe/Berlin'        => 'Berlin, Frankfurt, Paris, Rome, Madrid',
        'Europe/Athens'        => 'Athens, Istanbul, Minsk',
        'Europe/Moscow'        => 'Moscow, St. Petersburg, Volgograd',
    ];
}

/**
 * Render <option> tags for the timezone select.
 */
function timezone_options(?string $selected = null): string
{
    $html = '';
    foreach (timezone_choices() as $zone => $label) {
        $sel = $zone === $selected ? ' selected' : '';
        $html .= '<option value="' . e($zone) . '"' . $sel . '>' . e($label) . '</option>';
    }

    return $html;
}

/**
 * Validate a timezone against the offered list (falls back to any valid IANA zone).
 */
function valid_timezone(string $zone): bool
{
    return isset(timezone_choices()[$zone]) || in_array($zone, timezone_identifiers_list(), true);
}

/**
 * Server-side password policy, mirrored client-side in password.js.
 */
function password_meets_policy(string $password): bool
{
    return strlen($password) <= 72
        && mb_strlen($password) >= 8
        && !str_contains($password, "\0")
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^a-zA-Z0-9]/', $password);
}

/**
 * Human label for an urgency threshold in minutes ("2 hours", "1 day").
 */
function urgency_label(int $minutes): string
{
    if ($minutes >= 1440) {
        $days = intdiv($minutes, 1440);

        return $days . ' day' . ($days > 1 ? 's' : '');
    }
    if ($minutes >= 60) {
        $hours = intdiv($minutes, 60);

        return $hours . ' hour' . ($hours > 1 ? 's' : '');
    }

    return $minutes . ' minutes';
}

/**
 * CSS urgency class for a task row, given minutes until due.
 */
function task_urgency_class(bool $completed, bool $pastDue, int $minutesToDue, int $urgencyGreen, int $urgencyCritical): string
{
    if ($completed) {
        return 'task-completed';
    }
    if ($pastDue) {
        return 'task-past-due';
    }
    if ($minutesToDue <= $urgencyCritical) {
        return 'task-urgency-critical';
    }
    if ($minutesToDue <= $urgencyGreen) {
        return 'task-urgency-soon';
    }

    return 'task-urgency-green';
}
