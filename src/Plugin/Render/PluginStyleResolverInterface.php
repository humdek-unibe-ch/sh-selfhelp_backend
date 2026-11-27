<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Render;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A plugin's per-request answer for one style of a page (contract C6).
 *
 * A service that implements it is tagged `selfhelp.plugin.style_resolver`
 * automatically. The host registry calls {@see resolve()} after the page
 * cache, for every section of that style that is still on the page after
 * conditions, and only when an **enabled** plugin declares the style in its
 * manifest. A throw becomes `plugin_error: "resolver_failed"` on that
 * section only; the rest of the page still renders.
 *
 * The answer's `data` is placed at `section.plugin` and never overwrites a
 * core key. `dependsOn` tags that pass the host filter join the page's
 * `sync.depends_on`.
 */
#[AutoconfigureTag(PluginStyleResolverInterface::TAG)]
interface PluginStyleResolverInterface
{
    /** The container tag the registry collects. */
    public const TAG = 'selfhelp.plugin.style_resolver';

    /** The style name this resolver fills (a style the plugin's manifest declares). */
    public function styleName(): string;

    /**
     * @param array<string, mixed> $section the section as the page payload holds it (after conditions)
     */
    public function resolve(array $section, PluginRenderContext $context): PluginResolvedSection;
}
