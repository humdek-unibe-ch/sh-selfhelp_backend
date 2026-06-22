<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Tests\Support\QaWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behaviour contract for the AI section-generation prompt endpoint
 * `GET /admin/ai/section-prompt-template`
 * ({@see \App\Controller\Api\V1\Admin\AdminStyleController::getSectionPromptTemplate}).
 *
 * These tests pin the *cross-platform field contract* the redesigned prompt
 * teaches the LLM, so drift (re-introducing `shared_*` prefixes or the old
 * `web_cols`/`web_title_order` field names) fails CI:
 * - the rendered catalog carries the backend-derived `scope` per field and a
 *   per-style `renderTarget`, plus the scope legend;
 * - reserved cross-platform names keep their prefix (`shared_width`,
 *   `shared_icon`) and are marked `common`;
 * - obsolete prefixed names are gone;
 * - the additive `?format=json` returns the structured catalog and `?styles=`
 *   narrows it (compact / task-filtered prompt) — both back-compatible with the
 *   default `text/markdown` behaviour.
 */
final class AdminAiSectionPromptTemplateTest extends QaWebTestCase
{
    private const URI = '/cms-api/v1/admin/ai/section-prompt-template';

    private function getMarkdown(string $query = ''): string
    {
        $this->client->request(
            'GET',
            self::URI . $query,
            [],
            [],
            $this->authHeaders($this->loginAsQaAdmin()),
        );
        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString(
            'text/markdown',
            (string) $response->headers->get('Content-Type'),
            'Default format must stay text/markdown (back-compatible).',
        );
        return (string) $response->getContent();
    }

    public function testCatalogExposesScopeRenderTargetAndLegend(): void
    {
        $body = $this->getMarkdown();

        // Scope legend + the new compact per-field / per-style formats.
        self::assertStringContainsString('Field scope legend', $body);
        self::assertStringContainsString('renderTarget=both', $body);
        self::assertStringContainsString('### simple-grid (mantine, renderTarget=both)', $body);

        // simple-grid: base `cols` is the cross-platform (common) column count;
        // the per-breakpoint overrides are web-only.
        self::assertStringContainsString('cols (common, slider', $body);
        self::assertStringContainsString('web_cols_sm (web,', $body);

        // Scope tag must appear for every category.
        self::assertStringContainsString('(content,', $body);
        self::assertStringContainsString('(common,', $body);
        self::assertStringContainsString('(web,', $body);
    }

    public function testReservedSharedNamesStayCommonAndPrefixedDropIsHonoured(): void
    {
        $body = $this->getMarkdown();

        // The only legitimate shared_* survivors are the three reserved names,
        // and they are cross-platform (common).
        self::assertStringContainsString('shared_width (common,', $body);
        self::assertStringContainsString('shared_icon (common,', $body);

        // Pre-drop prefixed names must never come back.
        foreach (['shared_color', 'shared_size', 'shared_radius', 'shared_variant', 'shared_gap'] as $dead) {
            self::assertStringNotContainsString($dead, $body, sprintf('Obsolete prefixed field "%s" must not reappear.', $dead));
        }

        // Obsolete field names from the old prompt era.
        foreach (['web_title_order', 'web_grid_span', 'web_vertical_spacing'] as $dead) {
            self::assertStringNotContainsString($dead, $body, sprintf('Obsolete field "%s" must not reappear.', $dead));
        }

        // Old per-field locale tag format is gone (scope encodes locale now).
        self::assertStringNotContainsString('locale=en-GB|de-CH', $body);
    }

    public function testJsonFormatReturnsStructuredCatalog(): void
    {
        $token = $this->loginAsQaAdmin();
        $envelope = $this->jsonRequest('GET', self::URI . '?format=json', null, $token);
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertArrayHasKey('simple-grid', $data, 'JSON catalog must contain styles keyed by name.');
        $grid = $data['simple-grid'];
        self::assertIsArray($grid);
        self::assertSame('both', $grid['renderTarget'] ?? null);

        $fields = $grid['fields'] ?? null;
        self::assertIsArray($fields);

        $cols = $fields['cols'] ?? null;
        self::assertIsArray($cols);
        self::assertSame('common', $cols['scope'] ?? null, '`cols` must be a common (cross-platform) field.');

        $webColsSm = $fields['web_cols_sm'] ?? null;
        self::assertIsArray($webColsSm);
        self::assertSame('web', $webColsSm['scope'] ?? null, '`web_cols_sm` must be a web-only field.');
    }

    public function testStylesFilterNarrowsTheCatalog(): void
    {
        // JSON: the filtered catalog contains exactly the requested styles.
        $token = $this->loginAsQaAdmin();
        $envelope = $this->jsonRequest('GET', self::URI . '?format=json&styles=simple-grid,card', null, $token);
        $data = $this->assertEnvelopeSuccess($envelope);
        $keys = array_keys($data);
        sort($keys);
        self::assertSame(['card', 'simple-grid'], $keys, 'The ?styles= filter must restrict the JSON catalog.');

        // Markdown: filtered prompt keeps requested styles and drops the rest.
        $body = $this->getMarkdown('?styles=simple-grid,card');
        self::assertStringContainsString('### simple-grid (mantine,', $body);
        self::assertStringContainsString('### card (mantine,', $body);
        self::assertStringNotContainsString('### accordion (', $body);
    }
}
