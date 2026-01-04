# Security Regression Tests

## Purpose
- Fast, deterministic regression suite for security invariants
- No DAST, no CVE scanning, no external services

## How to run

```bash
vendor/bin/phpunit --group security
```

## What is covered
- Auth/session invariants (no user disclosure, cookie flags, login rate limit)
- RBAC and admin access checks
- CSRF enforcement
- XSS regression checks (pages/media/menus/search)
- Upload hardening (MIME, size, SVG, traversal, dedupe)
- SQLi regression for LIKE-based search
- SSRF guardrails for S3 endpoint usage
- Signed URLs validation (invalid/expired/scope)
- Open redirect prevention
- Path traversal/LFI guardrails
- Ops safety (read-only, health safe mode, backup/restore protections)

## What is not covered
- Full browser-based security testing
- Network scanning or third-party integrations
- Performance or load testing

## Adding new security tests
- Place under `tests/Security/`
- Add `@group security` to the test class
- Keep tests isolated and deterministic
- No network calls or real external services
