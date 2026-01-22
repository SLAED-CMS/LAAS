<?php

declare(strict_types=1);

namespace Laas\Security;

use DOMDocument;
use DOMNode;

final class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
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
    ];

    private const FORBIDDEN_TAGS = [
        'script',
        'iframe',
        'svg',
    ];

    private const ALLOWED_ATTRS = [
        'a' => ['href'],
        'img' => ['src', 'alt'],
    ];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

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

        $this->sanitizeNode($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private function sanitizeNode(DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::FORBIDDEN_TAGS, true)) {
                $node->removeChild($child);
                continue;
            }

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->sanitizeNode($child);
                $this->unwrapNode($node, $child);
                continue;
            }

            $this->sanitizeAttributes($child, $tag);
            $this->sanitizeNode($child);
        }
    }

    private function sanitizeAttributes(DOMNode $node, string $tag): void
    {
        if (!$node->hasAttributes()) {
            return;
        }

        $allowed = self::ALLOWED_ATTRS[$tag] ?? [];
        $remove = [];

        foreach ($node->attributes as $attr) {
            $name = strtolower($attr->nodeName);
            if (str_starts_with($name, 'on')) {
                $remove[] = $name;
                continue;
            }

            if (!in_array($name, $allowed, true)) {
                $remove[] = $name;
                continue;
            }

            if (($name === 'href' || $name === 'src') && !$this->isSafeUrl($attr->nodeValue)) {
                $remove[] = $name;
            }
        }

        foreach ($remove as $name) {
            if ($node instanceof \DOMElement) {
                $node->removeAttribute($name);
            }
        }
    }

    private function isSafeUrl(?string $value): bool
    {
        $value = $value ?? '';
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = strtolower(trim($decoded));

        if ($normalized === '') {
            return false;
        }

        if (str_starts_with($normalized, 'javascript:')) {
            return false;
        }

        if (str_starts_with($normalized, 'data:')) {
            return false;
        }

        return true;
    }

    private function unwrapNode(DOMNode $parent, DOMNode $node): void
    {
        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }
}
