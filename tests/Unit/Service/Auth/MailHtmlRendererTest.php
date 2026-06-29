<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\MailHtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the email body renderer: the WYSIWYG fragment must be wrapped
 * in the branded shell and every base tag / named preset class must be inlined as
 * email-client-safe CSS, while legacy full documents pass through untouched.
 */
final class MailHtmlRendererTest extends TestCase
{
    private MailHtmlRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new MailHtmlRenderer();
    }

    public function testWrapsFragmentInBrandedShell(): void
    {
        $html = $this->renderer->render('<p>Hello</p>');

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('max-width:600px', $html);
        self::assertStringContainsString('<p style="margin:0 0 16px;">Hello</p>', $html);
    }

    public function testInlinesPrimaryButtonPresetAndForcesLinkColour(): void
    {
        $html = $this->renderer->render(
            '<p style="text-align: center"><a href="https://example.test/go" class="email-button">Go</a></p>'
        );

        // The preset's inline declarations land on the styled anchor...
        self::assertStringContainsString('background-color:#2f6fed', $html);
        self::assertStringContainsString('display:inline-block', $html);
        // ...and the link text is forced to the button colour so it stays readable.
        self::assertStringContainsString('color:#ffffff', $html);
        // The author's own alignment style is preserved (merged, not dropped).
        self::assertStringContainsString('text-align: center', $html);
        // The href survives untouched.
        self::assertStringContainsString('href="https://example.test/go"', $html);
    }

    public function testInlinesMutedAndCalloutAndCodePresets(): void
    {
        $html = $this->renderer->render(
            '<p class="email-muted">small</p>'
            . '<p class="email-callout">https://example.test/very/long/url</p>'
            . '<p><span class="email-code">123456</span></p>'
        );

        self::assertStringContainsString('color:#6b7280', $html);
        self::assertStringContainsString('word-break:break-all', $html);
        self::assertStringContainsString('letter-spacing:6px', $html);
    }

    public function testAppliesBaseTagStyling(): void
    {
        $html = $this->renderer->render('<h2>Title</h2><hr><a href="https://example.test">Link</a>');

        self::assertStringContainsString('font-size:20px', $html);
        self::assertStringContainsString('border-top:1px solid #e5e7eb', $html);
        self::assertStringContainsString('color:#2f6fed', $html);
    }

    public function testPreservesUtf8Content(): void
    {
        $html = $this->renderer->render('<p>Grüezi, schön — café</p>');

        self::assertStringContainsString('Grüezi, schön — café', $html);
    }

    public function testLegacyFullDocumentIsPassedThroughUntouched(): void
    {
        $legacy = '<!DOCTYPE html><html><body style="color:#333"><p>Old email</p></body></html>';

        self::assertSame($legacy, $this->renderer->render($legacy));
    }

    public function testEmptyBodyStillProducesAValidShell(): void
    {
        $html = $this->renderer->render('   ');

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('max-width:600px', $html);
    }
}
