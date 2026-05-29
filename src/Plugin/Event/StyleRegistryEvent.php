<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when the admin style controller builds the catalog of
 * styles exposed to the admin builder. Plugin bundles may listen and
 * contribute additional styles through `addStyle()`.
 *
 * The style name must also be declared in `plugin.json` under `styles`
 * and seeded into the `styles` table by the plugin installer. This
 * event is the place where plugins surface the style to the admin UI
 * (descriptions, allowed children, default category).
 */
final class StyleRegistryEvent extends Event
{
    /**
     * @var array<int, array{
     *   pluginId: string,
     *   name: string,
     *   description: string,
     *   category: string,
     *   canHaveChildren: bool,
     * }>
     */
    private array $styles = [];

    public function addStyle(
        string $pluginId,
        string $name,
        string $description,
        string $category,
        bool $canHaveChildren,
    ): void {
        $this->styles[] = [
            'pluginId' => $pluginId,
            'name' => $name,
            'description' => $description,
            'category' => $category,
            'canHaveChildren' => $canHaveChildren,
        ];
    }

    /**
     * @return array<int, array{
     *   pluginId: string,
     *   name: string,
     *   description: string,
     *   category: string,
     *   canHaveChildren: bool,
     * }>
     */
    public function getStyles(): array
    {
        return $this->styles;
    }
}
