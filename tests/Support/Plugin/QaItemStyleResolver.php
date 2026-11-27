<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Plugin;

use App\Plugin\Render\PluginRenderContext;
use App\Plugin\Render\PluginResolvedSection;
use App\Plugin\Render\PluginStyleResolverInterface;
use App\Service\Sync\SyncTag;

/**
 * QA style resolver for {@see QaItemPluginFixture::STYLE} (contract C6 tests).
 *
 * Reads the item id from the section's `qa_item_id` field, returns it under
 * `section.plugin`, and optionally lists a `table:` tag and a bogus tag so
 * the host filter can be proven. A static counter proves it runs again on a
 * page-cache hit. When {@see $throwNext} is true, the next resolve throws.
 */
final class QaItemStyleResolver implements PluginStyleResolverInterface
{
    /** How many times {@see resolve()} was entered since the last reset. */
    public static int $calls = 0;

    /** When true, the next {@see resolve()} throws and the flag clears. */
    public static bool $throwNext = false;

    /** When set, {@see resolve()} throws for that item id only. */
    public static ?int $throwForItemId = null;

    /**
     * When set to a positive id, every resolve lists `table:<id>` in dependsOn
     * (plus an unregistered `res:` tag and a missing `table:` id for the filter).
     */
    public static ?int $tableDependsOn = null;

    public static function reset(): void
    {
        self::$calls = 0;
        self::$throwNext = false;
        self::$throwForItemId = null;
        self::$tableDependsOn = null;
    }

    public function styleName(): string
    {
        return QaItemPluginFixture::STYLE;
    }

    public function resolve(array $section, PluginRenderContext $context): PluginResolvedSection
    {
        ++self::$calls;
        $itemId = $this->itemIdOf($section);
        if (self::$throwNext) {
            self::$throwNext = false;
            throw new \RuntimeException('QA resolver forced failure');
        }
        if (self::$throwForItemId !== null && $itemId === self::$throwForItemId) {
            throw new \RuntimeException('QA resolver forced failure for item ' . $itemId);
        }

        $dependsOn = [];
        if (self::$tableDependsOn !== null && self::$tableDependsOn > 0) {
            $dependsOn[] = SyncTag::table(self::$tableDependsOn);
            $dependsOn[] = 'res:not_a_registered_type:9';
            $dependsOn[] = SyncTag::table(2_147_483_647);
        }
        if ($itemId > 0) {
            $dependsOn[] = SyncTag::resource(QaItemPluginFixture::TYPE_CODE, $itemId);
        }

        return new PluginResolvedSection(
            [
                'item_id' => $itemId,
                'user_id' => $context->userId,
                'page_id' => $context->pageId,
                'language_id' => $context->languageId,
                'serves_published' => $context->servesPublished,
                'preview' => $context->preview,
                'platform' => $context->platform,
            ],
            $dependsOn,
        );
    }

    /**
     * @param array<string, mixed> $section
     */
    private function itemIdOf(array $section): int
    {
        $raw = $section[QaItemPluginFixture::FIELD] ?? null;
        if (is_array($raw) && is_scalar($raw['content'] ?? null)) {
            $text = trim((string) $raw['content']);

            return ctype_digit($text) ? (int) $text : 0;
        }
        if (is_scalar($raw)) {
            $text = trim((string) $raw);

            return ctype_digit($text) ? (int) $text : 0;
        }

        return 0;
    }
}
