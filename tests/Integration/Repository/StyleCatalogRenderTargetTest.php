<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\StyleRepository;
use App\Tests\Support\QaKernelTestCase;

/**
 * Integration coverage for the CMS style catalog render-target contract
 * ({@see StyleRepository::findAllStylesGroupedByGroup} +
 * {@see StyleRepository::findAllStylesWithFields}) that the frontend
 * Add-Section picker / section inspector and the mobile renderer consume.
 *
 * Proves the contract seeded by Version20260618143215 (render-target FK +
 * backfill to `both`):
 *   - every catalog style exposes a `renderTarget` in {web, mobile, both};
 *   - ordinary core styles report `both`;
 *   - the established catalog has exactly 91 styles (milestone-one + entry-record-form);
 *   - the 8 styles previously missing from shared/mobile are present;
 *   - none of the 16 deferred speculative styles are seeded.
 */
final class StyleCatalogRenderTargetTest extends QaKernelTestCase
{
    /** Established backend catalog size (milestone one + entry-record-form). */
    private const ESTABLISHED_STYLE_COUNT = 91;

    /** Styles that must exist (previously absent from shared/mobile registries). */
    private const FORMERLY_MISSING = [
        'data-container', 'timeline-item', 'version', 'no-access',
        // `entry-table` is the renamed `show-user-input` (Version20260710093048).
        'missing', 'not-found', 'ref-container', 'entry-table',
    ];

    /** Deferred speculative styles that must NOT be in the milestone-one catalog. */
    private const DEFERRED_SPECULATIVE = [
        'dialog', 'popover', 'menu', 'menu-item', 'bottom-sheet',
        'skeleton', 'skeleton-group', 'spinner', 'toast',
        'tag-group', 'tag', 'input-group', 'input-otp', 'search-field',
        'fab-button', 'biometric-login-button',
    ];

    private StyleRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->service(StyleRepository::class);
    }

    /**
     * @return list<array<string, mixed>> Flattened catalog styles.
     */
    private function catalogStyles(): array
    {
        $styles = [];
        foreach ($this->repository->findAllStylesGroupedByGroup() as $group) {
            foreach (self::asList($group['styles'] ?? []) as $style) {
                $styles[] = self::asArray($style);
            }
        }

        return $styles;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findStyle(string $name): ?array
    {
        foreach ($this->catalogStyles() as $style) {
            if (($style['name'] ?? null) === $name) {
                return $style;
            }
        }

        return null;
    }

    public function testEveryCatalogStyleExposesAValidRenderTarget(): void
    {
        $styles = $this->catalogStyles();
        self::assertNotEmpty($styles, 'The QA baseline must seed CMS styles.');

        foreach ($styles as $style) {
            self::assertArrayHasKey('renderTarget', $style, 'Every catalog style must expose a renderTarget.');
            $name = self::asString($style['name'] ?? null);
            $renderTarget = self::asString($style['renderTarget'] ?? null);
            self::assertContains(
                $renderTarget,
                ['web', 'mobile', 'both'],
                "Style '{$name}' has an invalid renderTarget '{$renderTarget}'."
            );
        }
    }

    public function testCoreStylesDefaultToBothRenderTargets(): void
    {
        $container = $this->findStyle('container');
        self::assertNotNull($container, 'The baseline must seed the container style.');
        self::assertSame('both', self::asString($container['renderTarget'] ?? null), 'Core styles render on web and mobile.');
    }

    public function testEstablishedCatalogHasMilestoneOneParity(): void
    {
        self::assertCount(
            self::ESTABLISHED_STYLE_COUNT,
            $this->catalogStyles(),
            'The established catalog must contain exactly 91 styles.'
        );
    }

    public function testFormerlyMissingStylesArePresent(): void
    {
        foreach (self::FORMERLY_MISSING as $name) {
            self::assertNotNull(
                $this->findStyle($name),
                "Established style '{$name}' must be present in the catalog."
            );
        }
    }

    public function testDeferredSpeculativeStylesAreNotSeeded(): void
    {
        foreach (self::DEFERRED_SPECULATIVE as $name) {
            self::assertNull(
                $this->findStyle($name),
                "Deferred speculative style '{$name}' must not be in the milestone-one catalog."
            );
        }
    }

    public function testRenderTargetIsAlsoExposedByTheSchemaEndpointShape(): void
    {
        $schema = $this->repository->findAllStylesWithFields();
        self::assertArrayHasKey('container', $schema, 'The style schema must include the container style.');
        self::assertSame('both', self::asString($schema['container']['renderTarget'] ?? null));
    }
}
