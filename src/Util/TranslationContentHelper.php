<?php

namespace App\Util;

/**
 * TranslationContentHelper
 *
 * Utility helpers for inspecting translation content payloads.
 *
 * The CMS frontend uses a rich-text editor that often persists "empty"
 * fields as HTML wrappers (e.g. `<p class="single-line-paragraph"></p>`,
 * `<p><br></p>`, `<p>&nbsp;</p>`). A naive `trim()` check treats those as
 * non-empty, which breaks the language-fallback merge: the requested
 * language wins with a visually empty wrapper instead of falling back to
 * the CMS default language.
 *
 * This helper centralises the "is this content user-visibly empty?"
 * decision so every translation merge path applies the same rule.
 *
 * @package App\Util
 */
final class TranslationContentHelper
{
    /**
     * HTML elements that carry meaning even with no text content.
     * Presence of any of these means the field is NOT empty.
     */
    private const MEDIA_TAG_PATTERN = '/<(img|video|audio|iframe|embed|object|source|svg|canvas|picture|track)\b/i';

    /**
     * Determine whether a translation field's content is user-visibly empty.
     *
     * Considered empty:
     *   - null
     *   - empty string
     *   - whitespace-only (incl. `&nbsp;`, `\xC2\xA0`, tabs, newlines)
     *   - HTML wrapper(s) with no inner text and no media tags
     *     (e.g. `<p></p>`, `<p><br/></p>`, `<p class="single-line-paragraph"></p>`)
     *
     * Considered NOT empty:
     *   - any string with text after stripping tags/entities
     *   - HTML containing media tags (img, video, audio, iframe, ...)
     *
     * Non-string values (other than null) are conservatively treated as
     * non-empty so that numeric `0`, booleans, and arrays don't get silently
     * replaced by a fallback translation.
     *
     * @param mixed $content
     */
    public static function isEffectivelyEmpty($content): bool
    {
        if ($content === null) {
            return true;
        }

        if (!is_string($content)) {
            return false;
        }

        if (trim($content) === '') {
            return true;
        }

        // Media tags carry meaning even with no surrounding text.
        if (preg_match(self::MEDIA_TAG_PATTERN, $content) === 1) {
            return false;
        }

        $stripped = strip_tags($content);
        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Strip any unicode whitespace (incl. NBSP / U+00A0).
        $stripped = preg_replace('/\s+/u', '', $stripped) ?? '';

        return $stripped === '';
    }
}
