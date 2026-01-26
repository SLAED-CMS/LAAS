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
- img: src, alt
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

## Security notes
- URL schemes: javascript: and data: are blocked by the sanitizer.
- Event handlers: any on* attribute is stripped.
- Styles/scripts: <style>/<script> tags are removed; inline style is stripped in safe profiles.
- Iframes: editor_safe_rich uses a host allowlist; user_plain removes iframes entirely.
