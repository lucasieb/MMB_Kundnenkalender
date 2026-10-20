# Deployment Notes

## Source of truth and existing website

Work in `main`. The customer calendar on the home page, booking page, and three product pages uses the script and styles restored from `main2` (verified 2026-10-19). The published booking list and admin calendar also use the `main2` scripts; Hostinger adds its own iframe resize script. Updating PHP by SFTP does not update code pasted into the Website Builder. Keep those snippets and their API changes together.

On 2026-10-20 the two admin snippets were manually updated and published in the Website Builder to load `admin_auth.js?v=56c343a0`. Their source copies are `Adminbuchungsliste` and `Adminkalender`. Keep the same version in both embeds. The version bypasses older copies held by browsers and Hostinger's CDN; `.htaccess` now requires revalidation of this script. After future authentication script changes, update the version in both source copies and both Builder code fields, then publish and verify the live page. Publishing GitHub files alone does not perform this step.

The Builder editor sandboxes its previews with `allow-scripts` only. Its opaque preview origin cannot access the authenticated API or coordinate same-origin frames; verify login on the published website after unlocking its separate guest page gate.

Pushes to `main` deploy automatically after PHP syntax and isolated HTTP contract tests pass. Manual runs are restricted to `main`. After upload the workflow verifies the deployed commit in `deployment.json` and checks `ping.php`. A green upload by itself is not an end-to-end booking or email test.

## Admin accounts

The backend uses `admin_auth.js` with session authentication. Authentication is enabled by default; the Website Builder page password alone does not authenticate PHP requests. Keep `DISABLE_ADMIN_AUTH=false` in production.

The existing server's `admin_users.php` is retained and is deliberately excluded from Git and uploads. For a new installation, copy `admin_users.example.php` to `admin_users.php` on the server and populate password hashes privately. Do not commit real account passwords. An admin login is required after switching from the old password-header backend.

## Validation and rollback

Run `php tests/contract.php` with PHP 8.2 and PDO SQLite. It starts a local HTTP server with a temporary database and mail stub and checks authentication, pickup/shipping/delivery, price and address persistence, legacy payloads, validation and admin updates. It never uses the production database or SMTP account.

Before the 2026-10-19 reconciliation, all live files were downloaded as an unchanged ZIP backup. Deployments preserve server-only files (`delete_remote_files: false`), including credentials and other applications. Do not change this to a blanket deletion. For rollback, restore only the affected runtime files from a known backup or deploy a reviewed revert on `main`; deploying an old branch can mix incompatible files again.

## Environment Configuration

Set the following environment variables on the target system before deploying the application. Sensitive values must **not** be committed to version control. Use the provided `.env.dist` as a template for creating an environment-specific `.env` file or configure the variables directly in your hosting environment.

### Database
- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

### SMTP
- `SMTP_HOST`
- `SMTP_PORT` (default `587`)
- `SMTP_ENCRYPTION` (default `tls`)
- `SMTP_USERNAME`
- `SMTP_PASSWORD`
- `SMTP_FROM_EMAIL` (default `info@mietmichbox.de`)
- `SMTP_FROM_NAME` (default `MIETMICHBOX Team`)
- `SMTP_REPLY_TO` (default `info@mietmichbox.de`)

## Post-Deployment Action

After deploying the changes that move credentials to environment variables, rotate the database and SMTP credentials to invalidate any previously exposed values.
