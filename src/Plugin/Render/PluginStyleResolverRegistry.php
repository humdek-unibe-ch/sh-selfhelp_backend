<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Render;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use App\Plugin\Resource\RegisteredResourceTypes;
use App\Repository\DataTableRepository;
use App\Service\Sync\SyncTag;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs the style resolvers of enabled plugins after the page cache (C6).
 *
 * For each section still on the page it finds a resolver whose
 * {@see PluginStyleResolverInterface::styleName()} matches and whose style
 * an enabled plugin declares; a throw becomes `plugin_error` on that section
 * only. Collected `dependsOn` tags are filtered to registered `res:` types
 * and existing `table:` ids.
 */
final class PluginStyleResolverRegistry
{
    /** @var array<string, PluginStyleResolverInterface>|null style name => resolver (one per style) */
    private ?array $byStyle = null;

    /**
     * @param iterable<PluginStyleResolverInterface> $resolvers every resolver service of every plugin
     */
    public function __construct(
        private readonly PluginRegistryService $registry,
        private readonly RegisteredResourceTypes $registeredTypes,
        private readonly DataTableRepository $dataTables,
        private readonly LoggerInterface $logger,
        #[AutowireIterator(PluginStyleResolverInterface::TAG)]
        private readonly iterable $resolvers,
    ) {
    }

    /**
     * Fill `plugin` / `plugin_error` on every section that has a resolver, and
     * return the filtered `dependsOn` tags the resolvers reported.
     *
     * @param array<string, mixed> $pageData a page payload (`page.sections` …)
     * @return array{0: array<string, mixed>, 1: list<string>} the page data and the depends_on tags
     */
    public function apply(array $pageData, PluginRenderContext $context): array
    {
        $page = $pageData['page'] ?? null;
        if (!is_array($page) || !is_array($page['sections'] ?? null)) {
            return [$pageData, []];
        }

        $enabledStyles = $this->enabledStyles();
        $dependsOn = [];
        // Narrowed by the guard above: sections is an array of mixed section rows.
        $page['sections'] = $this->walk($page['sections'], $context, $enabledStyles, $dependsOn);
        $pageData['page'] = $page;

        return [$pageData, $this->filterDependsOn($dependsOn)];
    }

    /**
     * @param array<mixed> $sections
     * @param array<string, true> $enabledStyles
     * @param list<string> $dependsOn
     * @return list<array<string, mixed>>
     */
    private function walk(array $sections, PluginRenderContext $context, array $enabledStyles, array &$dependsOn): array
    {
        $out = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $typed = $this->stringKeyed($section);
            $style = is_string($typed['style_name'] ?? null) ? $typed['style_name'] : '';
            $resolver = ($style !== '' && isset($enabledStyles[$style]))
                ? ($this->resolversByStyle()[$style] ?? null)
                : null;
            if ($resolver !== null) {
                try {
                    $resolved = $resolver->resolve($typed, $context);
                    $typed['plugin'] = $resolved->data;
                    unset($typed['plugin_error']);
                    foreach ($resolved->dependsOn as $tag) {
                        if ($tag !== '') {
                            $dependsOn[] = $tag;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('A plugin style resolver failed; the section shows plugin_error and the rest of the page is unchanged.', [
                        'style' => $style,
                        'page_id' => $context->pageId,
                        'section_id' => $typed['id'] ?? null,
                        'resolver' => $resolver::class,
                        'exception' => $e,
                    ]);
                    $typed['plugin_error'] = 'resolver_failed';
                    unset($typed['plugin']);
                }
            }
            $childrenRaw = $typed['children'] ?? null;
            if (is_array($childrenRaw) && $childrenRaw !== []) {
                $typed['children'] = $this->walk($childrenRaw, $context, $enabledStyles, $dependsOn);
            }
            $out[] = $typed;
        }

        return $out;
    }

    /**
     * @param array<mixed> $row
     * @return array<string, mixed>
     */
    private function stringKeyed(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, PluginStyleResolverInterface>
     */
    private function resolversByStyle(): array
    {
        if ($this->byStyle !== null) {
            return $this->byStyle;
        }

        $byStyle = [];
        foreach ($this->resolvers as $resolver) {
            $name = $resolver->styleName();
            if ($name !== '' && !isset($byStyle[$name])) {
                $byStyle[$name] = $resolver;
            }
        }
        $this->byStyle = $byStyle;

        return $byStyle;
    }

    /**
     * Style names declared by currently enabled plugins (re-read each apply so
     * a test that installs a plugin mid-process is seen).
     *
     * @return array<string, true>
     */
    private function enabledStyles(): array
    {
        $styles = [];
        foreach ($this->registry->getEnabled() as $plugin) {
            foreach ((new PluginManifest($plugin->getManifestJson()))->getStyles() as $style) {
                $name = is_string($style['name'] ?? null) ? $style['name'] : '';
                if ($name !== '') {
                    $styles[$name] = true;
                }
            }
        }

        return $styles;
    }

    /**
     * Keep only well-formed tags of registered resource types and existing tables.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    public function filterDependsOn(array $tags): array
    {
        $kept = [];
        foreach ($tags as $tag) {
            if ($this->keepTag($tag)) {
                $kept[$tag] = $tag;
            } else {
                $this->logger->warning('Dropped a plugin depends_on tag that is not a registered resource or an existing data table.', [
                    'tag' => $tag,
                ]);
            }
        }
        $list = array_values($kept);
        sort($list);

        return $list;
    }

    private function keepTag(string $tag): bool
    {
        if (preg_match('/^res:([a-z][a-z0-9_]{1,63}):(\d+)$/', $tag, $m) === 1) {
            return $this->registeredTypes->isRegistered($m[1]) && (int) $m[2] > 0;
        }
        if (preg_match('/^table:(\d+)$/', $tag, $m) === 1) {
            $id = (int) $m[1];

            return $id > 0 && $this->dataTables->find($id) !== null;
        }

        return false;
    }

    /**
     * Build a well-formed `res:` tag when the type is registered, else null.
     */
    public function resourceTag(string $type, int $id): ?string
    {
        if (!$this->registeredTypes->isRegistered($type) || $id <= 0) {
            return null;
        }
        try {
            return SyncTag::resource($type, $id);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
