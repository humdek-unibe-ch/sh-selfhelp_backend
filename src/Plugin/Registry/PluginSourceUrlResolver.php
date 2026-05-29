<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Registry;

use App\Entity\Plugin\PluginSource;

/**
 * Resolves the effective base URL for plugin sources.
 *
 * The canonical source configuration lives in `plugin_sources`. We
 * keep that model intact and only allow an env-driven override for the
 * seeded system source `humdek-public`, so operators can point the
 * official catalogue at a mirror without editing protected rows.
 */
final class PluginSourceUrlResolver
{
    public function __construct(
        private readonly string $defaultRegistryUrl,
    ) {
    }

    public function resolve(PluginSource $source): string
    {
        if ($source->isSystem() && $source->getName() === 'humdek-public') {
            return $this->defaultRegistryUrl;
        }

        return $source->getUrl();
    }
}
