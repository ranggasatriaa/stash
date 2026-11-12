<?php

namespace App\Stash\Metadata;

use App\Stash\Support\UrlNormalizer;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Throwable;

/**
 * Extracts title, description, Open Graph data, site name and favicon from
 * untrusted HTML. Relative URLs are resolved against <base href> or the
 * final URL, and only http(s) results are kept.
 */
final class HtmlMetadataParser
{
    public const MAX_TITLE = 500;

    public const MAX_DESCRIPTION = 2000;

    /**
     * @return array{title: ?string, description: ?string, og_title: ?string, og_description: ?string, og_image: ?string, image_candidates: list<string>, site_name: ?string, favicon: ?string, canonical: ?string}
     */
    public function parse(string $html, string $baseUrl, ?string $charset = null): array
    {
        $empty = [
            'title' => null, 'description' => null, 'og_title' => null, 'og_description' => null,
            'og_image' => null, 'image_candidates' => [], 'site_name' => null, 'favicon' => null, 'canonical' => null,
        ];

        $html = self::toUtf8($html, $charset);
        if (trim($html) === '') {
            return $empty;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } catch (Throwable) {
            return $empty;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($dom);

        $base = $baseUrl;
        $baseHref = $this->attr($xpath, '//base[@href]', 'href');
        if ($baseHref) {
            $resolvedBase = $this->resolve($baseUrl, $baseHref);
            if ($resolvedBase) {
                $base = $resolvedBase;
            }
        }

        $meta = [];
        foreach ($xpath->query('//meta') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $key = strtolower(trim($node->getAttribute('property') ?: $node->getAttribute('name')));
            $content = $node->getAttribute('content');
            if ($key !== '' && $content !== '' && ! isset($meta[$key])) {
                $meta[$key] = $content;
            }
        }

        $titleNode = self::firstNode($xpath, '//title');
        $title = $titleNode?->textContent;

        $favicon = null;
        foreach ($xpath->query('//link[@rel][@href]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $rel = strtolower($node->getAttribute('rel'));
            if (preg_match('/(^|\s)(icon|shortcut icon|apple-touch-icon)(\s|$)/', $rel)) {
                $favicon = $this->resolve($base, $node->getAttribute('href'));
                if ($favicon) {
                    break;
                }
            }
        }

        $canonical = $this->attr($xpath, '//link[@rel="canonical"][@href]', 'href');

        // Preview image candidates, best first: Open Graph / Twitter cards,
        // video poster images, then <link rel="image_src"> and itemprop images.
        $rawImages = [
            $meta['og:image:secure_url'] ?? null,
            $meta['og:image'] ?? null,
            $meta['og:image:url'] ?? null,
            $meta['twitter:image'] ?? null,
            $meta['twitter:image:src'] ?? null,
            $meta['og:video:image'] ?? null,
            $meta['thumbnail'] ?? null,
            $this->attr($xpath, '//link[@rel="image_src"][@href]', 'href'),
            $this->attr($xpath, '//meta[@itemprop="thumbnailUrl"][@content]', 'content'),
            $this->attr($xpath, '//link[@itemprop="thumbnailUrl"][@href]', 'href'),
            $this->attr($xpath, '//video[@poster]', 'poster'),
        ];
        $imageCandidates = [];
        foreach ($rawImages as $raw) {
            $resolved = $this->resolve($base, $raw);
            if ($resolved !== null && ! in_array($resolved, $imageCandidates, true)) {
                $imageCandidates[] = $resolved;
            }
        }

        return [
            'title' => $this->clean($title, self::MAX_TITLE),
            'description' => $this->clean($meta['description'] ?? null, self::MAX_DESCRIPTION),
            'og_title' => $this->clean($meta['og:title'] ?? $meta['twitter:title'] ?? null, self::MAX_TITLE),
            'og_description' => $this->clean($meta['og:description'] ?? $meta['twitter:description'] ?? null, self::MAX_DESCRIPTION),
            'og_image' => $imageCandidates[0] ?? null,
            'image_candidates' => $imageCandidates,
            'site_name' => $this->clean($meta['og:site_name'] ?? $meta['application-name'] ?? null, 120),
            'favicon' => $favicon ?? $this->resolve($base, '/favicon.ico'),
            'canonical' => $canonical ? $this->resolve($base, $canonical) : null,
        ];
    }

    public static function toUtf8(string $html, ?string $charset): string
    {
        if ($charset === null && preg_match('/<meta[^>]+charset=["\']?\s*([a-z0-9_\-]+)/i', substr($html, 0, 4096), $m)) {
            $charset = $m[1];
        }

        $charset = $charset ? strtoupper($charset) : null;
        if ($charset && ! in_array($charset, ['UTF-8', 'UTF8'], true)) {
            try {
                $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
                if (is_string($converted)) {
                    return $converted;
                }
            } catch (Throwable) {
                // fall through to scrubbing
            }
        }

        return mb_scrub($html, 'UTF-8');
    }

    /** First DOM node matching an XPath query, or null (also when the query is invalid). */
    public static function firstNode(DOMXPath $xpath, string $query): ?DOMNode
    {
        $list = $xpath->query($query);
        $node = $list === false ? null : $list->item(0);

        return $node instanceof DOMNode ? $node : null;
    }

    private function attr(DOMXPath $xpath, string $query, string $attribute): ?string
    {
        $node = self::firstNode($xpath, $query);

        return $node instanceof DOMElement ? trim($node->getAttribute($attribute)) : null;
    }

    private function clean(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function resolve(string $base, ?string $href): ?string
    {
        if ($href === null || trim($href) === '' || strlen($href) > 2048) {
            return null;
        }

        try {
            $resolved = (string) UriResolver::resolve(Utils::uriFor($base), Utils::uriFor(trim($href)));
        } catch (Throwable) {
            return null;
        }

        return UrlNormalizer::isValidHttpUrl($resolved) ? $resolved : null;
    }
}
