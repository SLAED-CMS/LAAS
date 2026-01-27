# Content Sanitizer and Normalization

## Canon
- Store HTML in the database.
- Output HTML to views.

## Pipeline
- Input format: html or markdown.
- If markdown: render to HTML.
- Sanitize HTML using a profile.
- Persist sanitized HTML in the database.

## Profiles

Profile API (stable identifiers):
- admin_trusted_raw
- editor_safe_rich
- user_plain

### admin_trusted_raw
Goal: admin-only content that preserves most tags and attributes while blocking obvious dangers.

Policy:
- Allow all tags and attributes.
- Remove <script>, <style>, and <svg> tags entirely.
- Remove on* event handler attributes.
- Block unsafe URL schemes in href/src (only http, https, mailto, tel allowed).
- Iframes are allowed without a host allowlist (still require a safe URL scheme).

### editor_safe_rich
Goal: rich text with a strict allowlist.

Allowed tags:
- p, h1, h2, h3, h4, h5, h6, ul, ol, li
- strong, em, a, img, br, blockquote, iframe

Allowed attributes:
- a: href
- img: src, alt, title, width, height
- iframe: src, title, width, height, allow, allowfullscreen, frameborder

Policy:
- Remove <script>, <style>, and <svg> tags entirely.
- Remove on* event handler attributes.
- Strip style attributes.
- Block unsafe URL schemes in href/src (only http, https, mailto, tel allowed).
- Iframes are allowed only for allowlisted hosts. An empty allowlist removes all iframes.

### user_plain
Goal: minimal formatting for user-generated content.

Allowed tags:
- p, ul, ol, li, strong, em, a, br, blockquote

Allowed attributes:
- a: href

Policy:
- Remove <script>, <style>, and <svg> tags entirely.
- Remove on* event handler attributes.
- Strip style attributes.
- Block unsafe URL schemes in href/src (only http, https, mailto, tel allowed).
- Iframes are removed.
- Links get rel="nofollow ugc noopener" (merged with existing rel tokens).
- Target is not added automatically; only rel is enforced.
- Href allows http, https, mailto, tel, plus relative URLs (/ ./ ../ # ?).
- Src (if allowed) only allows http/https and relative URLs; data: is blocked.

## Integrations
- Pages (opt-in): APP_PAGES_NORMALIZE_ENABLED (editor_safe_rich)
- Menu (opt-in): APP_MENU_NORMALIZE_ENABLED (editor_safe_rich)
- DevTools JS errors (opt-in): APP_DEVTOOLS_JS_NORMALIZE_ENABLED (user_plain)
- Blocks (opt-in): APP_BLOCKS_NORMALIZE_ENABLED (editor_safe_rich) at PagesService::createRevision (rich_text html|markdown)

## Blocks: rich_text format
- data.format: html|markdown (optional; defaults to html when missing or unknown).
- Used only when APP_BLOCKS_NORMALIZE_ENABLED=1 to render markdown before sanitizing.

## Pages integration (opt-in)
- Flag: APP_PAGES_NORMALIZE_ENABLED (app.pages_normalize_enabled)
- Profile: editor_safe_rich (ContentProfiles::EDITOR_SAFE_RICH)
- Input format: content_format=html|markdown (unknown defaults to html)
- Saved content is sanitized HTML in the database.

## Pages admin editors
- HTML editor: TinyMCE sets content_format=html (sanitized editor_safe_rich on write when enabled).
- Markdown editor: Toast UI sets content_format=markdown (markdown rendered then sanitized on write when enabled).
- Output remains HTML; normalization happens only when APP_PAGES_NORMALIZE_ENABLED=1.
- Vendor assets are local under public/assets/vendor/tinymce and public/assets/vendor/toastui-editor.
- Editor choice persists per user via localStorage; missing assets fall back to textarea for the current page load.
- Fallback does not change the canonical sanitize pipeline (render -> sanitize -> DB).
- TinyMCE direct uploads insert <img> tags; sanitization still happens at the write boundary.

## Pages editor fallback rules
- Selection source order: server content_format (if present) -> server default (html) -> localStorage override (when no content_format).
- Availability check runs after selection; missing assets force textarea with content_format=html.
- When Toast UI is missing, markdown mode is not persisted; fallback is textarea HTML mode.

## User-generated content (opt-in)
- Security reports: SECURITY_REPORTS_NORMALIZE_ENABLED (security.reports_normalize_enabled)
- Profile: user_plain (ContentProfiles::USER_PLAIN)
- Input format: content_format=html|markdown (unknown defaults to html)
- Saved content is sanitized HTML in the database.

## Security notes
- URL schemes: javascript: and data: are blocked by the sanitizer.
- Event handlers: any on* attribute is stripped.
- Styles/scripts: <style>/<script> tags are removed; inline style is stripped in safe profiles.
- Iframes: editor_safe_rich uses a host allowlist; user_plain removes iframes entirely.
