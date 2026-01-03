# Audit Log

**Comprehensive audit trail** for tracking all important system actions. The audit log records user activities, security events, and administrative operations for compliance, debugging, and security monitoring.

---

## Table of Contents

1. [Overview](#overview)
2. [What Is Logged](#what-is-logged)
3. [Audit Events Reference](#audit-events-reference)
4. [Viewing the Audit Log](#viewing-the-audit-log)
5. [Filtering & Search](#filtering--search)
6. [Audit Context](#audit-context)
7. [Database Schema](#database-schema)
8. [CLI Access](#cli-access)
9. [Security Considerations](#security-considerations)
10. [Retention & Cleanup](#retention--cleanup)
11. [Use Cases](#use-cases)
12. [Best Practices](#best-practices)
13. [Troubleshooting](#troubleshooting)

---

## Overview

The audit log provides a **complete, immutable record** of all important system actions.

**Purpose:**
- **Compliance:** Meet regulatory requirements (GDPR, SOC 2, etc.)
- **Security:** Detect unauthorized access and suspicious activity
- **Debugging:** Investigate issues and understand system state changes
- **Accountability:** Track who did what and when

**Key Features:**
- **Automatic logging** of all critical actions
- **Immutable records** (cannot be edited or deleted via UI)
- **Structured context** (JSON metadata for each event)
- **Filtering & search** (by user, action, date range)
- **Pagination** for large datasets

**Introduced in:** v1.6.0

**Enhanced in:**
- v1.15.0: Filters and date ranges
- v2.2.0: RBAC diagnostics logging

---

## What Is Logged

### Automatic Logging

The following actions are **automatically logged** without additional code:

**Authentication:**
- User login (success/failure)
- User logout
- Session regeneration

**RBAC (Role-Based Access Control):**
- Role created/updated/deleted
- Role permissions changed
- Role cloned
- User roles updated
- RBAC diagnostics viewed

**Media:**
- Media uploaded
- Media deleted
- Media quarantine status changed
- Media scan completed (ClamAV)

**Pages:**
- Page created
- Page updated
- Page deleted

**Menus:**
- Menu created
- Menu updated
- Menu deleted

**Users:**
- User created
- User updated
- User deleted

**Settings:**
- System settings changed

### Manual Logging

Developers can log custom events:

```php
use App\Audit\AuditLogger;

// Simple log
AuditLogger::log('custom.action', $userId);

// With context
AuditLogger::log('custom.action', $userId, [
    'resource_id' => 123,
    'details' => 'Custom event details',
]);
```

---

## Audit Events Reference

### Authentication Events

| Action | Description | Context |
|--------|-------------|---------|
| `user.login` | User successfully logged in | `username`, `ip_address` |
| `user.login.failed` | Login attempt failed | `username`, `ip_address`, `reason` |
| `user.logout` | User logged out | `username` |

**Example:**
```json
{
  "action": "user.login",
  "user_id": 1,
  "username": "admin",
  "context": {
    "ip_address": "192.168.1.100",
    "user_agent": "Mozilla/5.0..."
  },
  "created_at": "2026-01-03 14:30:00"
}
```

### RBAC Events

| Action | Description | Context |
|--------|-------------|---------|
| `rbac.role.created` | New role created | `role_id`, `role_name` |
| `rbac.role.updated` | Role name/description changed | `role_id`, `role_name`, `changes` |
| `rbac.role.deleted` | Role deleted | `role_id`, `role_name` |
| `rbac.role.permissions.updated` | Role permissions changed | `role_id`, `added`, `removed` |
| `rbac.role.cloned` | Role cloned | `original_role_id`, `new_role_id` |
| `rbac.user.roles.updated` | User roles changed | `target_user_id`, `added_roles`, `removed_roles` |
| `rbac.diagnostics.viewed` | RBAC diagnostics page viewed | `target_user_id` |

**Example:**
```json
{
  "action": "rbac.role.permissions.updated",
  "user_id": 1,
  "username": "admin",
  "context": {
    "role_id": 3,
    "role_name": "Editor",
    "added": ["media.delete"],
    "removed": ["pages.delete"]
  },
  "created_at": "2026-01-03 15:45:00"
}
```

### Media Events

| Action | Description | Context |
|--------|-------------|---------|
| `media.uploaded` | Media file uploaded | `media_id`, `filename`, `mime_type`, `size` |
| `media.deleted` | Media file deleted | `media_id`, `filename`, `hash` |
| `media.quarantined` | File placed in quarantine | `media_id`, `reason` |
| `media.promoted` | File promoted from quarantine | `media_id` |
| `media.scan.completed` | ClamAV scan completed | `media_id`, `result` |

**Example:**
```json
{
  "action": "media.uploaded",
  "user_id": 2,
  "username": "editor",
  "context": {
    "media_id": 42,
    "filename": "photo.jpg",
    "mime_type": "image/jpeg",
    "size": 2048576,
    "hash": "abc123..."
  },
  "created_at": "2026-01-03 16:00:00"
}
```

### Pages Events

| Action | Description | Context |
|--------|-------------|---------|
| `pages.created` | Page created | `page_id`, `title`, `slug` |
| `pages.updated` | Page updated | `page_id`, `changes` |
| `pages.deleted` | Page deleted | `page_id`, `title` |

**Example:**
```json
{
  "action": "pages.created",
  "user_id": 2,
  "username": "editor",
  "context": {
    "page_id": 15,
    "title": "New Article",
    "slug": "new-article"
  },
  "created_at": "2026-01-03 10:00:00"
}
```

### Menus Events

| Action | Description | Context |
|--------|-------------|---------|
| `menus.created` | Menu created | `menu_id`, `name` |
| `menus.updated` | Menu updated | `menu_id`, `changes` |
| `menus.deleted` | Menu deleted | `menu_id`, `name` |

### Users Events

| Action | Description | Context |
|--------|-------------|---------|
| `users.created` | User created | `target_user_id`, `username`, `email` |
| `users.updated` | User updated | `target_user_id`, `changes` |
| `users.deleted` | User deleted | `target_user_id`, `username` |

### Settings Events

| Action | Description | Context |
|--------|-------------|---------|
| `settings.updated` | System settings changed | `setting_key`, `old_value`, `new_value` |

---

## Viewing the Audit Log

### Admin UI

**URL:** `/admin/audit`

**Required Permission:** `audit.view`

**Features:**
- Paginated list of all audit events
- Newest events first
- Filters: user, action, date range
- Sortable columns
- Context preview (JSON)

**Interface:**
```
┌─────────────────────────────────────────────────────────────┐
│ Audit Log                                                   │
├─────────────────────────────────────────────────────────────┤
│ Filters:                                                    │
│ [ User: _______ ] [ Action: _______ ]                      │
│ [ From: _______ ] [ To: _______ ]      [Apply Filters]     │
├─────────────────────────────────────────────────────────────┤
│ Timestamp           User    Action              Context    │
├─────────────────────────────────────────────────────────────┤
│ 2026-01-03 16:00   editor  media.uploaded      {...}      │
│ 2026-01-03 15:45   admin   rbac.role.updated   {...}      │
│ 2026-01-03 14:30   admin   user.login          {...}      │
│ ...                                                         │
├─────────────────────────────────────────────────────────────┤
│ [Prev] Page 1 of 10 [Next]                                 │
└─────────────────────────────────────────────────────────────┘
```

---

## Filtering & Search

### Filter by User

**Admin UI:**
1. Go to `/admin/audit`
2. Enter username in "User" filter
3. Click "Apply Filters"

**URL parameter:**
```
/admin/audit?user=admin
```

**Results:** Only events by user `admin`

### Filter by Action

**Admin UI:**
1. Go to `/admin/audit`
2. Enter action in "Action" filter (e.g., `rbac.*`, `user.login`)
3. Click "Apply Filters"

**URL parameter:**
```
/admin/audit?action=rbac.*
```

**Results:** All RBAC-related events

**Wildcard support:**
- `rbac.*` — All RBAC events
- `*.created` — All creation events
- `user.login*` — All login events (success + failure)

### Filter by Date Range

**Admin UI:**
1. Go to `/admin/audit`
2. Select "From" date (e.g., `2026-01-01`)
3. Select "To" date (e.g., `2026-01-31`)
4. Click "Apply Filters"

**URL parameters:**
```
/admin/audit?from=2026-01-01&to=2026-01-31
```

**Date format:** `YYYY-MM-DD`

**Invalid ranges:** Return HTTP 422 (Unprocessable Entity)

**Examples:**
- `from=2026-01-01&to=2025-12-31` — Invalid (to < from)
- `from=2026-99-99` — Invalid (malformed date)

### Combined Filters

**Example:** All RBAC events by user `admin` in January 2026:
```
/admin/audit?user=admin&action=rbac.*&from=2026-01-01&to=2026-01-31
```

**Filters are AND-ed:**
- User = admin **AND**
- Action matches `rbac.*` **AND**
- Date between Jan 1 and Jan 31

---

## Audit Context

Each audit event includes a **context** field (JSON) with event-specific details.

### Context Structure

**Common fields:**
- `user_id` — User who performed the action (nullable)
- `username` — Username (nullable)
- `ip_address` — Client IP address (when applicable)

**Event-specific fields:** Varies by event type

### Example Contexts

**Login:**
```json
{
  "ip_address": "192.168.1.100",
  "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
}
```

**RBAC role permissions updated:**
```json
{
  "role_id": 3,
  "role_name": "Editor",
  "added": ["media.delete"],
  "removed": ["pages.delete"]
}
```

**Media upload:**
```json
{
  "media_id": 42,
  "filename": "photo.jpg",
  "mime_type": "image/jpeg",
  "size": 2048576,
  "hash": "abc123def456"
}
```

**Page updated:**
```json
{
  "page_id": 15,
  "changes": {
    "title": {"old": "Old Title", "new": "New Title"},
    "content": {"old": "...", "new": "..."}
  }
}
```

---

## Database Schema

### Table: `audit_log`

```sql
CREATE TABLE audit_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    username VARCHAR(255) NULL,
    action VARCHAR(255) NOT NULL,
    context JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
);
```

**Fields:**
- `id` — Auto-increment primary key
- `user_id` — User who performed the action (nullable for system events)
- `username` — Denormalized username (for deleted users)
- `action` — Event type (e.g., `user.login`, `rbac.role.created`)
- `context` — JSON metadata (event-specific)
- `ip_address` — Client IP address (nullable)
- `created_at` — Timestamp (UTC)

**Indexes:**
- `idx_user_id` — Fast filtering by user
- `idx_action` — Fast filtering by action
- `idx_created_at` — Fast date range queries

### Querying Directly (SQL)

**All events by user `admin`:**
```sql
SELECT * FROM audit_log
WHERE username = 'admin'
ORDER BY created_at DESC;
```

**All RBAC events:**
```sql
SELECT * FROM audit_log
WHERE action LIKE 'rbac.%'
ORDER BY created_at DESC;
```

**Events in date range:**
```sql
SELECT * FROM audit_log
WHERE created_at BETWEEN '2026-01-01' AND '2026-01-31 23:59:59'
ORDER BY created_at DESC;
```

**Extract context field:**
```sql
SELECT
    action,
    username,
    JSON_EXTRACT(context, '$.role_name') AS role_name,
    created_at
FROM audit_log
WHERE action = 'rbac.role.permissions.updated';
```

---

## CLI Access

### Export Audit Log

**Export to JSON:**
```bash
# Export all audit events
php tools/cli.php audit:export --output=audit_export.json

# Export filtered events
php tools/cli.php audit:export --user=admin --action=rbac.* --output=rbac_audit.json
```

**Export to CSV:**
```bash
php tools/cli.php audit:export --format=csv --output=audit_export.csv
```

### Query Audit Log

**Recent events:**
```bash
php tools/cli.php audit:query --limit=10
```

**Filter by user:**
```bash
php tools/cli.php audit:query --user=admin --limit=50
```

**Filter by action:**
```bash
php tools/cli.php audit:query --action=user.login --limit=20
```

**Date range:**
```bash
php tools/cli.php audit:query --from=2026-01-01 --to=2026-01-31
```

---

## Security Considerations

### Immutability

**Audit log records are immutable:**
- Cannot be edited via Admin UI
- Cannot be deleted via Admin UI (only database access)
- Ensures integrity for compliance and forensics

**Why:**
- Prevents tampering
- Maintains audit trail integrity
- Required for compliance (SOC 2, GDPR, etc.)

### Access Control

**Viewing audit log requires:**
- Permission: `audit.view`
- Typically granted to administrators only

**Best practice:**
- Limit `audit.view` to trusted admins
- Review who has this permission regularly: `php tools/cli.php rbac:status`

### Sensitive Data

**Audit log may contain:**
- Usernames
- IP addresses
- Resource IDs
- Change diffs (including potentially sensitive data)

**Protect audit log:**
- Database access control
- Encrypted backups
- Secure log exports
- Review retention policy

### IP Address Logging

**IP addresses are logged for:**
- Login attempts
- Administrative actions

**Privacy considerations:**
- IP addresses are **personal data** (GDPR)
- Review local privacy laws
- Implement retention policy
- Consider pseudonymization for long-term storage

---

## Retention & Cleanup

### Default Retention

**No automatic cleanup** — audit log grows indefinitely.

**Recommended retention:**
- **Compliance requirements:** 1-7 years (depends on regulation)
- **Operational:** 90-365 days
- **Security incidents:** Permanent (for critical events)

### Manual Cleanup

**Delete old records (SQL):**
```sql
-- Delete records older than 1 year
DELETE FROM audit_log
WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

**Warning:** Ensure compliance requirements allow deletion.

### Automated Cleanup (Cron)

**Example: Delete records older than 1 year:**
```bash
# Cron job (monthly)
0 0 1 * * mysql -u user -p database -e "DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);"
```

**Better approach:** Archive before deleting
```bash
# 1. Export old records
php tools/cli.php audit:export --to=$(date -d '1 year ago' +%Y-%m-%d) --output=archive_$(date +%Y).json

# 2. Verify export
wc -l archive_$(date +%Y).json

# 3. Delete old records
mysql -u user -p database -e "DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);"
```

---

## Use Cases

### 1. Security Incident Investigation

**Scenario:** Unauthorized user deletion detected.

**Steps:**
1. Go to `/admin/audit`
2. Filter: `action=users.deleted`
3. Review who deleted which users
4. Check context for justification
5. Cross-reference with `user.login` to identify compromised account

**Example query:**
```
/admin/audit?action=users.deleted
```

### 2. Compliance Audit

**Scenario:** SOC 2 audit requires proof of access control changes.

**Steps:**
1. Export RBAC events:
   ```bash
   php tools/cli.php audit:export --action=rbac.* --from=2025-01-01 --to=2025-12-31 --output=rbac_2025.json
   ```
2. Provide `rbac_2025.json` to auditors
3. Document RBAC change process in runbook

### 3. Debugging User Issues

**Scenario:** User reports "I can't access pages anymore."

**Steps:**
1. Go to `/admin/audit`
2. Filter: `user=john` (the affected user)
3. Look for `rbac.user.roles.updated`
4. Check context for `removed_roles`
5. Restore role if removed in error

**Example:**
```json
{
  "action": "rbac.user.roles.updated",
  "context": {
    "target_user_id": 15,
    "removed_roles": ["Editor"]
  }
}
```

**Fix:** Re-assign "Editor" role to user `john`.

### 4. Tracking Content Changes

**Scenario:** Page content changed unexpectedly.

**Steps:**
1. Go to `/admin/audit`
2. Filter: `action=pages.updated`
3. Find the page by `page_id` in context
4. Review who made changes and when
5. Restore from backup if needed

---

## Best Practices

### 1. Regular Review

**Schedule regular audit log reviews:**
- **Weekly:** Review admin actions (`rbac.*`, `users.*`)
- **Monthly:** Review login failures (`user.login.failed`)
- **Quarterly:** Full compliance review

### 2. Alert on Critical Events

**Set up alerts for:**
- Multiple login failures (`user.login.failed`)
- User deletions (`users.deleted`)
- Permission changes to sensitive roles (`rbac.role.permissions.updated`)

**Example (pseudo-code):**
```bash
# Alert if >5 failed logins in 1 hour
if [ $(mysql -e "SELECT COUNT(*) FROM audit_log WHERE action='user.login.failed' AND created_at > NOW() - INTERVAL 1 HOUR") -gt 5 ]; then
    send_alert "Multiple failed login attempts detected"
fi
```

### 3. Archive Before Cleanup

**Always archive audit log before deletion:**
```bash
# 1. Export
php tools/cli.php audit:export --output=archive.json

# 2. Compress
gzip archive.json

# 3. Store off-site
aws s3 cp archive.json.gz s3://my-audit-archives/
```

### 4. Protect Audit Log Database

**Security measures:**
- Separate database user for audit log (read-only for app, write-only for audit service)
- Encrypted backups
- No foreign key cascades (prevent accidental deletion)
- Regular integrity checks

### 5. Document Audit Procedures

**Create a runbook:**
- How to access audit log
- Common queries
- Retention policy
- Compliance requirements
- Incident response procedures

---

## Troubleshooting

### Audit Log Not Recording Events

**Symptom:** Expected events not appearing in audit log.

**Solution:**
1. **Check database:** Verify `audit_log` table exists
   ```bash
   php tools/cli.php db:check
   ```
2. **Check permissions:** Ensure app has `INSERT` privilege on `audit_log`
3. **Check code:** Verify `AuditLogger::log()` is called
4. **Check logs:** Review `storage/logs/` for errors

### Filter Returns No Results

**Symptom:** Filter applied but no results shown.

**Solution:**
1. **Check date format:** Use `YYYY-MM-DD` (e.g., `2026-01-03`, not `01/03/2026`)
2. **Check date range:** Ensure `from` < `to`
3. **Check action wildcard:** Use `rbac.*` not `rbac*`
4. **Check username:** Case-sensitive (use exact username)

### Audit Log Growing Too Large

**Symptom:** Database size increasing rapidly.

**Solution:**
1. **Review retention policy:** Implement cleanup (see [Retention & Cleanup](#retention--cleanup))
2. **Archive old records:** Export and delete
3. **Consider partitioning:** Partition `audit_log` table by date (advanced)

### Invalid Date Range Error (HTTP 422)

**Symptom:** Error when applying date filter.

**Cause:** Invalid date format or `from > to`.

**Solution:**
- Use `YYYY-MM-DD` format
- Ensure `from` date < `to` date
- Check for typos (e.g., `2026-13-01` is invalid)

---

**Last updated:** January 2026
