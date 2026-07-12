<?php

/**
 * php-todo configuration.
 *
 * Copy this file to config.php (in the same directory) and fill in your values.
 * config.php is never overwritten by upgrades.
 */

return [

    // MySQL / MariaDB connection.
    'db' => [
        'host' => 'localhost',
        'name' => 'tododbname',
        'user' => 'tododbuser',
        'pass' => 'tododbpassword',
    ],

    // Public URL of the app, with trailing slash.
    // Flat deploy (extracted into the web root):  https://your.website.com/todo/
    // public/ deploy (document root = public/):   https://todo.your.website.com/
    'base_url' => 'https://your.website.com/todo/',

    'mail' => [
        'from'      => 'todo@your.website.com',
        'from_name' => 'To-Do App',

        // 'mail' = PHP mail(), 'smtp' = authenticated SMTP, 'log' = write to a file (for testing).
        'transport' => 'mail',

        // Only used when transport is 'smtp'.
        'smtp' => [
            'host'       => 'smtp.your.website.com',
            'port'       => 587,
            'username'   => '',
            'password'   => '',
            'encryption' => 'tls',   // 'tls', 'ssl', or 'none'
        ],

        // Only used when transport is 'log'.
        'log_path' => __DIR__ . '/mail.log',
    ],
];
