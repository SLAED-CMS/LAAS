# Security Policy

## Supported Versions

We release security updates for the following versions of LAAS CMS:

| Version | Supported          | Status                  |
| ------- | ------------------ | ----------------------- |
| 2.3.x   | :white_check_mark: | Current stable release  |
| 2.2.x   | :white_check_mark: | Security fixes only     |
| 2.1.x   | :white_check_mark: | Security fixes only     |
| 2.0.x   | :warning:          | Critical fixes only     |
| < 2.0   | :x:                | No longer supported     |

**Recommendation:** Always use the latest stable release (v2.3.x) for the best security posture.

---

## Reporting a Vulnerability

**We take security vulnerabilities seriously.** If you discover a security issue in LAAS CMS, please report it responsibly.

### How to Report

**Preferred Method:**
- Use [GitHub Security Advisories](https://github.com/eduardlaas/laas-cms/security/advisories/new)
- This allows private disclosure and collaboration

**Alternative Method:**
- Email: **eduard.laas@badessen.de**
- Subject: `[SECURITY] Brief description`
- Include: Detailed description, steps to reproduce, impact assessment

### What to Include

To help us address the issue quickly, please include:

1. **Type of vulnerability** (e.g., XSS, SQL injection, CSRF bypass)
2. **Affected version(s)** (e.g., v2.2.5, all versions)
3. **Steps to reproduce** (detailed, step-by-step instructions)
4. **Proof of concept** (code, screenshots, or video)
5. **Impact assessment** (what an attacker could achieve)
6. **Suggested fix** (if you have one)
7. **Your contact information** (for follow-up questions)

### Example Report

```
Subject: [SECURITY] SQL Injection in User Search

Version: LAAS CMS v2.2.5
Type: SQL Injection
Severity: High

Description:
The user search functionality in /admin/users is vulnerable to SQL injection
through the 'q' parameter.

Steps to Reproduce:
1. Log in as admin
2. Navigate to /admin/users
3. Enter the following in search: ' OR 1=1--
4. All users are returned regardless of search term

Impact:
An authenticated attacker with admin.access permission could extract sensitive
data from the database, including password hashes.

Proof of Concept:
[Attached screenshot or code]

Suggested Fix:
Use prepared statements instead of string concatenation in UserRepository::search()
```

### Please Do NOT

- **Publicly disclose** the vulnerability before we've had a chance to address it
- **Exploit the vulnerability** beyond what's necessary to demonstrate it
- **Share the vulnerability** with others before it's fixed
- **Demand compensation** (we appreciate responsible disclosure but cannot offer bounties)

### What to Expect

**We will:**
1. **Acknowledge receipt** within **48 hours** (business days)
2. **Confirm the vulnerability** within **5 business days**
3. **Provide a timeline** for the fix
4. **Keep you updated** on progress
5. **Credit you** in the security advisory (if you wish)

**Timeline:**
- **Critical vulnerabilities** (e.g., RCE, auth bypass): **7 days**
- **High severity** (e.g., XSS, CSRF): **14 days**
- **Medium severity** (e.g., info disclosure): **30 days**
- **Low severity** (e.g., minor issues): **60 days**

---

## Security Features

LAAS CMS is built with security as a first-class concern. Current security features include:

### Authentication & Authorization

- **Password hashing** with Argon2id (64MB memory, 4 iterations)
- **Session regeneration** on login to prevent fixation
- **Session security** (HttpOnly, Secure, SameSite=Strict)
- **Login rate limiting** to prevent brute force attacks
- **API Bearer tokens** with SHA-256 hashing, expiry, and revocation
- **Token rotation** with audit trail
- **RBAC** (Role-Based Access Control) for granular permissions
- **Permission groups** for easier management
- **Admin diagnostics** for permission introspection

### Input Validation & Output Encoding

- **Validation layer** with rules engine (required, email, min, max, etc.)
- **Template auto-escaping** by default (XSS prevention)
- **Prepared statements** for all SQL queries (SQL injection prevention)
- **MIME type validation** for file uploads
- **File size limits** (global and per-MIME type)

### CSRF & Rate Limiting

- **CSRF tokens** for all state-changing operations
- **Token refresh endpoint** (`/csrf`)
- **Rate limiting** middleware with configurable buckets
- **Per-IP and per-token** rate limiting
- **Dedicated API bucket** (60 requests/minute, configurable burst)
- **Login rate limiting** (5 attempts per 5 minutes)
- **Upload rate limiting** for media
- **CORS allowlist** for API v1 (strict origin validation)

### Media Security

- **Quarantine flow** for uploaded files
- **MIME sniffing protection** (magic bytes validation)
- **SHA-256 deduplication** (prevents re-upload of malicious files)
- **ClamAV integration** (optional, feature flag)
- **ZIP-bomb protection** (file size validation)
- **Metadata stripping** from images (privacy)
- **Secure serving headers** (Content-Disposition, X-Content-Type-Options)
- **Signed URLs** for temporary private access
- **Public/private access control**

### Security Headers

- **CSP** (Content Security Policy)
- **X-Frame-Options** (clickjacking prevention)
- **X-Content-Type-Options** (MIME sniffing prevention)
- **Referrer-Policy**
- **Permissions-Policy**

### Audit & Monitoring

- **Audit log** for all important actions (login, RBAC changes, media operations, API tokens)
- **Auth failure tracking** with anti-spam (rate-limited by IP/token prefix)
- **Structured logging** with Monolog (no Authorization headers logged)
- **Request correlation** (X-Request-Id)
- **DevTools panel** (debug mode only, requires permission)
- **Health endpoint** (`/health`) for monitoring

### Operations

- **Read-only mode** for maintenance windows
- **Config sanity checks** on startup
- **Production hardening** (debug disabled, DevTools blocked)
- **Backup/restore** CLI with safety guards
- **Contract tests** to protect architectural invariants

---

## Security Best Practices

### For Developers

**Code:**
- Always use `declare(strict_types=1);`
- Use prepared statements for SQL queries
- Validate all user input (use Validator)
- Escape all output in templates (use auto-escaping)
- Never trust user input, even from admins
- Use readonly properties where possible
- Avoid static state and global variables

**CSRF Protection:**
```html
<!-- All forms must include CSRF token -->
<form method="POST">
    <input type="hidden" name="csrf_token" value="{{ csrf_token() }}">
    <!-- form fields -->
</form>
```

**SQL Queries:**
```php
// Good: prepared statements
$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$userId]);

// Bad: string concatenation
$query = "SELECT * FROM users WHERE id = " . $userId; // NEVER DO THIS
```

**Output Escaping:**
```php
// Good: auto-escaped by default
{{ user.name }}

// Bad: raw output without validation
{{ user.bio|raw }}  // Only use raw() when absolutely necessary
```

**File Uploads:**
```php
// Always validate MIME type
if (!in_array($file['type'], $allowedMimeTypes)) {
    throw new MediaException('Invalid file type');
}

// Always validate file size
if ($file['size'] > $maxSize) {
    throw new MediaException('File too large');
}

// Use quarantine flow
$quarantinePath = $mediaService->quarantine($file);
$mediaService->scan($quarantinePath); // ClamAV
$mediaService->promote($quarantinePath);
```

### For Administrators

**Environment:**
- Set `APP_ENV=production` in production
- Set `APP_DEBUG=false` in production
- Use strong, unique `APP_KEY` in `.env`
- Enable HTTPS for all production deployments
- Configure firewall rules (allow only 80/443)

**Database:**
- Use strong, unique database passwords
- Limit database user permissions (grant only what's needed)
- Enable SSL/TLS for database connections
- Regularly backup the database
- Test backup restore procedures

**Permissions:**
- Follow principle of least privilege
- Review RBAC permissions regularly: `php tools/cli.php rbac:status`
- Use permission groups for easier management
- Audit admin actions: `/admin/audit`
- Use diagnostics to understand effective permissions: `/admin/diagnostics`

**Media:**
- Review media settings in `config/media.php`
- Configure ClamAV if handling untrusted uploads: `MEDIA_CLAMAV_ENABLED=true`
- Set appropriate file size limits
- Use S3/MinIO for production (isolate media from web server)
- Enable signed URLs for private media

**Monitoring:**
- Monitor `/health` endpoint regularly
- Set up log aggregation (ELK, Grafana, etc.)
- Review audit log for suspicious activity
- Monitor rate limit rejections
- Set up alerts for critical errors

**Updates:**
- Subscribe to security advisories
- Test updates on staging before production
- Follow upgrade guide: [UPGRADING.md](UPGRADING.md)
- Keep PHP and database up to date
- Review [docs/SECURITY.md](docs/SECURITY.md) after upgrades

---

## Security Advisories

Past security advisories are published at:
- **GitHub:** [Security Advisories](https://github.com/eduardlaas/laas-cms/security/advisories)
- **Website:** https://laas-cms.org/security

Subscribe to notifications to stay informed about security updates.

---

## Vulnerability Disclosure Policy

**Coordinated Disclosure:**
We follow a coordinated disclosure policy. After we've patched a vulnerability:

1. **We release a security update** with version bump
2. **We publish a security advisory** with:
   - CVE ID (if applicable)
   - Affected versions
   - Fixed versions
   - Severity rating (Critical, High, Medium, Low)
   - Description and impact
   - Credit to reporter (with permission)
3. **We notify users** via:
   - GitHub Security Advisories
   - Release notes
   - Website announcement

**Embargo Period:**
- We ask researchers to wait **90 days** before public disclosure
- We will coordinate with you on a disclosure date
- If we cannot fix within 90 days, we'll work with you on an extension

---

## Security Contact

- **Email:** eduard.laas@badessen.de
- **GitHub:** [Security Advisories](https://github.com/eduardlaas/laas-cms/security/advisories/new)
- **Website:** https://laas-cms.org/security

For general questions about security features, see [docs/SECURITY.md](docs/SECURITY.md).

---

## Hall of Fame

We recognize security researchers who help us improve LAAS CMS:

<!-- Will be updated when we receive vulnerability reports -->

Thank you for helping keep LAAS CMS secure!

---

**Last updated:** January 2026
