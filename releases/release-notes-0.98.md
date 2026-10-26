A security-focused update with safer setup, stronger sign-in protections, and browser hardening. Requires PHP 8.3+ and upgrades the database to schema 3.

## Highlights
- Setup and upgrades require a private setup key before database access.
- App-specific cookies isolate sign-in state.
- Forwarded HTTPS and client-IP information comes only from configured trusted proxies.
- Persistent limits protect sign-in and password-reset requests.
- Email changes require password confirmation.
- Improved editor sanitization, refreshed DOMPurify, and security headers strengthen browser protections, with built-in scripts in separate files.

## Upgrading
1. **Back up your database.**
2. Extract this release over your existing files (`config.php` is preserved).
3. Before opening `install.php`, set `setup_key` in `config.php` to a long, locally generated random private secret; the installer refuses without it. Legacy loose-variable configs use `$setup_key`.
4. Behind a reverse proxy, set `trusted_proxies` to its exact IP addresses (or `$trusted_proxies` in a legacy config). Proxies must overwrite forwarded headers rather than relay client values.
5. Run `install.php`, enter and confirm the setup key, then click **Upgrade Database** to apply schema 3.
6. Everyone signs in again once: old remember-me cookies and tokens are dropped and old sessions are no longer used.
7. Move inline JavaScript in custom template edits into separate script files served by the app because the new security headers block inline scripts.

## Requirements
- PHP **8.3+** (`pdo_mysql`, `mbstring`)
- MySQL **5.7+** or MariaDB **10.2+**

See [README.md](../README.md) and [CHANGELOG.md](../CHANGELOG.md) for full details.

## Install
Download `php-todo-0.98.tar.gz`, extract it, and follow the [README installation steps](../README.md#installation).
