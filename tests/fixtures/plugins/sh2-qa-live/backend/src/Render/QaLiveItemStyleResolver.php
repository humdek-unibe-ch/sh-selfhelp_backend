<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace Humdek\QaLiveBundle\Render;

use App\Plugin\Render\PluginRenderContext;
use App\Plugin\Render\PluginResolvedSection;
use App\Plugin\Render\PluginStyleResolverInterface;
use App\Plugin\Resource\PluginResourceServiceInterface;
use App\Service\Cache\Core\CacheService;
use App\Service\Sync\SyncTag;
use Humdek\QaLiveBundle\QaLiveCatalogue;
use Humdek\QaLiveBundle\Service\QaLiveItemService;

final class QaLiveItemStyleResolver implements PluginStyleResolverInterface
{
    public function __construct(
        private readonly QaLiveItemService $items,
        private readonly PluginResourceServiceInterface $resources,
        private readonly CacheService $cache,
    ) {
    }

    public function styleName(): string
    {
        return QaLiveCatalogue::STYLE;
    }

    public function resolve(array $section, PluginRenderContext $context): PluginResolvedSection
    {
        $itemId = $this->positiveIntField($section, QaLiveCatalogue::FIELD_ITEM_ID);
        $dependsOn = $itemId > 0
            ? [SyncTag::resource(QaLiveCatalogue::TYPE_CODE, $itemId)]
            : [];

        if ($itemId < 1) {
            return new PluginResolvedSection(['not_found' => true], $dependsOn);
        }

        $pinned = null;
        $pinTableId = $this->positiveIntField($section, QaLiveCatalogue::FIELD_PIN_TABLE_ID);
        if ($pinTableId > 0 && $context->userId > 0) {
            $pinned = $this->resources->pinnedVersion(
                $context->userId,
                $pinTableId,
                QaLiveCatalogue::TYPE_CODE,
                $itemId,
            );
        }

        // Cache item keys become Symfony cache tags; colons/`@`/slashes are reserved.
        $cacheKey = sprintf(
            'resolve_%d_u_%d_pin_%s',
            $itemId,
            $context->userId,
            $pinned === null ? 'none' : (string) $pinned,
        );
        $resolved = $this->cache
            ->withCategory(QaLiveCatalogue::CACHE_CATEGORY)
            ->withResourceScope(QaLiveCatalogue::TYPE_CODE, $itemId)
            ->getItem($cacheKey, fn (): ?array => $this->items->resolvePublished($itemId, $pinned));

        if ($resolved === null) {
            return new PluginResolvedSection(['not_found' => true], $dependsOn);
        }

        return new PluginResolvedSection(
            [
                'title' => $resolved['title'],
                'version' => $resolved['version'],
            ],
            $dependsOn,
        );
    }

    /**
     * @param array<string, mixed> $section
     */
    private function positiveIntField(array $section, string $name): int
    {
        $raw = $section[$name] ?? null;
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