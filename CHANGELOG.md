# Changelog

## 0.97

A major modernization overhaul. Requires PHP 8.1+ and MySQL 5.7+ / MariaDB 10.2+.

### Security
- Fixed SQL injection in the task editor (checklist queries built from an
  unsanitized `id`).
- Fixed stored XSS: rich-text task details are now sanitized server-side with
  HTMLPurifier on save **and** on output (curing any hostile content stored by
  0.96). Client-side DOMPurify is now UX only.
- CSRF tokens are now validated on the AJAX complete/uncomplete endpoints, which
  previously accepted unauthenticated cross-site POSTs.
- Session ID is regenerated on login (session-fixation defense); session cookies
  set `HttpOnly`, `SameSite=Lax`, and `Secure` (over HTTPS).
- "Remember me" tokens are now stored hashed with a selector/validator scheme,
  expire after 30 days, and rotate on each use (were plaintext and permanent).
- Email verification and email-change tokens now expire (24h), with a resend
  option for expired verification links.
- Database errors are logged, never echoed to the browser.
- The installer refuses to run once an admin exists (previously anyone could
  create a new super-admin against a live install).

### Installer & database
- The installer is now **upgrade-capable**: it detects an existing 0.96 database
  and runs ordered migrations, recording a schema version. Future updates are
  "extract over old files, hit install.php".
- Schema converted to `utf8mb4` (emoji and 4-byte characters now supported).
- `checklist_items` foreign key now uses `ON DELETE CASCADE` (deleting a task
  with checklist items no longer fails).
- Added a unique constraint on group memberships (deduplicated on upgrade).

### Functionality
- Editing a checklist no longer wipes the completion state of items you keep.
- Reminders redesigned: computed in UTC, sent to every group member (not just
  whoever triggered the run first), each formatted in the recipient's timezone.
- Email-change flow simplified to a single verification hop at the new address.

### Architecture & UI
- Restructured from flat procedural pages into a small front-controller app
  (`src/`, `templates/`, `public/`) with shared DB/auth/CSRF/mailer/view
  services — eliminating the copy-pasted boilerplate across every page.
- Replaced PHP's raw `mail()` calls with PHPMailer (SMTP or `mail()`), fixing
  `From:` header injection.
- Replaced the deprecated `FILTER_SANITIZE_STRING` (removed in PHP 8.1) with
  explicit validation — the app now runs on modern PHP.
- Single responsive stylesheet with CSS variables; same dark theme and urgency
  colors, now mobile-friendly. Quill and DOMPurify are vendored locally instead
  of loaded from CDNs (with consistent versions).
- Removed dead code (`send_task_completion_email.php`) and fixed the invalid
  `removeEventListener` / duplicate-script bugs on the task list.

## 0.96

Initial released version.
