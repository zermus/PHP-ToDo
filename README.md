# PHP To-Do Application

A self-hosted to-do list with groups, checklists, rich-text notes, a calendar
view, and email reminders. Version **0.97**.

## Requirements

- PHP **8.1 or higher** (with `pdo_mysql` and `mbstring` extensions)
- MySQL **5.7+** or MariaDB **10.2+** (required for the `utf8mb4` schema)
- A web server (Apache or Nginx)

Composer is **not** required to install a release — the `vendor/` directory
(PHPMailer, HTMLPurifier) ships inside the release tarball.

## Directory layout

The application code lives outside the public web root:

```
php-todo/
├── public/        <- web server document root (contains index.php, install.php, assets)
├── src/           <- application code
├── templates/     <- HTML templates
├── migrations/    <- database migrations
├── bin/           <- send_reminders.php (cron)
├── vendor/        <- bundled dependencies
├── config.php     <- your configuration (you create this; never overwritten)
└── config.sample.php
```

## Installation

1. **Download and extract** the release tarball into your installation directory.

2. **Create your config**: copy `config.sample.php` to `config.php` and fill in
   your database credentials, `base_url`, and mail settings. `config.php` is
   never touched by upgrades.

3. **Point your web server at `public/`** (recommended), or deploy flat — see
   *Deployment modes* below.

4. **Run the installer**: browse to `.../install.php` (e.g.
   `https://yoursite.com/todo/install.php`), fill in the admin account form,
   and submit.

5. **Post-install**:
   - Verify the admin email via the link that was sent.
   - Optionally delete `public/install.php` (it locks itself once installed).
   - Set up the reminder cron (see below).

## Deployment modes

**Recommended — document root at `public/`:** point your virtual host's
document root at the `public/` directory. Application code and `config.php`
then sit physically outside the web root. This works on Apache and Nginx.

Nginx example:

```nginx
server {
    root /var/www/php-todo/public;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fpm_script_name;
    }
}
```

**Flat deploy (Apache only):** if you extract the app directly into a web root
you don't control the document root of, the included root `.htaccess` transparently
rewrites requests into `public/` and denies access to `src/`, `templates/`,
`migrations/`, `vendor/`, `bin/`, and `config.php`. This requires Apache with
`mod_rewrite`. **Do not deploy flat on Nginx** — the `.htaccess` protections do
not apply there; use the `public/`-as-root layout instead.

## Reminder cron

Run the reminder/notification sender every minute:

```
* * * * * php /path/to/php-todo/bin/send_reminders.php
```

On Windows, use Task Scheduler to run `php C:\path\to\php-todo\bin\send_reminders.php`
at a 1-minute interval.

## Email

Set `mail.transport` in `config.php`:

- `mail` — PHP's built-in `mail()` (default; headers built correctly by PHPMailer).
- `smtp` — authenticated SMTP; fill in the `mail.smtp` block (host, port,
  username, password, encryption).
- `log` — write messages to `mail.log_path` instead of sending. Useful for
  local testing.

## Upgrading from a previous version

1. **Back up your database.**
2. Extract the new release **over** your existing files. `config.php` is
   preserved.
3. Browse to `.../install.php`. It detects the existing installation and shows
   an **Upgrade** button instead of the install form; it never exposes admin
   creation on an existing database.
4. Click **Upgrade Database**. Migrations run in order and the schema version is
   recorded. The upgrade can optionally remove leftover files from older
   (pre-0.97) flat deployments.

Note: upgrading from 0.96 invalidates existing "remember me" cookies (the token
storage is now hashed and expiring) — affected users simply log in again once.

## License

Released under the [MIT License](LICENSE).

- Official website: https://cgee.net/php-todo/
- Feedback: cgee [at] cgee [dot] net
