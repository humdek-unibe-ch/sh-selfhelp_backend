<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Render;

/**
 * What a style resolver returns for one section (contract C6).
 *
 * `data` becomes `section.plugin`. `dependsOn` is a list of change tags
 * (`res:<type>:<id>` of registered types, and `table:<id>` of tables the
 * resolver read); the host keeps only well-formed tags of registered types
 * and existing tables, and logs the rest.
 */
final readonly class PluginResolvedSection
{
    /**
     * @param array<string, mixed> $data placed at `section.plugin`
     * @param list<string> $dependsOn change tags this section's plugin data depends on
     */
    public function __construct(
        public array $data,
        public array $dependsOn = [],
    ) {
    }
}
