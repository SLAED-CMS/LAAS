# Testing

## Run tests

```bash
vendor/bin/phpunit
```

## Coverage (local)

Coverage requires a driver:
- PCOV (preferred) or Xdebug
- Enable the extension in your local `php.ini` or CLI ini

```bash
vendor/bin/phpunit --coverage-clover coverage/clover.xml --coverage-html coverage/html
```

Coverage output:
- `coverage/clover.xml` (Clover)
- `coverage/html/` (HTML report)

## CI artifacts

- Coverage HTML and Clover XML are uploaded as CI artifacts on the `coverage` job.
- JUnit report is uploaded as `junit` on the `test` job.

## Coverage threshold

- CI enforces a minimum line coverage threshold.
- Default threshold is configured via `COVERAGE_MIN_LINES` in the CI job.

## Critical paths

Coverage focuses on core and critical paths:
- Router dispatch (happy path + 404)
- CSRF middleware allow/deny
- Auth/RBAC middleware allow/deny
- Settings read path (cache hit/miss)
- Media serve headers (nosniff/disposition)
- Health endpoint (200/503)
- Backup inspect (dry-run)
- Migrations status (smoke)
