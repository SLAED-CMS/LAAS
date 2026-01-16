# Routes Reference

Complete route reference for LAAS CMS.

---

## Public Routes

| Route | Description |
|-------|-------------|
| `/` | Homepage (system showcase) |
| `/search` | Frontend search |
| `/pages/{slug}` | Page view |
| `/changelog` | Changelog feed |
| `/login` | Login page (GET/POST) |
| `/logout` | Logout (POST) |

---

## API Routes

| Route | Auth | Description |
|-------|------|-------------|
| `/api/v1/ping` | Public | Health check |
| `/api/v1/pages` | Public | Pages list |
| `/api/v1/media` | Public | Media list |
| `/api/v1/menus/{name}` | Public | Menu by name |
| `/api/v1/auth/token` | Admin/Password | Issue token |
| `/api/v1/me` | Bearer | Token identity |
| `/api/v1/auth/revoke` | Bearer | Revoke current token |

### AI API (v4.0.0)

| Route | Auth | Description |
|-------|------|-------------|
| `/api/v1/ai/propose` | Bearer | Create proposal |
| `/api/v1/ai/tools` | Bearer | List available tools |
| `/api/v1/ai/run` | Bearer | Run plan |

---

## System Routes

| Route | Description |
|-------|-------------|
| `/health` | System health endpoint |
| `/csrf` | CSRF token refresh |
| `/__csp/report` | CSP report ingestion (POST) |

---

## Admin Routes

### Core Management

| Route | Permission | Description |
|-------|------------|-------------|
| `/admin` | `admin.access` | Dashboard |
| `/admin/modules` | `admin.modules.manage` | Module management |
| `/admin/settings` | `admin.settings.manage` | Settings editor |

### Content & Media

| Route | Permission | Description |
|-------|------------|-------------|
| `/admin/pages` | `pages.view` | Pages management |
| `/admin/pages/create` | `pages.create` | Create page |
| `/admin/pages/{id}/edit` | `pages.edit` | Edit page |
| `/admin/media` | `media.view` | Media library |

### User Management

| Route | Permission | Description |
|-------|------------|-------------|
| `/admin/users` | `users.manage` | User management |
| `/admin/diagnostics` | `admin.access` | RBAC diagnostics |

### System

| Route | Permission | Description |
|-------|------------|-------------|
| `/admin/audit` | `audit.view` | Audit log |
| `/admin/ops` | `admin.access` | Ops dashboard |
| `/admin/menus` | `menus.manage` | Menu management |
| `/admin/search` | `admin.access` | Global search |

### API & Integrations

| Route | Permission | Description |
|-------|------------|-------------|
| `/admin/api-tokens` | `api.tokens.view` | Token management |
| `/admin/changelog` | `changelog.admin` | Changelog config |
| `/admin/ai` | `ai.view` | AI Assistant (v4.0.0) |

---

## Media Routes

| Route | Description |
|-------|-------------|
| `/media/{hash}.{ext}` | Media file serving |
| `/media/thumb/{hash}/{size}.{ext}` | Thumbnail serving (sm/md/lg) |
| `/media/signed/{token}/{hash}.{ext}` | Signed URL serving |

---

## Personal Access Tokens (PAT)

Create tokens via `/admin/api-tokens` or `/api/v1/auth/token`.

**Usage:**
```http
Authorization: Bearer LAAS_<prefix>.<secret>
```

**Configuration:**
- `API_TOKEN_SCOPES` — Allowlisted scopes

---

## See Also

- [API Documentation](API.md)
- [RBAC Permissions](RBAC.md)
- [Security](SECURITY.md)