# UI Tokens v1

## Purpose / Goals

- Separate backend from CSS/JS.
- Standardize UI state in data contracts.
- Allow Bootstrap/HTMX replacement without controller changes.
- Keep token-to-class mapping inside templates only.

## Token categories

### status

| token | values | meaning |
| --- | --- | --- |
| status | ok, degraded, down, active, inactive, pending, blocked | entity state |

### variant

| token | values | meaning |
| --- | --- | --- |
| variant | primary, secondary, success, warning, danger, info, dark | visual variant |

### size

| token | values | meaning |
| --- | --- | --- |
| size | xs, sm, md, lg, xl | element size |

### enabled

| token | values | meaning |
| --- | --- | --- |
| enabled | true, false | availability |

### visibility

| token | values | meaning |
| --- | --- | --- |
| visibility | visible, hidden, collapsed | visibility state |

### severity

| token | values | meaning |
| --- | --- | --- |
| severity | low, medium, high, critical | issue severity |

### align

| token | values | meaning |
| --- | --- | --- |
| align | start, center, end | alignment |

## Naming rules

- Keys use `snake_case`
- Values are enums in `snake_case`
- No CSS classes in values

## Examples

### Backend returns tokens

```php
return $view->render('pages/status.html', [
    'health' => [
        'status' => 'ok',
        'severity' => 'low',
        'enabled' => true,
    ],
]);
```

### Template maps tokens to Bootstrap 5

```html
{% if health.status == 'ok' %}
  <span class="badge text-bg-success">OK</span>
{% elseif health.status == 'degraded' %}
  <span class="badge text-bg-warning">Degraded</span>
{% else %}
  <span class="badge text-bg-danger">Down</span>
{% endif %}
```

## Anti-patterns

- `*_class` from PHP
- inline style in templates
- inline JS in templates
- CDN usage in templates

## Migration guide

### Before: class from PHP

```php
'health_class' => 'text-bg-success'
```

After: token

```php
'health' => ['status' => 'ok']
```

### Before: class for button

```php
'button_class' => 'btn btn-danger'
```

After: token

```php
'button' => ['variant' => 'danger', 'size' => 'sm']
```

### Before: flag as class

```php
'row_class' => $flash ? 'laas-flash' : ''
```

After: flag

```php
'row' => ['flash' => $flash]
```
