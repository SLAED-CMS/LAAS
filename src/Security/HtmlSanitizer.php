<?php

declare(strict_types=1);

namespace Laas\Security;

use DOMDocument;
use DOMNode;

final class HtmlSanitizer
{
    private const DEFAULT_PROFILE = 'legacy';

    /**
     * Profile structure:
     * - allow_all_tags: bool
     * - allowed_tags: string[]
     * - blocked_tags: string[]
     * - allow_all_attributes: bool
     * - allowed_attributes: array<string, string[]>
     * - strip_comments: bool
     * - strip_event_handlers: bool
     * - strip_style_attribute: bool
     * - allowed_url_schemes: string[]|null
     * - iframe_allowlist_hosts: string[]|null (null allows any host, empty blocks all)
     */
    private const PROFILES = [
        'legacy' => [
            'allow_all_tags' => false,
            'allowed_tags' => [
                'p',
                'h1',
                'h2',
                'h3',
                'h4',
                'h5',
                'h6',
                'ul',
                'ol',
                'li',
                'strong',
                'em',
                'a',
                'img',
                'br',
                'blockquote',
            ],
            'blocked_tags' => ['script', 'iframe', 'svg'],
            'allow_all_attributes' => false,
            'allowed_attributes' => [
                'a' => ['href'],
                'img' => ['src', 'alt'],
            ],
            'strip_comments' => true,
            'strip_event_handlers' => true,
            'strip_style_attribute' => false,
            'allowed_url_schemes' => null,
            'iframe_allowlist_hosts' => [],
        ],
        'admin_trusted_raw' => [
            'allow_all_tags' => true,
            'allowed_tags' => [],
            'blocked_tags' => ['script', 'style', 'svg'],
            'allow_all_attributes' => true,
            'allowed_attributes' => [],
            'strip_comments' => true,
            'strip_event_handlers' => true,
            'strip_style_attribute' => false,
            'allowed_url_schemes' => ['http', 'https', 'mailto', 'tel'],
            'iframe_allowlist_hosts' => null,
        ],
        'editor_safe_rich' => [
            'allow_all_tags' => false,
            'allowed_tags' => [
                'p',
                'h1',
                'h2',
                'h3',
                'h4',
                'h5',
                'h6',
                'ul',
                'ol',
                'li',
                'strong',
                'em',
                'a',
                'img',
                'br',
                'blockquote',
                'iframe',
            ],
            'blocked_tags' => ['script', 'style', 'svg'],
            'allow_all_attributes' => false,
            'allowed_attributes' => [
                'a' => ['href'],
                'img' => ['src', 'alt'],
                'iframe' => ['src', 'title', 'width', 'height', 'allow', 'allowfullscreen', 'frameborder'],
            ],
            'strip_comments' => true,
            'strip_event_handlers' => true,
            'strip_style_attribute' => true,
            'allowed_url_schemes' => ['http', 'https', 'mailto', 'tel'],
            'iframe_allowlist_hosts' => [],
        ],
        'user_plain' => [
            'allow_all_tags' => false,
            'allowed_tags' => [
                'p',
                'ul',
                'ol',
                'li',
                'strong',
                'em',
                'a',
                'br',
                'blockquote',
            ],
            'blocked_tags' => ['script', 'style', 'svg'],
            'allow_all_attributes' => false,
            'allowed_attributes' => [
                'a' => ['href'],
            ],
            'strip_comments' => true,
            'strip_event_handlers' => true,
            'strip_style_attribute' => true,
            'allowed_url_schemes' => ['http', 'https', 'mailto', 'tel'],
            'iframe_allowlist_hosts' => [],
        ],
    ];

    public function sanitize(string $html, string $profile = self::DEFAULT_PROFILE): string
    {
        if (trim($html) === '') {
            return '';
        }

        $config = $this->profile($profile);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $wrapped = '<div>' . $html . '</div>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->documentElement;
        if ($root === null) {
            return '';
        }

        $this->sanitizeNode($root, $config);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function sanitizeNode(DOMNode $node, array $profile): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                if ($profile['strip_comments']) {
                    $node->removeChild($child);
                }
                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $tag = strtolower($child->nodeName);
            if (in_array($tag, $profile['blocked_tags'], true)) {
                $node->removeChild($child);
                continue;
            }

            if (!$this->isTagAllowed($tag, $profile)) {
                $this->sanitizeNode($child, $profile);
                $this->unwrapNode($node, $child);
                continue;
            }

            if ($tag === 'iframe' && !$this->isIframeAllowed($child, $profile)) {
                $node->removeChild($child);
                continue;
            }

            $this->sanitizeAttributes($child, $tag, $profile);
            $this->sanitizeNode($child, $profile);
        }
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function sanitizeAttributes(DOMNode $node, string $tag, array $profile): void
    {
        if (!$node->hasAttributes()) {
            return;
        }

        $remove = [];

        foreach ($node->attributes as $attr) {
            $name = strtolower($attr->nodeName);
            if ($profile['strip_event_handlers'] && str_starts_with($name, 'on')) {
                $remove[] = $name;
                continue;
            }

            if ($profile['strip_style_attribute'] && $name === 'style') {
                $remove[] = $name;
                continue;
            }

            if (!$this->isAttributeAllowed($tag, $name, $profile)) {
                $remove[] = $name;
                continue;
            }

            if (($name === 'href' || $name === 'src')
                && !$this->isSafeUrl($attr->nodeValue, $profile['allowed_url_schemes'])) {
                $remove[] = $name;
            }
        }

        foreach ($remove as $name) {
            if ($node instanceof \DOMElement) {
                $node->removeAttribute($name);
            }
        }
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function isTagAllowed(string $tag, array $profile): bool
    {
        if ($profile['allow_all_tags']) {
            return true;
        }

        return in_array($tag, $profile['allowed_tags'], true);
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function isAttributeAllowed(string $tag, string $name, array $profile): bool
    {
        if ($profile['allow_all_attributes']) {
            return true;
        }

        $allowed = $profile['allowed_attributes']['*'] ?? [];
        if (isset($profile['allowed_attributes'][$tag])) {
            $allowed = array_merge($allowed, $profile['allowed_attributes'][$tag]);
        }

        return in_array($name, $allowed, true);
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function isIframeAllowed(DOMNode $node, array $profile): bool
    {
        $allowlist = $profile['iframe_allowlist_hosts'];
        if ($allowlist === null) {
            return true;
        }

        if (!$node instanceof \DOMElement) {
            return false;
        }

        $src = $node->getAttribute('src');
        if ($src === '') {
            return false;
        }

        if (!$this->isSafeUrl($src, $profile['allowed_url_schemes'])) {
            return false;
        }

        $decoded = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $host = parse_url($decoded, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), $allowlist, true);
    }

    /**
     * @param array<string>|null $allowedSchemes
     */
    private function isSafeUrl(?string $value, ?array $allowedSchemes): bool
    {
        $value = $value ?? '';
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = strtolower(trim($decoded));

        if ($normalized === '') {
            return false;
        }

        if ($allowedSchemes === null) {
            if (str_starts_with($normalized, 'javascript:')) {
                return false;
            }

            if (str_starts_with($normalized, 'data:')) {
                return false;
            }

            return true;
        }

        $normalized = preg_replace('/[\\x00-\\x1F\\x7F\\s]+/', '', $normalized) ?? '';
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized) !== 1) {
            return true;
        }

        $pos = strpos($normalized, ':');
        $scheme = $pos === false ? $normalized : substr($normalized, 0, $pos);
        return in_array($scheme, $allowedSchemes, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(string $profile): array
    {
        $config = self::PROFILES[$profile] ?? self::PROFILES[self::DEFAULT_PROFILE];

        $config['allowed_tags'] = array_map('strtolower', $config['allowed_tags']);
        $config['blocked_tags'] = array_map('strtolower', $config['blocked_tags']);

        $attrs = [];
        foreach ($config['allowed_attributes'] as $tag => $names) {
            $attrs[strtolower($tag)] = array_map('strtolower', $names);
        }
        $config['allowed_attributes'] = $attrs;

        if (is_array($config['allowed_url_schemes'])) {
            $config['allowed_url_schemes'] = array_map('strtolower', $config['allowed_url_schemes']);
        }

        if (is_array($config['iframe_allowlist_hosts'])) {
            $config['iframe_allowlist_hosts'] = array_map('strtolower', $config['iframe_allowlist_hosts']);
        }

        return $config;
    }

    private function unwrapNode(DOMNode $parent, DOMNode $node): void
    {
        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }
}
