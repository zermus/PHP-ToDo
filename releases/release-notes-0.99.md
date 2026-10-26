A hardening and maintenance update with improved sign-in handling and validation. Requires PHP 8.3+; upgrades the database to schema 4.

## Highlights
- Single-use account links are stored as hashes, preserving existing links during upgrade.
- Verification links can be requested from sign-in or resent by an administrator to the stored account address. Resends share the password-reset request limits.
- Password changes and resets sign out other sessions.
- Passwords accept spaces and other non-alphanumeric symbols, with a 72-byte maximum.
- New installations start with registration disabled; existing settings are preserved.
- Form validation handles oversized input and malformed old form values.
- Sanitizer caching uses an application-private directory when writable.
- Updated HTMLPurifier and PHPMailer dependencies; Quill remains unchanged.

## Upgrading
1. **Back up your database.**
2. Extract this release over your existing files (`config.php` is preserved).
3. Run the setup-key-protected `install.php` upgrade to apply schema 4. Existing verification, password-reset and email-change links keep working until their normal expiry.
4. Everyone signs in again once. Password rehashing at login can also sign out other sessions.
5. Give the PHP worker ownership of `var/cache/purifier` and 0700 permissions on its directories. Never make it world-writable. If it is not writable, caching is disabled and sanitization still works.
6. Passwords require at least eight characters, uppercase and lowercase letters, a number and a non-alphanumeric character (spaces count), and must not exceed 72 bytes.
7. No new configuration keys or runtime requirements are introduced. Older 0.96/0.97 installations still use the existing installer upgrade path. Legacy-file cleanup is offered only for 0.96, unticked by default.

## Requirements
- PHP **8.3+** (`pdo_mysql`, `mbstring`)
- MySQL **5.7+** or MariaDB **10.2+**

See [README.md](../README.md) and [CHANGELOG.md](../CHANGELOG.md) for full details.

## Install
Download `php-todo-0.99.tar.gz`, extract it, and follow the [README installation steps](../README.md#installation).
