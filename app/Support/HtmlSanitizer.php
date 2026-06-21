<?php

namespace App\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;

class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'b', 'blockquote', 'br', 'code', 'div', 'em', 'h1', 'h2', 'h3', 'h4',
        'hr', 'i', 'img', 'li', 'ol', 'p', 'pre', 's', 'span', 'strong', 'table',
        'tbody', 'td', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    private const DROP_WITH_CONTENT = [
        'applet', 'audio', 'embed', 'form', 'iframe', 'math', 'object', 'script', 'style', 'svg', 'template', 'video',
    ];

    private const ALLOWED_STYLE_PROPERTIES = [
        'background-color', 'border', 'border-color', 'border-radius', 'border-style', 'border-width',
        'color', 'font-family', 'font-size', 'font-style', 'font-weight', 'line-height',
        'margin', 'margin-bottom', 'margin-left', 'margin-right', 'margin-top',
        'padding', 'padding-bottom', 'padding-left', 'padding-right', 'padding-top',
        'text-align', 'text-decoration', 'white-space',
    ];

    public static function sanitize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return $value;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div data-sanitizer-root="1">'.$value.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $root = $document->getElementsByTagName('div')->item(0);

        if (! $root) {
            return '';
        }

        self::cleanChildren($root);
        $html = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $html .= $document->saveHTML($child) ?: '';
        }

        return trim($html);
    }

    public static function sanitizeBuilderJson(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sanitizeBuilderJson($item);

                continue;
            }

            if (is_string($item) && self::isRichTextBuilderKey((string) $key)) {
                $value[$key] = self::sanitize($item) ?? '';
            }
        }

        return $value;
    }

    private static function cleanChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $parent->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $parent->removeChild($child);

                continue;
            }

            self::cleanAttributes($child, $tag);
            self::cleanChildren($child);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild) {
                    $parent->insertBefore($child->firstChild, $child);
                }

                $parent->removeChild($child);
            }
        }
    }

    private static function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = match ($tag) {
            'a' => ['href', 'style', 'target', 'title'],
            'img' => ['alt', 'height', 'loading', 'src', 'style', 'title', 'width'],
            'td', 'th' => ['colspan', 'rowspan', 'style'],
            default => ['style'],
        };

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        if ($element->hasAttribute('href') && ! self::isSafeUrl($element->getAttribute('href'), false)) {
            $element->removeAttribute('href');
        }

        if ($element->hasAttribute('src') && ! self::isSafeUrl($element->getAttribute('src'), true)) {
            $element->removeAttribute('src');
        }

        if ($element->hasAttribute('target')) {
            $target = strtolower(trim($element->getAttribute('target')));

            if (! in_array($target, ['_blank', '_self'], true)) {
                $element->removeAttribute('target');
            } elseif ($target === '_blank') {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }

        if ($element->hasAttribute('loading') && strtolower($element->getAttribute('loading')) !== 'lazy') {
            $element->removeAttribute('loading');
        }

        if ($element->hasAttribute('style')) {
            $style = self::sanitizeStyle($element->getAttribute('style'));

            if ($style === '') {
                $element->removeAttribute('style');
            } else {
                $element->setAttribute('style', $style);
            }
        }
    }

    private static function sanitizeStyle(string $style): string
    {
        $safe = [];

        foreach (explode(';', $style) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            $property = strtolower(trim($property));
            $value = trim($value);

            if (! in_array($property, self::ALLOWED_STYLE_PROPERTIES, true) || $value === '') {
                continue;
            }

            $normalized = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $value));

            if (str_contains($normalized, 'url(')
                || str_contains($normalized, 'expression(')
                || str_contains($normalized, '@import')
                || str_contains($normalized, 'javascript:')
                || str_contains($normalized, '\\')) {
                continue;
            }

            $safe[] = $property.': '.$value;
        }

        return implode('; ', $safe);
    }

    private static function isSafeUrl(string $url, bool $image): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        $normalized = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $url));

        if (str_starts_with($normalized, '/') && ! str_starts_with($normalized, '//')) {
            return true;
        }

        if (! $image && str_starts_with($normalized, '#')) {
            return true;
        }

        $allowedSchemes = $image ? ['http', 'https'] : ['http', 'https', 'mailto', 'tel'];
        $scheme = parse_url($normalized, PHP_URL_SCHEME);

        return is_string($scheme) && in_array($scheme, $allowedSchemes, true);
    }

    private static function isRichTextBuilderKey(string $key): bool
    {
        $key = strtolower($key);

        return in_array($key, ['html', 'body'], true)
            || str_starts_with($key, 'sectionbody')
            || str_starts_with($key, 'faqanswer');
    }
}
