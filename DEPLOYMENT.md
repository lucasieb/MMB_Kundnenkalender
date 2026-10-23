# Deployment Notes

## Source of truth and existing website

Work in `main`. The customer calendar on the home page, booking page, and three product pages uses the script and styles restored from `main2` (verified 2026-10-19). The published booking list and admin calendar also use the `main2` scripts; Hostinger adds its own iframe resize script. Updating PHP by SFTP does not update code pasted into the Website Builder. Keep those snippets and their API changes together.

The admin snippets in `Adminbuchungsliste` and `Adminkalender` load `admin_auth.js?v=20261020-mobile1`. Copy both complete snippets into their Website Builder code fields, then publish. Keep the same version in both embeds. The version bypasses older copies held by browsers and Hostinger's CDN; `.htaccess` requires revalidation of this script. After future authentication script changes, update the version in both source copies and both Builder code fields, then publish and verify the live page. Publishing GitHub files alone does not perform this step.

The Builder editor sandboxes its previews with `allow-scripts` only. Its opaque preview origin cannot access the authenticated API or coordinate same-origin frames; verify login on the published website after unlocking its separate guest page gate.

Pushes to `main` deploy automatically after PHP syntax and isolated HTTP contract tests pass. Manual runs are restricted to `main`. After upload the workflow verifies the deployed commit in `deployment.json` and checks `ping.php`. A green upload by itself is not an end-to-end booking or email test.

## Admin accounts

The backend uses `admin_auth.js` with session authentication. Authentication is enabled by default; the Website Builder page password alone does not authenticate PHP requests. Keep `DISABLE_ADMIN_AUTH=false` in production.

The website and API use different sites. To support browsers that block third-party cookies, the token endpoint returns a session bearer consisting of a PHP session ID plus a separate random 256-bit secret. The client holds it only in memory and sends it as `Authorization: Bearer ...` to the configured API origin. Both same-origin embeds share the current bearer; it is never persisted in browser storage or URLs. Login still requires the session's CSRF token and a valid password, and rotates both the session ID and bearer secret. Inactivity and account-configuration changes invalidate both cookie and bearer sessions. Never log Authorization headers or authentication response bodies.

Below 900px the booking table becomes compact cards with one Details action. The detail form keeps its header and footer visible while fields scroll; secondary actions are available through “Weitere Aktionen”. Navigation buttons move between the two embeds. The calendar is a read-only view of bookings and availability blocks; manual blocking and the former local availability override are not enabled.

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

## Booking data release 2026-10-20

Before publishing the new form, preserve a private database backup and run `php migrate_booking_fields.php` from the backend directory over SSH. This CLI-only additive migration is idempotent. It preserves historical rows and does not guess their missing fulfillment choice. Creation fails explicitly if required storage columns are absent.

Copy the complete `Kundenkalender` snippet into all five Builder embeds (home, central booking page and three product pages), and `Adminbuchungsliste` into the internal booking list. Publish the Builder only together with the corresponding PHP release. Required contact/address fields are checked server-side too. Birthdate is optional. Email confirmation verifies reachability, not identity, a contract or booking status; link GETs do not verify automatically. Changing the email revokes its previous verification.

SMTP credentials belong in `mail.private.php` one directory above `public_html`, with permissions 0600. The file returns the SMTP configuration array. It is outside HTTP access, excluded from the repository and retained across deployments. Environment variables remain available when no private file is present. There is no repository password fallback. Use existing mailbox credentials; never put them in logs, issues or commits. Successful SMTP acceptance and actual inbox receipt are separate checks.

The creation response reports customer and internal mail outcomes separately, and stores acceptance timestamps. A mail failure leaves the booking intact and the customer sees a clear warning. No bulk resending, invoice automation or automatic data deletion is introduced by this release.

## Management layout release 2026-10-22

`Adminbuchungsliste` is now a small loader for `admin_list.js` and `admin_list.css`. Copy this complete loader into the existing booking-list Builder element and publish once with this release. The calendar embed and authentication are unchanged. Future management layout changes are deployed centrally; both new assets must revalidate through `.htaccess`. The list retains all existing API operations, filters and detail actions. Additional equipment lines expand locally in the row without changing a booking.


## Gesendet-Ablage
Nach SMTP-Annahme legt der zentrale Mailer dieselbe MIME-Nachricht per IMAP/TLS in `Gesendete Objekte` ab. Es wird keine BCC-/Zusatzmail versendet. Standardserver: `imap.ionos.de:993`; Zugangsdaten aus der bestehenden privaten SMTP-Konfiguration. Abweichungen über `IMAP_HOST`, `IMAP_PORT`, `IMAP_SENT_FOLDER` oder private `imap_username`/`imap_password` konfigurieren. `MAIL_SENT_ARCHIVE_ENABLED=0` deaktiviert die Ablage. PHP-IMAP muss verfügbar sein; die TLS-Zertifikatsprüfung bleibt aktiv.

Ausstehende Kopien liegen mit 0600 in `<Domainverzeichnis>/mail-sent-queue/` (0700), außerhalb von `public_html`. Der bestehende stündliche CLI-Aufruf `booking_reminders.php --send --scheduled` wiederholt ausschließlich die IMAP-Ablage, auch außerhalb des Erinnerungs-Versandfensters. SMTP wird dabei niemals erneut aufgerufen. Globale Dateisperre und Message-ID-Suche verhindern doppelte Kopien nach parallelen Aufrufen oder unterbrochener APPEND-Antwort. Erfolgreiche Kopien werden aus der Queue gelöscht. Archivfehler dürfen einen bereits erfolgreichen SMTP-Versand nicht als fehlgeschlagen melden. CLI-Protokoll `sent_archive.pending`/`failed` überwachen. Historische Mails werden nicht rekonstruiert.
