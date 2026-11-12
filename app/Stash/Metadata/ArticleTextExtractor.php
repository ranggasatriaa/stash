<?php

namespace App\Stash\Metadata;

use DOMDocument;
use DOMElement;
use DOMNameSpaceNode;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Throwable;

/**
 * Bounded, dependency-free readable-text extraction. Strips scripts, styles,
 * navigation and other chrome, then prefers <article>/<main> content and
 * finally the densest paragraph container. Output is plain text only.
 */
final class ArticleTextExtractor
{
    private const REMOVE = ['script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'object', 'embed', 'form', 'nav', 'header', 'footer', 'aside', 'button', 'select', 'input', 'textarea'];

    public function extract(string $html, ?string $charset, int $maxChars): string
    {
        $html = HtmlMetadataParser::toUtf8($html, $charset);
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } catch (Throwable) {
            return '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($dom);
        foreach (self::REMOVE as $tag) {
            $this->removeNodes($xpath->query('//'.$tag));
        }
        $this->removeNodes($xpath->query('//*[@aria-hidden="true" or @hidden]'));

        $root = HtmlMetadataParser::firstNode($xpath, '//article')
            ?? HtmlMetadataParser::firstNode($xpath, '//main')
            ?? HtmlMetadataParser::firstNode($xpath, '//*[@role="main"]')
            ?? $this->densestContainer($xpath)
            ?? HtmlMetadataParser::firstNode($xpath, '//body');

        if (! $root) {
            return '';
        }

        $blocks = [];
        foreach ($xpath->query('.//h1|.//h2|.//h3|.//h4|.//p|.//li|.//blockquote|.//pre', $root) ?: [] as $node) {
            if (! $node instanceof DOMNode) {
                continue;
            }
            $text = $this->normalize($node->textContent);
            if (mb_strlen($text) >= 2) {
                $blocks[] = $text;
            }
        }

        if ($blocks === []) {
            $blocks[] = $this->normalize($root->textContent);
        }

        $text = implode("\n\n", array_unique($blocks));

        return mb_substr(trim($text), 0, $maxChars);
    }

    /** @param DOMNodeList<DOMNameSpaceNode|DOMNode>|false $nodes */
    private function removeNodes(DOMNodeList|false $nodes): void
    {
        foreach (iterator_to_array($nodes ?: []) as $node) {
            if ($node instanceof DOMNode) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    private function densestContainer(DOMXPath $xpath): ?DOMNode
    {
        $best = null;
        $bestScore = 0;
        foreach ($xpath->query('//p') ?: [] as $p) {
            $parent = $p->parentNode;
            if (! $parent instanceof DOMElement) {
                continue;
            }
            $score = 0;
            foreach ($parent->childNodes as $child) {
                if ($child instanceof DOMElement && $child->tagName === 'p') {
                    $score += mb_strlen($child->textContent);
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $parent;
            }
        }

        return $bestScore > 200 ? $best : null;
    }

    private function normalize(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
