# Search

**Full-text search** across pages, media, and users with HTMX-powered live search and RBAC-aware filtering. LAAS CMS provides frontend and admin search capabilities with relevance ranking and performance optimization.

---

## Table of Contents

1. [Overview](#overview)
2. [Features](#features)
3. [Search Scopes](#search-scopes)
4. [Frontend Search](#frontend-search)
5. [Admin Search](#admin-search)
6. [Global Admin Search](#global-admin-search)
7. [HTMX Live Search](#htmx-live-search)
8. [Query Processing](#query-processing)
9. [Relevance Ordering](#relevance-ordering)
10. [Database Indexes](#database-indexes)
11. [Security & RBAC](#security--rbac)
12. [Performance Considerations](#performance-considerations)
13. [Code Examples](#code-examples)
14. [Best Practices](#best-practices)
15. [Troubleshooting](#troubleshooting)

---

## Overview

LAAS CMS provides **full-text search** functionality across multiple content types.

**Key capabilities:**
- **Frontend search** — Public search for pages
- **Admin search** — Scoped search for pages, media, users
- **Global admin search** — Unified search across all content types
- **Live search** — HTMX-powered instant results with debounce
- **RBAC-aware** — Results filtered by user permissions

**Introduced in:**
- v1.14.0: Core search functionality
- v2.1.1: Global admin search

---

## Features

### Live Search (HTMX)

**Real-time results as you type:**
- 300ms debounce (avoids excessive requests)
- No page reload required
- Empty state handling
- Keyboard-friendly

### Relevance Ranking

**Results sorted by relevance:**
1. **Prefix match** (`query%`) — Highest priority
2. **Contains match** (`%query%`) — Medium priority
3. **Newest records** — Tiebreaker

### Query Normalization

**Queries are automatically cleaned:**
- Whitespace trimmed
- Multiple spaces collapsed to single space
- Special characters escaped (SQL injection prevention)

### Pagination

**Large result sets are paginated:**
- Default limit: 50 results
- Max page size: 1000 results
- Page parameter: `?page=2`

---

## Search Scopes

### 1. Pages

**Searchable fields:**
- `title` — Page title
- `slug` — URL slug
- `content` — Page content (full-text)

**Available in:**
- Frontend search (`/search`)
- Admin pages search (`/admin/pages?q=...`)
- Global admin search (`/admin/search`)

### 2. Media

**Searchable fields:**
- `original_name` — Original filename
- `mime_type` — File MIME type (e.g., `image/jpeg`)

**Available in:**
- Admin media search (`/admin/media?q=...`)
- Global admin search (`/admin/search`)

### 3. Users

**Searchable fields:**
- `username` — User login name
- `email` — User email address

**Available in:**
- Admin users search (`/admin/users?q=...`)
- Global admin search (`/admin/search`)

---

## Frontend Search

**Public search for pages** (available to all visitors).

### URL

```
/search?q={query}
```

**Example:**
```
/search?q=welcome
```

### Features

- Searches page `title`, `slug`, and `content`
- Only returns **published pages**
- Results ranked by relevance
- Paginated (50 per page)

### Template

**Search form:**
```html
<form method="GET" action="/search">
    <input type="search" name="q" placeholder="Search..." value="{{ query }}">
    <button type="submit">Search</button>
</form>
```

**Results:**
```html
{% if results %}
    <ul>
        {% foreach results as page %}
            <li>
                <a href="/pages/{% page.slug %}">
                    <mark>{% page.title %}</mark>
                </a>
                <p>{% page.excerpt %}</p>
            </li>
        {% endforeach %}
    </ul>
{% else %}
    <p class="alert alert-secondary">No results found for "{% query %}"</p>
{% endif %}
```

### Example Query

**Find pages about "installation":**
```
/search?q=installation
```

**Results:**
- "Installation Guide" (prefix match, highest)
- "Quick Installation" (contains match)
- "Post-Installation Setup" (contains match)

---

## Admin Search

**Scoped search** within specific admin pages.

### Admin Pages Search

**URL:**
```
/admin/pages?q={query}
```

**Example:**
```
/admin/pages?q=getting+started
```

**Features:**
- Searches `title`, `slug`, `content`
- Includes **draft pages** (not just published)
- RBAC-aware (requires `pages.view`)
- Live search with HTMX

### Admin Media Search

**URL:**
```
/admin/media?q={query}
```

**Example:**
```
/admin/media?q=logo
```

**Features:**
- Searches `original_name`, `mime_type`
- Shows media metadata (size, upload date)
- RBAC-aware (requires `media.view`)
- Live search with HTMX

### Admin Users Search

**URL:**
```
/admin/users?q={query}
```

**Example:**
```
/admin/users?q=admin
```

**Features:**
- Searches `username`, `email`
- Shows user roles and status
- RBAC-aware (requires `users.view`)
- Live search with HTMX

---

## Global Admin Search

**Unified search across all content types** (pages, media, users).

### URL

```
/admin/search?q={query}
```

**Example:**
```
/admin/search?q=welcome
```

### Features

**Single search endpoint for all content:**
- Pages (title, slug, content)
- Media (filename, MIME type)
- Users (username, email)

**Results grouped by type:**
```
Pages (3)
- Welcome Page
- Getting Started
- ...

Media (2)
- welcome-banner.jpg
- welcome-icon.png

Users (1)
- welcome@example.com
```

### RBAC Filtering

**Results filtered by permissions:**
- `pages.view` — See pages results
- `media.view` — See media results
- `users.view` — See users results

**Example:**
- User has `pages.view` only → sees only pages, not media/users
- User has `pages.view` + `media.view` → sees pages and media, not users

### Interface

**Search bar (top of admin panel):**
```html
<form method="GET" action="/admin/search">
    <input type="search" name="q" placeholder="Search pages, media, users...">
    <button type="submit">Search</button>
</form>
```

**Results page:**
```
┌─────────────────────────────────────────────────────────┐
│ Search Results for "welcome"                            │
├─────────────────────────────────────────────────────────┤
│ Pages (3 results)                                       │
│ • Welcome Page                      [Edit] [View]       │
│ • Getting Started Guide             [Edit] [View]       │
│ • Welcome to LAAS CMS               [Edit] [View]       │
├─────────────────────────────────────────────────────────┤
│ Media (2 results)                                       │
│ • welcome-banner.jpg (128 KB)       [View] [Delete]     │
│ • welcome-icon.png (12 KB)          [View] [Delete]     │
├─────────────────────────────────────────────────────────┤
│ Users (1 result)                                        │
│ • welcome@example.com               [Edit]              │
└─────────────────────────────────────────────────────────┘
```

---

## HTMX Live Search

**Real-time search without page reload** using HTMX.

### How It Works

**HTML:**
```html
<input
    type="search"
    name="q"
    placeholder="Search..."
    hx-get="/admin/pages"
    hx-trigger="keyup changed delay:300ms"
    hx-target="#results"
    hx-indicator="#spinner"
>

<div id="spinner" class="htmx-indicator">Searching...</div>
<div id="results">
    <!-- Results injected here -->
</div>
```

**Explanation:**
- `hx-get="/admin/pages"` — Send GET request to this endpoint
- `hx-trigger="keyup changed delay:300ms"` — Trigger on keyup, debounced 300ms
- `hx-target="#results"` — Replace content of #results div
- `hx-indicator="#spinner"` — Show spinner during request

### Debounce

**300ms delay prevents excessive requests:**

**Without debounce:**
```
User types: "welcome"
Requests: w, we, wel, welc, welco, welcom, welcome (7 requests)
```

**With 300ms debounce:**
```
User types: "welcome"
Requests: welcome (1 request, after user stops typing)
```

### Empty State

**When no results found:**
```html
<div class="alert alert-secondary">
    No results found for "{% query %}"
</div>
```

### Highlight Matches

**Highlight search query in results:**
```html
<mark>{% highlighted_text %}</mark>
```

**Example:**
```
Query: "install"
Result: "Quick <mark>Install</mark>ation Guide"
```

---

## Query Processing

### Normalization

**Queries are normalized before search:**

```php
// Before: "  hello   world  "
// After: "hello world"

$query = trim($query);
$query = preg_replace('/\s+/', ' ', $query);
```

### Minimum Length

**Queries shorter than 2 characters are rejected:**

```
Query: "a" → HTTP 422 (Unprocessable Entity)
Query: "ab" → OK
```

**Why:**
- Prevents performance issues (1-char queries match too many results)
- Improves relevance (short queries rarely useful)

### Special Characters

**SQL wildcards are escaped:**

```php
// User input: "hello%world"
// Escaped: "hello\%world"

$query = str_replace(['%', '_'], ['\%', '\_'], $query);
```

**Why:**
- Prevents SQL injection
- Prevents unintended wildcard matching

### SQL Query Example

**Search pages for "welcome":**

```sql
SELECT * FROM pages
WHERE
    title LIKE ? ESCAPE '\' OR
    slug LIKE ? ESCAPE '\' OR
    content LIKE ? ESCAPE '\'
ORDER BY
    CASE
        WHEN title LIKE ? ESCAPE '\' THEN 1  -- Prefix match
        WHEN title LIKE ? ESCAPE '\' THEN 2  -- Contains match
        ELSE 3
    END,
    created_at DESC
LIMIT 50;
```

**Bound parameters:**
```php
$params = [
    'welcome%',      // Prefix match (title)
    '%welcome%',     // Contains match (title)
    '%welcome%',     // Contains match (slug)
    '%welcome%',     // Contains match (content)
    'welcome%',      // ORDER BY prefix
    '%welcome%',     // ORDER BY contains
];
```

---

## Relevance Ordering

**Results are ranked by relevance:**

### 1. Prefix Match (Highest)

**Query matches start of field:**

```
Query: "install"
Match: "Installation Guide" ✅ (starts with "install")
```

### 2. Contains Match (Medium)

**Query appears anywhere in field:**

```
Query: "install"
Match: "Quick Installation" ✅ (contains "install")
```

### 3. Newest First (Tiebreaker)

**When relevance is equal, show newest:**

```
Both match with same relevance:
- "Installation Guide" (2026-01-03)
- "Install Tutorial" (2026-01-01)

Result order:
1. Installation Guide (newer)
2. Install Tutorial (older)
```

### Example

**Query: "welcome"**

**Results (ordered by relevance):**
1. **"Welcome Page"** — Prefix match in title
2. **"Getting Started: Welcome"** — Contains match in title
3. **"About Us" (content: "Welcome to our site")** — Contains match in content
4. **"Contact" (content: "You're welcome here")** — Contains match in content, older

---

## Database Indexes

**Indexes optimize search performance.**

### Required Indexes

**Pages:**
```sql
CREATE INDEX idx_pages_title ON pages(title);
CREATE INDEX idx_pages_slug ON pages(slug);
```

**Media:**
```sql
CREATE INDEX idx_media_original_name ON media_files(original_name);
CREATE INDEX idx_media_mime_type ON media_files(mime_type);
```

**Users:**
```sql
CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_users_email ON users(email);
```

### Full-Text Indexes (Optional)

**For large content fields:**

```sql
CREATE FULLTEXT INDEX idx_pages_content ON pages(content);
```

**Pros:**
- Much faster for large text fields
- Better relevance ranking

**Cons:**
- Not supported on all database engines (requires MySQL 5.6+)
- Larger index size

---

## Security & RBAC

### RBAC Filtering

**Search results are filtered by user permissions:**

**Example:**
```php
// User has pages.view → sees pages
// User has media.view → sees media
// User has users.view → sees users

if (!$auth->hasPermission('pages.view')) {
    // Hide pages from search results
}
```

### SQL Injection Prevention

**All queries use prepared statements:**

```php
// GOOD (prepared statement)
$stmt = $db->prepare('SELECT * FROM pages WHERE title LIKE ? ESCAPE \'\\\'');
$stmt->execute(["%{$query}%"]);

// BAD (vulnerable to SQL injection)
$query = "SELECT * FROM pages WHERE title LIKE '%{$_GET['q']}%'";
```

### Input Validation

**Query validation:**
- Length: 2-100 characters
- Type: String
- Sanitization: HTML entities escaped

**Example:**
```php
if (strlen($query) < 2) {
    throw new ValidationException('Query too short (min 2 chars)');
}

if (strlen($query) > 100) {
    throw new ValidationException('Query too long (max 100 chars)');
}
```

---

## Performance Considerations

### Pagination

**Limit results to prevent performance issues:**

```php
// Default: 50 results
$limit = min($_GET['limit'] ?? 50, 1000);

// Max: 1000 results (prevents excessive memory usage)
```

### Query Complexity

**Avoid wildcard prefix searches:**

```sql
-- SLOW (leading wildcard prevents index usage)
WHERE title LIKE '%welcome'

-- FAST (trailing wildcard uses index)
WHERE title LIKE 'welcome%'
```

**Current implementation uses both** (for relevance), but prefix match is prioritized.

### Caching (Future)

**Search results could be cached:**

```php
// Cache key: search:{query}:{user_id}:{page}
$cacheKey = "search:{$query}:{$userId}:{$page}";
$results = Cache::remember($cacheKey, 300, function() {
    // Perform search
});
```

**Not currently implemented** (v2.2.1).

---

## Code Examples

### Controller Example

```php
<?php
declare(strict_types=1);

namespace Modules\Pages\Controller;

use App\Security\Auth;
use Modules\Pages\Repository\PageRepository;

class SearchController
{
    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly Auth $auth
    ) {}

    public function search(): void
    {
        // Get query
        $query = trim($_GET['q'] ?? '');

        // Validate
        if (strlen($query) < 2) {
            http_response_code(422);
            echo json_encode(['error' => 'Query too short']);
            return;
        }

        // Search
        $results = $this->pageRepository->search($query, limit: 50);

        // Render
        echo $this->template->render('search/results.html', [
            'query' => $query,
            'results' => $results,
        ]);
    }
}
```

### Repository Example

```php
<?php
declare(strict_types=1);

namespace Modules\Pages\Repository;

class PageRepository
{
    public function search(string $query, int $limit = 50): array
    {
        // Escape wildcards
        $escapedQuery = str_replace(['%', '_'], ['\%', '\_'], $query);

        // Prepare patterns
        $prefixPattern = $escapedQuery . '%';
        $containsPattern = '%' . $escapedQuery . '%';

        // Query
        $sql = "
            SELECT id, title, slug, created_at
            FROM pages
            WHERE
                title LIKE ? ESCAPE '\\' OR
                slug LIKE ? ESCAPE '\\' OR
                content LIKE ? ESCAPE '\\'
            ORDER BY
                CASE
                    WHEN title LIKE ? ESCAPE '\\' THEN 1
                    WHEN title LIKE ? ESCAPE '\\' THEN 2
                    ELSE 3
                END,
                created_at DESC
            LIMIT ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $containsPattern,  // title LIKE
            $containsPattern,  // slug LIKE
            $containsPattern,  // content LIKE
            $prefixPattern,    // ORDER BY prefix
            $containsPattern,  // ORDER BY contains
            $limit,
        ]);

        return $stmt->fetchAll();
    }
}
```

### HTMX Template Example

```html
<!-- Search input -->
<input
    type="search"
    name="q"
    placeholder="Search pages..."
    value="{{ query }}"
    hx-get="/admin/pages"
    hx-trigger="keyup changed delay:300ms"
    hx-target="#page-list"
    hx-indicator="#search-spinner"
    class="form-control"
>

<!-- Loading indicator -->
<div id="search-spinner" class="htmx-indicator">
    <span class="spinner-border spinner-border-sm"></span>
    Searching...
</div>

<!-- Results container -->
<div id="page-list">
    {% if results %}
        <table class="table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Slug</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                {% foreach results as page %}
                <tr>
                    <td><mark>{% page.title %}</mark></td>
                    <td>{% page.slug %}</td>
                    <td>{% page.created_at %}</td>
                    <td>
                        <a href="/admin/pages/{% page.id %}/edit">Edit</a>
                    </td>
                </tr>
                {% endforeach %}
            </tbody>
        </table>
    {% else %}
        <div class="alert alert-secondary">
            No results found for "{% query %}"
        </div>
    {% endif %}
</div>
```

---

## Best Practices

### 1. Use Appropriate Scope

**Don't use global search when scoped search is better:**

**Good:**
```
Looking for a specific page? → /admin/pages?q=...
Looking for a user? → /admin/users?q=...
```

**Bad:**
```
Looking for a specific page? → /admin/search?q=... (includes media, users)
```

**Why:** Scoped search is faster and more relevant.

### 2. Provide Clear Feedback

**Show what's being searched:**
```html
<p>Searching for "{% query %}" in pages...</p>
```

**Show result count:**
```html
<p>Found {% count(results) %} results</p>
```

### 3. Limit Result Display

**Paginate or limit results:**
```
Show 50 results, then "Load more" or pagination
Don't show 10,000 results at once
```

### 4. Highlight Matches

**Make it obvious why a result matched:**
```html
<mark>{% highlighted_text %}</mark>
```

### 5. Test Edge Cases

**Test queries:**
- Empty query: `""` → HTTP 422
- Single char: `"a"` → HTTP 422
- Special chars: `"hello%world"` → Escaped
- SQL injection: `"'; DROP TABLE pages--"` → Escaped

---

## Troubleshooting

### No Results Found

**Symptom:** Query returns no results, but content exists.

**Solution:**
1. **Check query length:** Min 2 characters
2. **Check case sensitivity:** Search is case-insensitive (uses `LIKE`)
3. **Check field:** Ensure searching correct field (title vs content)
4. **Check permissions:** User may lack RBAC permission to see results

**Debug:**
```php
// Log the SQL query
error_log($sql);
error_log(print_r($params, true));
```

### Search Too Slow

**Symptom:** Search takes >2 seconds.

**Solution:**
1. **Add indexes:** Ensure indexes exist on searchable fields
2. **Limit results:** Reduce limit to 50 or fewer
3. **Avoid content search:** Searching large content fields is slow
4. **Use full-text index:** For large content fields

**Check indexes:**
```sql
SHOW INDEX FROM pages;
```

### HTMX Live Search Not Working

**Symptom:** Typing in search box does nothing.

**Solution:**
1. **Check HTMX loaded:** Verify `htmx.js` is included
2. **Check hx-get URL:** Ensure endpoint exists
3. **Check browser console:** Look for JavaScript errors
4. **Check network tab:** Verify requests are sent
5. **Check debounce:** Wait 300ms after typing

**Debug:**
```html
<!-- Add htmx debug attribute -->
<input ... hx-trigger="keyup changed delay:300ms" hx-debug="true">
```

### HTTP 422 Error

**Symptom:** Search returns HTTP 422.

**Cause:** Query validation failed.

**Common reasons:**
- Query too short (<2 chars)
- Query too long (>100 chars)
- Invalid characters

**Solution:**
```
Ensure query is 2-100 characters
```

### Special Characters Not Working

**Symptom:** Query with `%` or `_` behaves unexpectedly.

**Cause:** SQL wildcards not escaped.

**Solution:**
```php
// Escape wildcards
$query = str_replace(['%', '_'], ['\%', '\_'], $query);
```

---

**Last updated:** January 2026
