# Production Deployment Guide

**Complete guide** for deploying LAAS CMS v2.2.3 to production. This document covers environment configuration, security hardening, monitoring, backup strategies, and operational best practices.

---

## Table of Contents

1. [Overview](#overview)
2. [Pre-Deployment Checklist](#pre-deployment-checklist)
3. [Environment Configuration](#environment-configuration)
4. [Web Server Configuration](#web-server-configuration)
5. [HTTPS & TLS](#https--tls)
6. [Security Headers](#security-headers)
7. [Storage & Permissions](#storage--permissions)
8. [Media & Storage Configuration](#media--storage-configuration)
9. [Database Configuration](#database-configuration)
10. [Sessions & Cookies](#sessions--cookies)
11. [Performance & Caching](#performance--caching)
12. [Health Checks & Monitoring](#health-checks--monitoring)
13. [Backups](#backups)
14. [Read-Only Mode](#read-only-mode)
15. [DevTools & Debug Mode](#devtools--debug-mode)
16. [CI/CD Integration](#cicd-integration)
17. [Deployment Workflow](#deployment-workflow)
18. [Smoke Tests](#smoke-tests)
19. [Monitoring & Logging](#monitoring--logging)
20. [Disaster Recovery](#disaster-recovery)
21. [Scaling Considerations](#scaling-considerations)
22. [Troubleshooting](#troubleshooting)
23. [Security Hardening](#security-hardening)

---

## Overview

LAAS CMS is designed to be **production-ready** out of the box, but requires proper configuration and deployment practices to ensure security, performance, and reliability.

**Deployment Goals:**
- **Security:** HTTPS, CSRF protection, rate limiting, security headers
- **Reliability:** Health checks, backups, read-only mode
- **Performance:** Template caching, settings caching, CDN for static assets
- **Observability:** Logging, monitoring, audit trail

**Supported Deployment Environments:**
- **Traditional:** Apache, Nginx, IIS (with PHP-FPM)
- **Cloud:** AWS, DigitalOcean, Azure, Google Cloud
- **Containerized:** Docker, Kubernetes
- **Managed:** Shared hosting (with limitations)

---

## Pre-Deployment Checklist

Before deploying to production, verify:

### Code & Dependencies
- [ ] Latest stable release (v2.2.3 or newer)
- [ ] `composer install --no-dev --optimize-autoloader` executed
- [ ] No uncommitted changes in working directory
- [ ] Version tagged in git

### Environment
- [ ] `.env` file configured for production
- [ ] `APP_ENV=production` set
- [ ] `APP_DEBUG=false` set
- [ ] `DEVTOOLS_ENABLED=false` set
- [ ] Strong `APP_KEY` generated (64+ characters)

### Database
- [ ] Database created and accessible
- [ ] Migrations executed: `php tools/cli.php migrate:up`
- [ ] Database credentials secured (not in version control)
- [ ] Database user has minimal required privileges

### Storage
- [ ] `storage/` directory writable by web server
- [ ] `storage/uploads/` not publicly accessible
- [ ] S3/MinIO configured if using cloud storage
- [ ] Media security settings reviewed

### Security
- [ ] HTTPS configured and tested
- [ ] Security headers configured
- [ ] CSRF protection enabled (default)
- [ ] Rate limiting configured
- [ ] RBAC permissions reviewed

### Monitoring
- [ ] `/health` endpoint accessible
- [ ] Monitoring configured for health endpoint
- [ ] Log aggregation configured
- [ ] Error alerting configured

### Backups
- [ ] Backup strategy defined
- [ ] Automated backups scheduled
- [ ] Backup restore tested
- [ ] Off-site backup storage configured

---

## Environment Configuration

### .env File

Copy `.env.example` to `.env` and configure:

```bash
# Application
APP_ENV=production
APP_DEBUG=false
APP_KEY=your-strong-random-key-min-64-chars-recommended-128
APP_URL=https://yourdomain.com
APP_READ_ONLY=false

# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=laas_production
DB_USER=laas_user
DB_PASS=strong-database-password

# Storage
STORAGE_DISK=local
# For S3/MinIO:
# STORAGE_DISK=s3
# S3_BUCKET=my-bucket
# S3_REGION=us-east-1
# S3_KEY=your-access-key
# S3_SECRET=your-secret-key
# S3_ENDPOINT=https://s3.amazonaws.com

# Media
MEDIA_CLAMAV_ENABLED=false
# Set to true if ClamAV is installed and configured

# Sessions
SESSION_LIFETIME=7200

# Logging
LOG_LEVEL=warning

# DevTools (MUST be disabled in production)
DEVTOOLS_ENABLED=false
```

### Critical Settings

**APP_ENV:**
- **production:** Optimized for production (error suppression, caching)
- **development:** Developer-friendly (verbose errors, no caching)

**APP_DEBUG:**
- **false (production):** Hide error details from users
- **true (development only):** Show detailed error messages

**APP_KEY:**
- Generate with: `openssl rand -base64 64`
- Keep secret, never commit to version control
- Rotate periodically (requires session invalidation)

**APP_READ_ONLY:**
- **false (normal operation):** Write operations allowed
- **true (maintenance):** All write operations blocked

---

## Web Server Configuration

### Nginx (Recommended)

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    root /var/www/laas/public;
    index index.php;

    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # Security Headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;

    # Hide server version
    server_tokens off;

    # Client upload limits
    client_max_body_size 100M;
    client_body_timeout 120s;

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ ^/(config|modules|src|storage|tools|vendor) {
        deny all;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # Increase timeouts for long-running operations
        fastcgi_read_timeout 300s;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Cache static assets
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Logging
    access_log /var/log/nginx/laas_access.log;
    error_log /var/log/nginx/laas_error.log warn;
}
```

### Apache

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    Redirect permanent / https://yourdomain.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName yourdomain.com
    DocumentRoot /var/www/laas/public

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/yourdomain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/yourdomain.com/privkey.pem
    SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
    SSLCipherSuite HIGH:!aNULL:!MD5

    <Directory /var/www/laas/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        # Rewrite rules (if mod_rewrite enabled)
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [QSA,L]
    </Directory>

    # Security Headers
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    # Deny access to sensitive directories
    <DirectoryMatch "^/(config|modules|src|storage|tools|vendor)">
        Require all denied
    </DirectoryMatch>

    # Hide .htaccess and .env
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/laas_error.log
    CustomLog ${APACHE_LOG_DIR}/laas_access.log combined
</VirtualHost>
```

---

## HTTPS & TLS

### Why HTTPS is Required

- **Session security:** Prevents session hijacking
- **CSRF protection:** Secure cookies require HTTPS
- **Data encryption:** Protects passwords and sensitive data
- **SEO:** Google favors HTTPS sites
- **Trust:** Browser warnings for non-HTTPS sites

### Obtaining SSL Certificates

**Let's Encrypt (Free, Recommended):**
```bash
# Install certbot
sudo apt install certbot python3-certbot-nginx

# Obtain certificate
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com

# Auto-renewal (cron job)
sudo certbot renew --dry-run
```

**Commercial CA:**
- Purchase from DigiCert, Comodo, etc.
- Generate CSR: `openssl req -new -newkey rsa:2048 -nodes -keyout yourdomain.key -out yourdomain.csr`
- Submit CSR to CA
- Install certificate on web server

### HSTS (HTTP Strict Transport Security)

**Enable HSTS after HTTPS is stable:**

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
```

**Warning:** Only enable after:
1. HTTPS is fully functional
2. All subdomains support HTTPS (if using `includeSubDomains`)
3. You're committed to HTTPS (HSTS cannot be easily reverted)

**HSTS Preload:**
- Submit to https://hstspreload.org/
- Browser will enforce HTTPS before first visit
- Requires: `max-age=31536000`, `includeSubDomains`, `preload`

### TLS Configuration

**Protocols:**
- **Enable:** TLSv1.2, TLSv1.3
- **Disable:** SSLv3, TLSv1, TLSv1.1 (deprecated, insecure)

**Ciphers:**
```
ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384
```

**Test with:**
```bash
# Check TLS configuration
openssl s_client -connect yourdomain.com:443 -tls1_2

# SSL Labs scan
https://www.ssllabs.com/ssltest/analyze.html?d=yourdomain.com
```

---

## Security Headers

### Content Security Policy (CSP)

**Start with a restrictive policy and relax as needed:**

```nginx
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'" always;
```

**Breakdown:**
- `default-src 'self'` — Only load resources from same origin
- `script-src 'self' 'unsafe-inline'` — Scripts from same origin + inline scripts (HTMX uses inline)
- `style-src 'self' 'unsafe-inline'` — Styles from same origin + inline styles
- `img-src 'self' data:` — Images from same origin + data URIs
- `frame-ancestors 'self'` — Only allow framing by same origin

**Testing:**
1. Enable CSP in report-only mode:
   ```nginx
   add_header Content-Security-Policy-Report-Only "..." always;
   ```
2. Monitor browser console for violations
3. Adjust policy as needed
4. Switch to enforcement mode

### Other Security Headers

```nginx
# Prevent clickjacking
add_header X-Frame-Options "SAMEORIGIN" always;

# Prevent MIME sniffing
add_header X-Content-Type-Options "nosniff" always;

# Referrer policy
add_header Referrer-Policy "strict-origin-when-cross-origin" always;

# Permissions policy (disable unused features)
add_header Permissions-Policy "geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()" always;

# Hide server version
Server: (blank)
```

---

## Storage & Permissions

### File Permissions

**Recommended ownership:**
```bash
# Web server user (nginx/apache)
sudo chown -R www-data:www-data /var/www/laas

# Writable directories
sudo chmod 775 storage storage/logs storage/sessions storage/cache storage/uploads storage/backups

# Files
sudo chmod 644 .env
sudo chmod 644 config/*.php
```

**Security:**
- `.env` should NOT be world-readable (no `chmod 777`)
- `storage/` should NOT be accessible via web server
- `public/` is the only publicly accessible directory

### Storage Directory Structure

```
storage/
├── logs/              # Application logs (writable)
├── sessions/          # PHP sessions (writable)
├── cache/             # File cache (writable)
│   ├── data/          # Settings, menus
│   └── templates/     # Compiled templates
├── uploads/           # Local media storage (writable, PRIVATE)
└── backups/           # Database backups (writable, PRIVATE)
```

### Never Expose Storage Publicly

**Bad (DO NOT DO):**
```nginx
# This exposes uploads directly - INSECURE
location /uploads {
    alias /var/www/laas/storage/uploads;
}
```

**Good:**
- Media is served via `/media/{hash}.{ext}` route
- Controller validates permissions and security headers
- Direct filesystem access is blocked

---

## Media & Storage Configuration

### Local Storage

**Default configuration:**
```env
STORAGE_DISK=local
```

**Location:** `storage/uploads/`

**Pros:**
- Simple setup
- No external dependencies
- Low latency

**Cons:**
- Not horizontally scalable
- Single point of failure
- Backup complexity

### S3/MinIO Storage

**Configuration:**
```env
STORAGE_DISK=s3
S3_BUCKET=my-laas-media
S3_REGION=us-east-1
S3_KEY=AKIAIOSFODNN7EXAMPLE
S3_SECRET=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
S3_ENDPOINT=https://s3.amazonaws.com
```

**Pros:**
- Horizontally scalable
- High availability
- Automatic backups (depending on provider)
- CDN integration

**Cons:**
- External dependency
- Cost (storage + transfer)
- Latency (network round-trip)

**Security:**
- Use **private buckets** (no public read/write)
- Use IAM roles with minimal permissions
- Enable server-side encryption
- Use signed URLs for temporary access

### Media Security Settings

**Review `config/media.php`:**

```php
return [
    'quarantine_enabled' => true,
    'max_file_size' => 100 * 1024 * 1024, // 100MB
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ],
    'per_mime_limits' => [
        'image/jpeg' => 20 * 1024 * 1024, // 20MB
        'application/pdf' => 50 * 1024 * 1024, // 50MB
    ],
    'clamav_enabled' => env('MEDIA_CLAMAV_ENABLED', false),
    'clamav_socket' => '/var/run/clamav/clamd.ctl',
];
```

**ClamAV Integration (Optional):**
```bash
# Install ClamAV
sudo apt install clamav clamav-daemon

# Update virus definitions
sudo freshclam

# Enable in .env
MEDIA_CLAMAV_ENABLED=true
```

---

## Database Configuration

### Connection Settings

**Production database user:**
```sql
-- Create dedicated database user
CREATE USER 'laas_prod'@'localhost' IDENTIFIED BY 'strong-password';

-- Grant minimal privileges
GRANT SELECT, INSERT, UPDATE, DELETE ON laas_production.* TO 'laas_prod'@'localhost';

-- NO GRANT for DROP, CREATE, ALTER (use migration account for schema changes)
FLUSH PRIVILEGES;
```

### Connection Pooling

**For high-traffic sites, use persistent connections:**

```php
// config/database.php
return [
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_NAME'),
            'username' => env('DB_USER'),
            'password' => env('DB_PASS'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [
                PDO::ATTR_PERSISTENT => true, // Enable persistent connections
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        ],
    ],
];
```

### Database Backups

**Automated daily backups:**
```bash
# Cron job (daily at 2 AM)
0 2 * * * /usr/bin/php /var/www/laas/tools/cli.php backup:create

# Retention policy (keep last 30 days)
0 3 * * * find /var/www/laas/storage/backups -name "*.sql.gz" -mtime +30 -delete
```

---

## Sessions & Cookies

### Session Configuration

**Production settings:**
```php
// config/session.php (or .env)
session.cookie_httponly = 1
session.cookie_secure = 1     // Require HTTPS
session.cookie_samesite = Lax // Or Strict
session.use_strict_mode = 1
session.gc_maxlifetime = 7200  // 2 hours
```

### Cookie Security

**All cookies must be:**
- **HttpOnly:** Prevents JavaScript access (XSS protection)
- **Secure:** Only sent over HTTPS
- **SameSite:** `Lax` (CSRF protection) or `Strict` (stricter, may break some flows)

**Example:**
```php
setcookie('session_id', $value, [
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Lax',
    'expires' => time() + 7200,
    'path' => '/',
]);
```

---

## Performance & Caching

**OPcache required:** See [docs/OPCACHE.md](OPCACHE.md).

### Template Caching

**Always enable in production:**
```bash
# Warmup cache after deployment
php tools/cli.php templates:warmup

# Templates are cached in storage/cache/templates/
```

### Settings & Menu Caching

**Automatic caching (default TTL: 300s):**
- Settings are cached after first load
- Menus are cached after first load
- Cache is invalidated on update

**Manual cache clear:**
```bash
php tools/cli.php cache:clear
```

### Opcache

**Enable PHP opcache for significant performance gains:**

```ini
; php.ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
```

For production-safe configuration and deploy behavior, see [docs/OPCACHE.md](OPCACHE.md).

### CDN for Static Assets

**Serve static assets from CDN:**
```nginx
# Cache static files locally
location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}
```

**Or use a CDN:**
- Cloudflare (free tier available)
- AWS CloudFront
- Fastly

---

## Health Checks & Monitoring

### Health Endpoint

**URL:** `/health`

**Returns:**
```json
{
  "status": "healthy",
  "timestamp": "2026-01-03T12:00:00+00:00",
  "checks": {
    "database": "ok",
    "storage": "ok"
  }
}
```

**Use for:**
- Load balancer health checks
- Uptime monitoring
- Readiness probes (Kubernetes)

### Monitoring Setup

**Monitor the health endpoint:**
```bash
# Curl check (every 60 seconds)
*/1 * * * * curl -f https://yourdomain.com/health || echo "Health check failed"
```

**Monitoring services:**
- UptimeRobot (free tier available)
- Pingdom
- StatusCake
- AWS CloudWatch
- Datadog

### Alerts

**Set up alerts for:**
- Health endpoint returns non-200 status
- Response time > 5 seconds
- High error rate in logs
- Disk space < 10% free
- Database connection failures

---

## Backups

### Automated Backups

**Schedule daily backups:**
```bash
# Cron job: daily at 2 AM
0 2 * * * /usr/bin/php /var/www/laas/tools/cli.php backup:create
```

**Backup includes:**
- Full database dump (all tables)
- Compressed with gzip
- Timestamped filename

### Backup Storage

**Local storage:**
- Location: `storage/backups/`
- Retention: 30 days (recommended)

**Off-site storage (recommended):**
```bash
# Upload to S3 after backup
0 2 * * * /usr/bin/php /var/www/laas/tools/cli.php backup:create && \
  aws s3 cp storage/backups/*.sql.gz s3://my-backup-bucket/laas/
```

### Backup Testing

**Test restore procedure quarterly:**
```bash
# List backups
php tools/cli.php backup:list

# Inspect backup
php tools/cli.php backup:inspect storage/backups/backup_2026-01-03_020000.sql.gz

# Restore (DESTRUCTIVE - use on staging only)
php tools/cli.php backup:restore storage/backups/backup_2026-01-03_020000.sql.gz
```

**Never test restore on production** — use staging environment.

---

## Read-Only Mode

### When to Use

- **Maintenance windows:** During risky operations
- **Database migrations:** Prevent data corruption
- **Incident response:** Stop write operations during investigation
- **Backup restore:** Prevent concurrent writes

### Enable Read-Only Mode

```bash
# In .env
APP_READ_ONLY=true
```

**Or via CLI:**
```bash
# Enable
echo "APP_READ_ONLY=true" >> .env

# Disable
sed -i 's/APP_READ_ONLY=true/APP_READ_ONLY=false/' .env
```

### What It Does

**Blocks all write operations:**
- Form submissions (POST, PUT, DELETE)
- API mutations
- File uploads
- Database writes

**Allows read operations:**
- Page views
- API queries
- Health checks

**User experience:**
- Forms display warning banner
- Write buttons are disabled
- API returns HTTP 503 (Service Unavailable)

---

## DevTools & Debug Mode

### Production Rules

**NEVER enable in production:**
```env
APP_DEBUG=false
DEVTOOLS_ENABLED=false
```

**Why:**
- **Security:** Debug mode exposes sensitive information (stack traces, queries, environment variables)
- **Performance:** DevTools adds overhead
- **Privacy:** Exposes internal system details

### Debug Toolbar Access

**If you must debug in production (emergency only):**
1. Enable DevTools: `DEVTOOLS_ENABLED=true`
2. Ensure user has `debug.view` permission
3. Debug toolbar appears at bottom of page
4. **Disable immediately after debugging**

**Better approach:** Replicate issue on staging environment.

---

## CI/CD Integration

### GitHub Actions Example

```yaml
name: Deploy to Production

on:
  push:
    tags:
      - 'v*'

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'

      - name: Install dependencies
        run: composer install --no-dev --optimize-autoloader

      - name: Run tests
        run: vendor/bin/phpunit

      - name: Deploy to server
        run: |
          ssh user@production-server "cd /var/www/laas && git pull && composer install --no-dev --optimize-autoloader && php tools/cli.php migrate:up && php tools/cli.php cache:clear"
```

---

## Deployment Workflow

### Standard Deployment

```bash
# 1. Pull latest code
git pull origin main

# 2. Install dependencies (production)
composer install --no-dev --optimize-autoloader

# 3. Run migrations
php tools/cli.php migrate:up

# 4. Clear caches
php tools/cli.php cache:clear

# 5. Warmup template cache
php tools/cli.php templates:warmup

# 6. Reload web server
sudo systemctl reload nginx
```

### Zero-Downtime Deployment

```bash
# 1. Enable read-only mode
echo "APP_READ_ONLY=true" >> .env

# 2. Deploy new code
# ... standard deployment steps ...

# 3. Disable read-only mode
sed -i 's/APP_READ_ONLY=true/APP_READ_ONLY=false/' .env
```

---

## Smoke Tests

### Ops Check Command

```bash
php tools/cli.php ops:check
```

**Runs:**
- Database connectivity test
- Storage write test
- Health endpoint test
- Configuration sanity checks

**Use in CI/CD:**
```yaml
- name: Run smoke tests
  run: php tools/cli.php ops:check
```

---

## Monitoring & Logging

### Log Levels

**Production:**
```env
LOG_LEVEL=warning
```

**Levels:**
- `debug` — Verbose (development only)
- `info` — Informational messages
- `warning` — Warnings (recommended for production)
- `error` — Errors only
- `critical` — Critical errors only

### Log Aggregation

**Recommended tools:**
- **ELK Stack:** Elasticsearch, Logstash, Kibana
- **Grafana Loki:** Lightweight alternative
- **Sentry:** Error tracking and monitoring
- **CloudWatch Logs:** AWS-native solution

### Audit Log

**Review audit log regularly:**
- `/admin/audit` — Web UI
- Filter by action: `rbac.*`, `user.login`, `media.upload`
- Look for suspicious activity

---

## Disaster Recovery

### Recovery Plan

1. **Identify issue** (monitoring alert, user report)
2. **Enable read-only mode** (prevent further damage)
3. **Assess impact** (check logs, database, backups)
4. **Restore from backup** (if needed)
5. **Test restored system**
6. **Disable read-only mode**
7. **Post-mortem** (document incident, improve monitoring)

### RTO & RPO

**Define your targets:**
- **RTO (Recovery Time Objective):** How long can you be down?
- **RPO (Recovery Point Objective):** How much data can you afford to lose?

**Example:**
- RTO: 1 hour (must restore within 1 hour)
- RPO: 24 hours (daily backups acceptable)

---

## Scaling Considerations

### Horizontal Scaling

**Current limitations:**
- File-based sessions (use database or Redis sessions)
- Local storage (use S3/MinIO)
- File-based cache (use Redis/Memcached)

**To scale horizontally:**
1. Move sessions to database or Redis
2. Move storage to S3/MinIO
3. Use Redis/Memcached for caching
4. Use load balancer with sticky sessions (if needed)

---

## Troubleshooting

### Common Issues

**500 Internal Server Error:**
- Check logs: `storage/logs/`
- Verify permissions: `storage/` writable
- Check `.env` configuration

**Session issues:**
- Verify `session.cookie_secure=1` only if HTTPS enabled
- Check session directory: `storage/sessions/` writable

**Database connection failed:**
- Check credentials in `.env`
- Verify database server is running
- Check firewall rules

---

## Security Hardening

### Principle of Least Privilege

- Database user: minimal permissions
- Web server user: write access only to `storage/`
- RBAC: grant only necessary permissions

### Regular Updates

- PHP: keep up to date with security patches
- Database: apply security updates
- Dependencies: `composer update` regularly
- Review security advisories: [SECURITY.md](../SECURITY.md)

---

**Last updated:** January 2026
