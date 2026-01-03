# Template Engine

**HTML-first template engine with compile-time optimization.**

LAAS CMS uses a custom, lightweight template engine designed for security, performance, and developer experience. Templates are compiled to PHP and cached for fast execution.

---

## Table of Contents

- [Philosophy](#philosophy)
- [Template Syntax](#template-syntax)
- [Variables](#variables)
- [Control Structures](#control-structures)
- [Template Inheritance](#template-inheritance)
- [Partials & Includes](#partials--includes)
- [Helpers & Functions](#helpers--functions)
- [HTMX Integration](#htmx-integration)
- [Theme System](#theme-system)
- [Cache & Performance](#cache--performance)
- [Security](#security)
- [Best Practices](#best-practices)
- [Troubleshooting](#troubleshooting)

---

## Philosophy

**Design Principles:**
- **HTML-first** — Templates are valid HTML, no build step required
- **Auto-escaping by default** — XSS prevention out of the box
- **Compile-time optimization** — Templates compile to PHP for performance
- **Minimal syntax** — Small learning curve, familiar constructs
- **Progressive enhancement** — Works with or without JavaScript (HTMX ready)

**What it is:**
- Lightweight template engine (~500 lines)
- Compiles templates to PHP
- Caches compiled templates for performance
- Auto-escapes output for security

**What it is NOT:**
- Not Twig, Blade, or Smarty (simpler, smaller)
- Not a full-featured templating language (by design)
- Not meant for complex logic (keep it in controllers)

---

## Template Syntax

### Basic Syntax

Templates use `{% %}` for directives and `{{ }}` for output (alternate syntax).

**Output:**
```html
<!-- Auto-escaped by default -->
{% title %}
{{ title }}

<!-- Raw output (use with caution) -->
{% raw content %}
```

**Comments:**
```html
{# This is a comment - not rendered #}
```

---

## Variables

### Simple Variables

**Syntax:**
```html
{% variableName %}
```

**Auto-escaping:**
All variables are automatically HTML-escaped to prevent XSS attacks.

**Example:**
```html
<!-- Controller passes: ['name' => '<script>alert("XSS")</script>'] -->
<h1>Hello, {% name %}!</h1>

<!-- Output: -->
<h1>Hello, &lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;!</h1>
```

### Object/Array Access

**Dot notation for objects and arrays:**
```html
{% user.name %}
{% user.email %}
{% settings.site_name %}
{% items.0.title %}
```

**Equivalent PHP:**
```php
$user['name']
$user['email']
$settings['site_name']
$items[0]['title']
```

### Raw Output

**Use `raw` directive for unescaped HTML:**
```html
{% raw article.content %}
```

**⚠️ Warning:** Only use `raw` for trusted content (e.g., admin-authored HTML). Never use for user input!

**Example:**
```html
<!-- GOOD: Admin-authored content -->
<div class="article-body">
    {% raw article.body %}
</div>

<!-- BAD: User input - XSS vulnerability! -->
<div class="comment">
    {% raw comment.text %}  <!-- NEVER DO THIS -->
</div>
```

---

## Control Structures

### If / Else

**Syntax:**
```html
{% if condition %}
    <!-- content when true -->
{% else %}
    <!-- content when false -->
{% endif %}
```

**Example:**
```html
{% if user.is_admin %}
    <a href="/admin">Admin Panel</a>
{% else %}
    <p>Access denied</p>
{% endif %}
```

**Without else:**
```html
{% if messages %}
    <div class="alert">{% messages %}</div>
{% endif %}
```

**Truthiness:**
- `true`, non-empty strings, non-zero numbers → true
- `false`, `null`, `0`, `''`, `[]` → false

### Foreach Loops

**Syntax:**
```html
{% foreach items as item %}
    <!-- loop body -->
{% endforeach %}
```

**Example:**
```html
<ul>
{% foreach pages as page %}
    <li>
        <a href="/pages/{% page.slug %}">{% page.title %}</a>
    </li>
{% endforeach %}
</ul>
```

**Empty check:**
```html
{% if pages %}
    <ul>
    {% foreach pages as page %}
        <li>{% page.title %}</li>
    {% endforeach %}
    </ul>
{% else %}
    <p>No pages found.</p>
{% endif %}
```

**Nested loops:**
```html
{% foreach categories as category %}
    <h2>{% category.name %}</h2>
    <ul>
    {% foreach category.items as item %}
        <li>{% item.title %}</li>
    {% endforeach %}
    </ul>
{% endforeach %}
```

---

## Template Inheritance

Templates support inheritance using `extends` and `block` directives.

### Base Layout

**File:** `themes/default/layout.html`
```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{% block title %}Default Title{% endblock %}</title>
    {% block head %}{% endblock %}
</head>
<body>
    <header>
        <nav>
            {% menu 'main' %}
        </nav>
    </header>

    <main>
        {% block content %}
            <!-- Default content -->
        {% endblock %}
    </main>

    <footer>
        {% block footer %}
            <p>&copy; {% site_name %}</p>
        {% endblock %}
    </footer>

    {% block scripts %}{% endblock %}
</body>
</html>
```

### Child Template

**File:** `themes/default/pages/show.html`
```html
{% extends "layout.html" %}

{% block title %}{% page.title %} - {% site_name %}{% endblock %}

{% block content %}
    <article>
        <h1>{% page.title %}</h1>
        <div class="content">
            {% raw page.content %}
        </div>
    </article>
{% endblock %}

{% block scripts %}
    <script src="/js/pages.js"></script>
{% endblock %}
```

**How it works:**
1. Child template declares `{% extends "layout.html" %}`
2. Engine loads base layout
3. Child blocks override base blocks
4. Final HTML combines both

**Rules:**
- `extends` must be the first directive
- Only one `extends` per template
- Blocks in child override blocks in parent
- Blocks can be empty or have default content

---

## Partials & Includes

Use `include` to embed reusable template fragments.

### Include Syntax

```html
{% include "partials/header.html" %}
```

### Example: Reusable Alert

**File:** `themes/default/partials/alert.html`
```html
<div class="alert alert-{% type %}">
    {% message %}
</div>
```

**Usage in template:**
```html
{% if error %}
    {% include "partials/alert.html" %}
{% endif %}
```

**Variables are inherited** from parent template scope.

### Common Use Cases

**Navigation:**
```html
<!-- partials/nav.html -->
<nav>
    {% menu 'main' %}
</nav>

<!-- In layout -->
{% include "partials/nav.html" %}
```

**Form errors:**
```html
<!-- partials/form-errors.html -->
{% if errors %}
    <div class="alert alert-danger">
        <ul>
        {% foreach errors as error %}
            <li>{% error %}</li>
        {% endforeach %}
        </ul>
    </div>
{% endif %}

<!-- In form -->
{% include "partials/form-errors.html" %}
```

**CSRF token:**
```html
<!-- partials/csrf.html -->
<input type="hidden" name="csrf_token" value="{% csrf_token %}">

<!-- In all forms -->
<form method="POST">
    {% include "partials/csrf.html" %}
    <!-- form fields -->
</form>
```

---

## Helpers & Functions

### Built-in Helpers

#### csrf_token()

Returns CSRF token for forms.

```html
<form method="POST">
    <input type="hidden" name="csrf_token" value="{% csrf_token %}">
    <!-- form fields -->
</form>
```

#### t(key, params)

Translate text using i18n system.

```html
<!-- Simple translation -->
<h1>{% t 'welcome.title' %}</h1>

<!-- With parameters (in PHP, not template) -->
<!-- Controller: $this->view('page', ['welcome' => t('welcome.user', ['name' => $user['name']])]) -->
<p>{% welcome %}</p>
```

#### menu(location)

Render menu by location.

```html
<!-- Main navigation -->
{% menu 'main' %}

<!-- Footer menu -->
{% menu 'footer' %}
```

**Output:**
```html
<ul class="menu">
    <li><a href="/">Home</a></li>
    <li><a href="/about">About</a></li>
    <li><a href="/contact">Contact</a></li>
</ul>
```

#### asset(path)

Generate asset URL with cache busting.

```html
<link rel="stylesheet" href="{% asset 'css/style.css' %}">
<script src="{% asset 'js/app.js' %}"></script>
```

**Output:**
```html
<link rel="stylesheet" href="/themes/default/css/style.css?v=1234567890">
<script src="/themes/default/js/app.js?v=1234567890"></script>
```

#### url(path)

Generate absolute URL.

```html
<a href="{% url '/pages/about' %}">About</a>
```

### Custom Variables

Controllers can pass custom data:

```php
// In controller
return $this->view('pages/show', [
    'page' => $page,
    'site_name' => config('app.site_name'),
    'user' => $currentUser,
    'csrf_token' => csrf_token(),
]);
```

```html
<!-- In template -->
<h1>{% page.title %}</h1>
<p>Welcome, {% user.name %}!</p>
<footer>{% site_name %}</footer>
```

---

## HTMX Integration

**Progressive Enhancement:** Templates work with or without JavaScript.

### HTMX Partial Rendering

When a request includes `HX-Request` header and template uses `extends`:
- **Only the content block is returned** (not full layout)
- Reduces bandwidth, faster updates
- Enables SPA-like experience without client-side routing

### Example: Page Toggle

**Template:** `themes/admin/pages/index.html`
```html
{% extends "admin/layout.html" %}

{% block content %}
    <table class="table">
    {% foreach pages as page %}
        <tr id="page-{% page.id %}">
            <td>{% page.title %}</td>
            <td>
                <button
                    class="btn btn-sm"
                    hx-post="/admin/pages/{% page.id %}/toggle"
                    hx-swap="outerHTML"
                    hx-target="#page-{% page.id %}">
                    {% if page.published %}Published{% else %}Draft{% endif %}
                </button>
            </td>
        </tr>
    {% endforeach %}
    </table>
{% endblock %}
```

**HTMX Request:**
1. User clicks button
2. HTMX sends POST with `HX-Request: true` header
3. Server detects HTMX, returns **only** `block content`
4. HTMX swaps HTML without full page reload

**Full page request:**
1. User navigates directly to page
2. No `HX-Request` header
3. Server returns **full layout** with content block

### Best Practices

**Use semantic HTML:**
```html
<!-- Good: semantic, works without JS -->
<form method="POST" hx-post="/search" hx-target="#results">
    <input type="search" name="q">
    <button type="submit">Search</button>
</form>

<!-- Bad: requires JS -->
<div hx-post="/search">
    <input type="text" name="q">
    <div onclick="htmx.trigger('#search-form', 'submit')">Search</div>
</div>
```

**Progressive enhancement:**
```html
<!-- Works with or without JS -->
<a href="/admin/users/{% user.id %}/delete"
   hx-delete="/admin/users/{% user.id %}"
   hx-confirm="Delete this user?"
   hx-target="closest tr"
   hx-swap="outerHTML">
    Delete
</a>
```

---

## Theme System

### Theme Structure

```
themes/
├── default/              # Public theme
│   ├── layout.html      # Base layout
│   ├── pages/
│   │   ├── index.html
│   │   └── show.html
│   ├── partials/
│   │   ├── nav.html
│   │   └── footer.html
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── app.js
└── admin/               # Admin theme
    ├── layout.html
    ├── dashboard.html
    └── ...
```

### Theme Selection

**Public theme:**
- Configured in `config/app.php` or DB settings
- Default: `default`
- Can be changed via admin UI (`/admin/settings`)

**Admin theme:**
- Always uses `admin` theme
- Cannot be changed (by design)

### Template Resolution

**Search order:**
1. `themes/{current_theme}/{template}.html`
2. `themes/default/{template}.html` (fallback)
3. Throws exception if not found

**Example:**
```php
// Request: /pages/about
// Current theme: custom

// Searches:
// 1. themes/custom/pages/show.html
// 2. themes/default/pages/show.html
// 3. Exception if neither exists
```

---

## Cache & Performance

### Template Compilation

**How it works:**
1. First request: template compiled to PHP, saved to cache
2. Subsequent requests: cached PHP executed directly
3. Template changes invalidated automatically (dev mode)

**Cache location:**
```
storage/cache/templates/
├── default_layout.html.php
├── default_pages_show.html.php
└── admin_dashboard.html.php
```

### Cache Management

**Clear template cache:**
```bash
php tools/cli.php templates:clear
```

**Warmup cache (recommended for production):**
```bash
php tools/cli.php templates:warmup
```

**Auto-invalidation:**
- Dev mode (`APP_DEBUG=true`): cache checked on every request
- Production mode: cache persists until manually cleared

### Performance Tips

**Do:**
- Use `templates:warmup` in production
- Cache expensive operations in controllers, not templates
- Use partials for frequently reused snippets
- Keep logic in controllers, not templates

**Don't:**
- Put complex logic in templates
- Make database queries in templates
- Use `raw` for user input
- Nest includes more than 2-3 levels deep

---

## Security

### Auto-Escaping

**All output is escaped by default:**
```html
<!-- Safe: automatically escaped -->
{% user.name %}
{% page.title %}

<!-- Equivalent to: -->
<?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?>
```

### When to Use Raw

**Only use `raw` for:**
- Admin-authored HTML content (CMS pages)
- Pre-sanitized markup from trusted sources
- Content stored with HTML tags intentionally

**Never use `raw` for:**
- User-generated content
- Comments, forum posts
- Any untrusted input

### CSRF Protection

**Always include CSRF token in forms:**
```html
<form method="POST" action="/admin/settings">
    <input type="hidden" name="csrf_token" value="{% csrf_token %}">
    <!-- form fields -->
    <button type="submit">Save</button>
</form>
```

**HTMX forms:**
```html
<form hx-post="/admin/settings">
    {% include "partials/csrf.html" %}
    <!-- form fields -->
</form>
```

### XSS Prevention Checklist

- [ ] Never use `{% raw %}` for user input
- [ ] Always validate and sanitize in controllers
- [ ] Use CSRF tokens in all forms
- [ ] Validate MIME types for file uploads
- [ ] Use Content-Security-Policy headers
- [ ] Audit all uses of `raw` directive

---

## Best Practices

### Keep Templates Simple

**Good:**
```html
{% extends "layout.html" %}

{% block content %}
    <h1>{% page.title %}</h1>
    {% if page.published %}
        <div class="content">{% raw page.content %}</div>
    {% else %}
        <p class="draft-notice">This page is a draft.</p>
    {% endif %}
{% endblock %}
```

**Bad (too much logic):**
```html
<!-- Don't do complex operations in templates -->
{% foreach users as user %}
    {% if user.status == 'active' && user.role != 'guest' %}
        {% if user.last_login > now - 86400 %}
            <!-- Complex nested logic - move to controller! -->
        {% endif %}
    {% endif %}
{% endforeach %}
```

**Fix:** Move logic to controller:
```php
// In controller
$activeUsers = array_filter($users, function($user) {
    return $user['status'] === 'active'
        && $user['role'] !== 'guest'
        && $user['last_login'] > time() - 86400;
});

return $this->view('users/list', ['users' => $activeUsers]);
```

### Use Partials for Reusability

**Create reusable components:**
```
partials/
├── csrf.html           # CSRF token
├── form-errors.html    # Validation errors
├── pagination.html     # Pagination controls
├── alert.html          # Alert boxes
└── breadcrumbs.html    # Breadcrumb navigation
```

### Organize by Module

**Structure templates by feature:**
```
themes/default/
├── pages/
│   ├── index.html
│   ├── show.html
│   └── edit.html
├── users/
│   ├── profile.html
│   └── settings.html
└── media/
    ├── gallery.html
    └── upload.html
```

### Use Semantic HTML

**Good (semantic, accessible):**
```html
<nav role="navigation" aria-label="Main navigation">
    {% menu 'main' %}
</nav>

<main role="main">
    <article>
        <h1>{% page.title %}</h1>
        <time datetime="{% page.created_at %}">{% page.created_at %}</time>
        <div>{% raw page.content %}</div>
    </article>
</main>
```

**Bad (div soup):**
```html
<div class="nav">
    {% menu 'main' %}
</div>

<div class="main">
    <div class="article">
        <div class="title">{% page.title %}</div>
        <div class="date">{% page.created_at %}</div>
        <div class="content">{% raw page.content %}</div>
    </div>
</div>
```

---

## Troubleshooting

### Template Not Found

**Error:**
```
Template not found: pages/show.html
```

**Causes:**
1. File doesn't exist in current or default theme
2. Wrong path separator (use `/`, not `\`)
3. Case sensitivity on Linux servers

**Fix:**
```bash
# Check file exists
ls themes/default/pages/show.html

# Verify theme setting
php tools/cli.php settings:get theme

# Check template cache
php tools/cli.php templates:clear
```

### Variable Not Rendering

**Issue:** `{% user.name %}` shows nothing

**Causes:**
1. Variable not passed from controller
2. Variable is `null` or empty
3. Typo in variable name

**Debug:**
```php
// In controller - check what's being passed
var_dump($data);
return $this->view('template', $data);
```

### HTMX Partial Not Working

**Issue:** Full page returned instead of partial

**Causes:**
1. Template doesn't use `extends`
2. `HX-Request` header not sent
3. Multiple `block content` definitions

**Fix:**
```html
<!-- Ensure template extends layout -->
{% extends "layout.html" %}

<!-- Single content block -->
{% block content %}
    <!-- content here -->
{% endblock %}
```

### Raw Output Still Escaped

**Issue:** HTML tags displayed as text even with `raw`

**Cause:** Using `{% %}` instead of `{% raw %}`

**Wrong:**
```html
{% page.content %}  <!-- Escaped -->
```

**Correct:**
```html
{% raw page.content %}  <!-- Not escaped -->
```

### Cache Not Clearing

**Issue:** Template changes not reflected

**Fix:**
```bash
# Clear template cache
php tools/cli.php templates:clear

# Clear all caches
php tools/cli.php cache:clear

# Verify APP_DEBUG setting
cat .env | grep APP_DEBUG
```

---

## Examples

### Complete Page Template

```html
{% extends "layout.html" %}

{% block title %}{% page.title %} - {% site_name %}{% endblock %}

{% block head %}
    <meta name="description" content="{% page.meta_description %}">
    <link rel="stylesheet" href="{% asset 'css/pages.css' %}">
{% endblock %}

{% block content %}
    <article class="page">
        <header>
            <h1>{% page.title %}</h1>
            {% if page.subtitle %}
                <p class="subtitle">{% page.subtitle %}</p>
            {% endif %}
            <time datetime="{% page.created_at %}">
                {% t 'published_on' %}: {% page.created_at %}
            </time>
        </header>

        <div class="page-content">
            {% raw page.content %}
        </div>

        {% if page.tags %}
            <footer class="page-tags">
                <strong>Tags:</strong>
                {% foreach page.tags as tag %}
                    <a href="/tags/{% tag.slug %}" class="tag">{% tag.name %}</a>
                {% endforeach %}
            </footer>
        {% endif %}
    </article>
{% endblock %}

{% block scripts %}
    <script src="{% asset 'js/pages.js' %}"></script>
{% endblock %}
```

### Admin Form with HTMX

```html
{% extends "admin/layout.html" %}

{% block content %}
    <div class="container">
        <h1>{% t 'admin.pages.edit' %}</h1>

        {% include "partials/form-errors.html" %}

        <form method="POST" hx-post="/admin/pages/{% page.id %}" hx-target="#page-form">
            <div id="page-form">
                {% include "partials/csrf.html" %}

                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" class="form-control" id="title" name="title" value="{% page.title %}" required>
                </div>

                <div class="mb-3">
                    <label for="slug" class="form-label">Slug</label>
                    <input type="text" class="form-control" id="slug" name="slug" value="{% page.slug %}" required>
                </div>

                <div class="mb-3">
                    <label for="content" class="form-label">Content</label>
                    <textarea class="form-control" id="content" name="content" rows="10">{% page.content %}</textarea>
                </div>

                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="published" name="published" {% if page.published %}checked{% endif %}>
                    <label class="form-check-label" for="published">Published</label>
                </div>

                <button type="submit" class="btn btn-primary" hx-indicator="#spinner">
                    Save Changes
                </button>
                <span id="spinner" class="htmx-indicator spinner-border spinner-border-sm"></span>
            </div>
        </form>
    </div>
{% endblock %}
```

---

## References

- [Architecture Overview](ARCHITECTURE.md) — System design
- [HTMX Documentation](https://htmx.org/) — Progressive enhancement
- [i18n Guide](I18N.md) — Internationalization
- [Security Guide](SECURITY.md) — XSS prevention

---

**Last updated:** January 2026
