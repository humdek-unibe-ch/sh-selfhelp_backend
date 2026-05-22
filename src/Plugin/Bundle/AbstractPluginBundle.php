<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Bundle;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Base class every SelfHelp plugin bundle SHOULD extend. The host
 * `PluginRegistryService` uses this base class as a marker so it can
 * locate plugin bundles in the container without listing them by name.
 *
 * Plugin bundles do NOT need to override anything from this class; the
 * default Symfony `AbstractBundle` behavior is correct. The base class
 * exists so:
 *
 *   - `instanceof AbstractPluginBundle` is the unambiguous test for
 *     "this is a SelfHelp plugin bundle";
 *   - the doctor command can detect bundles that are loaded but not
 *     registered in `plugins` table (`composer require`-d but not
 *     installed);
 *   - the bundle exposes `getPluginId()` so the registry can map a
 *     bundle to its manifest row.
 *
 * Concrete plugin bundles must implement `getPluginId()` to return the
 * manifest `id` (kebab-case). Everything else can use Symfony defaults.
 */
abstract class AbstractPluginBundle extends AbstractBundle
{
    /**
     * Plugin manifest id, e.g. `sh2-shp-survey-js`.
     */
    abstract public function getPluginId(): string;
}
