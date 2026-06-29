<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Renders a WYSIWYG email-body fragment into a complete, email-client-safe HTML
 * document.
 *
 * Admins edit mail bodies in the CMS rich-text editor (never raw HTML). The
 * editor stores a small content fragment (headings, paragraphs, links, lists)
 * plus the named "email style" presets it attaches as CSS classes
 * (`email-button`, `email-callout`, …). This renderer:
 *
 *   1. Inlines the base tag styles + every preset class as inline CSS — email
 *      clients strip `<style>`/`<head>` blocks, so styling MUST be inline.
 *   2. Wraps the styled content in a branded, centered "shell" (the consistent
 *      look-and-feel every email shares).
 *
 * Legacy full HTML documents (a body that still starts with `<!doctype>`/
 * `<html>`/`<body>`) are passed through untouched so an already hand-authored
 * email is never double-wrapped.
 *
 * The {@see self::PRESET_STYLES} map is the SERVER half of the email-style
 * contract; the frontend editor's Style dropdown attaches the matching classes
 * (see `config/mentions.config` / the email rich-text field). Adding a preset =
 * one entry here + one item in the editor Style menu (documented in
 * docs/reference/email-styles.md).
 */
final class MailHtmlRenderer
{
    /**
     * Named email-style presets: CSS class => email-safe inline declarations.
     *
     * @var array<string, string>
     */
    private const PRESET_STYLES = [
        'email-button' => 'display:inline-block;background-color:#2f6fed;color:#ffffff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:600;',
        'email-button-secondary' => 'display:inline-block;background-color:#ffffff;color:#2f6fed;padding:11px 27px;border:1px solid #2f6fed;border-radius:6px;text-decoration:none;font-weight:600;',
        'email-link-strong' => 'color:#2f6fed;font-weight:700;text-decoration:underline;',
        'email-muted' => 'color:#6b7280;font-size:12px;line-height:1.5;',
        'email-callout' => 'display:block;background-color:#f3f4f6;border-radius:6px;padding:12px 16px;word-break:break-all;color:#374151;',
        'email-code' => 'display:inline-block;font-family:Menlo,Consolas,monospace;font-size:30px;font-weight:700;letter-spacing:6px;color:#111827;',
    ];

    /**
     * Base styling applied to bare tags so unstyled authored content still looks
     * on-brand.
     *
     * @var array<string, string>
     */
    private const BASE_TAG_STYLES = [
        'h1' => 'margin:0 0 16px;font-size:24px;font-weight:700;color:#111827;',
        'h2' => 'margin:0 0 16px;font-size:20px;font-weight:700;color:#111827;',
        'h3' => 'margin:0 0 12px;font-size:18px;font-weight:700;color:#111827;',
        'h4' => 'margin:0 0 12px;font-size:16px;font-weight:700;color:#111827;',
        'p' => 'margin:0 0 16px;',
        'a' => 'color:#2f6fed;',
        'ul' => 'margin:0 0 16px;padding-left:22px;',
        'ol' => 'margin:0 0 16px;padding-left:22px;',
        'li' => 'margin:0 0 6px;',
        'hr' => 'border:none;border-top:1px solid #e5e7eb;margin:24px 0;',
        'blockquote' => 'margin:0 0 16px;padding-left:14px;border-left:3px solid #e5e7eb;color:#4b5563;',
    ];

    /**
     * Preset classes whose host/nested `<a>` must inherit the preset's text
     * colour (a button's link defaults to blue and would be unreadable).
     *
     * @var array<string, string>
     */
    private const LINK_COLOR_CLASSES = [
        'email-button' => '#ffffff',
        'email-button-secondary' => '#2f6fed',
        'email-link-strong' => '#2f6fed',
    ];

    /**
     * Render a body fragment into a complete branded HTML email. Full HTML
     * documents are returned verbatim (legacy passthrough).
     */
    public function render(string $bodyFragment): string
    {
        $trimmed = trim($bodyFragment);
        if ($trimmed === '') {
            return $this->wrap('');
        }

        // Legacy full documents already carry structure + styles: never re-wrap.
        if (preg_match('/<\s*(!doctype|html|body)\b/i', $trimmed) === 1) {
            return $bodyFragment;
        }

        return $this->wrap($this->inline($trimmed));
    }

    /**
     * Inline the base tag styles + preset class styles onto every element of the
     * fragment so the styling survives email clients.
     */
    private function inline(string $fragment): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // Own `<body>` wrapper + XML encoding hint keeps UTF-8 intact and avoids
        // an implied <html><head> being injected around our fragment.
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"?><body>' . $fragment . '</body>',
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return $fragment;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return $fragment;
        }

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('.//*', $body);
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if ($node instanceof \DOMElement) {
                    $this->applyStyles($node);
                }
            }
        }

        $html = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $html .= (string) $dom->saveHTML($child);
        }

        return $html;
    }

    /**
     * Merge the base tag style + any preset class styles into an element's inline
     * `style`. Authored inline styles (e.g. `text-align`) are appended last so
     * they win on conflict.
     */
    private function applyStyles(\DOMElement $el): void
    {
        $tag = strtolower($el->tagName);

        $declarations = [];
        if (isset(self::BASE_TAG_STYLES[$tag])) {
            $declarations[] = self::BASE_TAG_STYLES[$tag];
        }

        $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];
        foreach ($classes as $class) {
            if ($class === '') {
                continue;
            }
            if (isset(self::PRESET_STYLES[$class])) {
                $declarations[] = self::PRESET_STYLES[$class];
            }
            if (isset(self::LINK_COLOR_CLASSES[$class])) {
                $this->forceLinkColor($el, self::LINK_COLOR_CLASSES[$class]);
            }
        }

        if ($declarations === []) {
            return;
        }

        $el->setAttribute('style', implode('', $declarations) . trim($el->getAttribute('style')));
    }

    /**
     * Force the colour of the element itself (if it is an `<a>`) and any nested
     * `<a>` so a button/link preset's link text matches the preset.
     */
    private function forceLinkColor(\DOMElement $el, string $color): void
    {
        $anchors = [];
        if (strtolower($el->tagName) === 'a') {
            $anchors[] = $el;
        }
        foreach ($el->getElementsByTagName('a') as $anchor) {
            $anchors[] = $anchor;
        }

        foreach ($anchors as $anchor) {
            $anchor->setAttribute(
                'style',
                'color:' . $color . ';text-decoration:none;' . trim($anchor->getAttribute('style'))
            );
        }
    }

    /**
     * Wrap styled content in the shared branded shell (centered card).
     */
    private function wrap(string $content): string
    {
        return '<!DOCTYPE html>'
            . '<html lang="en"><head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '</head>'
            . '<body style="margin:0;padding:0;background-color:#f4f5f7;">'
            . '<div style="max-width:600px;margin:0 auto;padding:24px;'
            . 'font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;'
            . 'color:#333333;background-color:#ffffff;">'
            . $content
            . '</div>'
            . '</body></html>';
    }
}
