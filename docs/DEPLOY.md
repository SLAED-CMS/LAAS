# Deploy Guide (PHP-FPM + OPcache)

## Zero-surprise deploy (recommended)
1. Enable read-only mode (optional for migrations): `APP_READ_ONLY=true`
2. Backup: `php tools/cli.php backup:create`
3. Deploy code:
   - preferred: atomic symlink switch
   - alternative: rsync to a staging dir
4. Install deps: `composer install --no-dev --optimize-autoloader`
5. Migrations: `php tools/cli.php migrate:up`
6. Templates warmup (if used): `php tools/cli.php templates:warmup`
7. Reload PHP-FPM:
   - `systemctl reload php8.x-fpm`
   - or `service php-fpm reload`
8. Health check: `curl -f https://yourdomain.com/health`
9. Disable read-only mode: `APP_READ_ONLY=false`

## Limited hosting (no reload available)
- Use `opcache.validate_timestamps=1` and `opcache.revalidate_freq=0`.
- Expect higher filesystem checks and slower warm reload.
- This is a compromise; prefer PHP-FPM reload for consistency.

## OPcache reset (CLI only)
- Do not expose any web endpoint for OPcache reset.
- If you have a trusted ops CLI, use it from the server only.
- Otherwise, the only supported method is PHP-FPM reload.

## Notes
- No web-based opcache reset.
- No curl or HTTP-based reset routes.

**Last updated:** January 2026
