# Deployment Notes

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
