# OPCache (Production)

## What is OPcache and why
- Caches compiled PHP bytecode to reduce CPU usage.
- Improves response time and tail latency consistency.
- Reduces filesystem I/O for PHP files.
- Stabilizes performance under load.
- Recommended for any production PHP-FPM setup.

## Recommended settings (PHP-FPM)
Example `opcache.ini`:

```ini
; Enable OPcache in production
opcache.enable=1
opcache.enable_cli=0

; Memory and file limits (adjust to codebase size)
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000

; Revalidation
opcache.validate_timestamps=0
opcache.revalidate_freq=0

; Safety and compatibility
opcache.save_comments=1
opcache.jit=0
opcache.jit_buffer_size=0
opcache.consistency_checks=0

; Optional: file cache for shared storage or read-only containers
; opcache.file_cache=/tmp/php-opcache

; Optional: preload (advanced, only if you control deployment)
; opcache.preload=/var/www/laas/preload.php
; opcache.preload_user=www-data
```

Notes:
- `opcache.enable_cli=0` keeps CLI clean; set to `1` only if you run CLI warmup and want it cached.
- `validate_timestamps=0` is recommended for prod. For limited hosting where reload is not possible, set `validate_timestamps=1` and `revalidate_freq=0`.
- `save_comments=1` is required for reflection and attributes.
- JIT is disabled by default for predictability.

Containers:
- Immutable image: keep `validate_timestamps=0` and reload PHP-FPM on deploy.
- Bind mounts: use `validate_timestamps=1` if reload is not available.

## Security
- Never expose `opcache_reset` or `phpinfo()` in web.
- Consider `disable_functions=opcache_reset,opcache_get_status` in prod if appropriate.
- DevTools must be disabled in production (`APP_DEBUG=false`, `DEVTOOLS_ENABLED=false`).

## Troubleshooting
Symptoms of stale code:
- Changes not reflected after deploy.
- Mixed behavior across processes.

How to check:
- `php -v`
- `php -i | grep opcache`
- `systemctl reload php8.x-fpm`

Do not:
- Add a web endpoint for OPcache reset.
- Run opcache reset from HTTP requests.

