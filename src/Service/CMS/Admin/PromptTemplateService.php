<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Build the AI section-generation prompt template on demand.
 *
 * The template = a hand-maintained base markdown (`docs/ai/prompt_template_base.md`)
 * + an auto-generated catalog of every style + field (rendered from
 * {@see StyleSchemaService::getSchema()}). The result is plain markdown that
 * the admin "Copy AI prompt" button serves verbatim via
 * `GET /cms-api/v1/admin/ai/section-prompt-template`.
 *
 * No disk artefacts are required: the controller calls {@see render()} per
 * request. The catalog block reflects the live style/field schema, so the
 * prompt is always in sync with the database.
 *
 * The legacy `bin/console app:prompt-template:build` command still uses this
 * service when an editor wants to dump the rendered template to disk (e.g.
 * to commit a snapshot for offline review).
 */
class PromptTemplateService
{
    public function __construct(
        private readonly StyleSchemaService $styleSchemaService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
    }

    /**
     * Resolve the absolute path to the hand-maintained base markdown file.
     */
    public function resolveBasePath(): string
    {
        return $this->projectDir . '/docs/ai/prompt_template_base.md';
    }

    /**
     * Render the full prompt markdown (base + catalog) as a string.
     *
     * @throws \RuntimeException if the base markdown is missing.
     */
    public function render(): string
    {
        $basePath = $this->resolveBasePath();
        if (!is_file($basePath)) {
            throw new \RuntimeException(sprintf(
                'Prompt template base markdown not found at %s. Make sure the file is committed in the backend repo.',
                $basePath
            ));
        }

        $base = (string) file_get_contents($basePath);
        $catalog = $this->renderCatalog($this->styleSchemaService->getSchema());

        return $this->injectCatalog($base, $catalog);
    }

    /**
     * Render the catalog appendix as markdown — one block per style.
     *
     * @param array<string, array<string, mixed>> $schema
     */
    private function renderCatalog(array $schema): string
    {
        $lines = [];
        $lines[] = '> Catalog regenerated: ' . (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $lines[] = '';

        foreach ($schema as $styleName => $meta) {
            $group = $this->scalarToString($meta['group'] ?? 'unknown');
            $canHave = !empty($meta['can_have_children']);
            $rawDescription = $meta['description'] ?? null;
            $description = is_scalar($rawDescription) ? trim((string) $rawDescription) : '';

            $header = sprintf('### %s (%s)%s', $styleName, $group, $canHave ? ' — can_have_children' : '');
            $lines[] = $header;

            if ($description !== '') {
                $lines[] = '';
                $lines[] = str_replace(["\r\n", "\n"], ' ', $description);
            }

            $fields = $meta['fields'] ?? null;
            if (is_array($fields) && $fields !== []) {
                $lines[] = '';
                $lines[] = 'Fields:';
                foreach ($fields as $fieldName => $fieldMeta) {
                    if (!is_array($fieldMeta)) {
                        continue;
                    }
                    $type = $this->scalarToString($fieldMeta['type'] ?? '?');
                    $isTranslatable = $this->scalarToInt($fieldMeta['display'] ?? 0) === 1;
                    $display = $isTranslatable ? 'translatable' : 'property';
                    $locale = $isTranslatable ? 'en-GB|de-CH|...' : 'all';
                    $default = $fieldMeta['default_value'] ?? null;
                    $defaultRepr = $default === null ? 'null' : '"' . addslashes($this->scalarToString($default)) . '"';
                    $hidden = $this->scalarToInt($fieldMeta['hidden'] ?? 0) > 0;
                    $disabled = !empty($fieldMeta['disabled']);

                    $tags = [];
                    $tags[] = $display . ', locale=' . $locale;
                    if ($hidden) {
                        $tags[] = 'hidden';
                    }
                    if ($disabled) {
                        $tags[] = 'disabled';
                    }

                    $line = sprintf(
                        '- %s (%s, default=%s, %s)',
                        $this->scalarToString($fieldName),
                        $type,
                        $defaultRepr,
                        implode(', ', $tags)
                    );

                    // Append enum options for select/segment/slider/color-picker fields.
                    $options = $fieldMeta['options'] ?? null;
                    if (is_array($options) && $options !== []) {
                        $values = [];
                        foreach ($options as $opt) {
                            $optValue = is_array($opt) ? ($opt['value'] ?? '') : '';
                            $optValue = $this->scalarToString($optValue);
                            if ($optValue !== '') {
                                $values[] = $optValue;
                            }
                        }
                        $values = array_values(array_unique($values));
                        if (!empty($values)) {
                            $rendered = array_map(
                                static fn (string $v): string => '"' . $v . '"',
                                $values
                            );
                            $line .= ' — options: ' . implode(' | ', $rendered);
                        }
                    }

                    $lines[] = $line;
                }
            }

            $allowedChildren = $meta['allowed_children'] ?? null;
            if (is_array($allowedChildren) && $allowedChildren !== []) {
                $lines[] = '';
                $lines[] = 'Allowed children: ' . implode(', ', array_map(fn ($c): string => $this->scalarToString($c), $allowedChildren));
            } elseif ($canHave) {
                $lines[] = '';
                $lines[] = 'Allowed children: (any)';
            }

            $allowedParents = $meta['allowed_parents'] ?? null;
            if (is_array($allowedParents) && $allowedParents !== []) {
                $lines[] = 'Allowed parents: ' . implode(', ', array_map(fn ($p): string => $this->scalarToString($p), $allowedParents));
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Coerce a schema value to a string for catalog rendering.
     */
    private function scalarToString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Coerce a schema value to an int for catalog rendering.
     */
    private function scalarToInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Replace the `<!-- CATALOG:BEGIN --> ... <!-- CATALOG:END -->` block in
     * the base markdown with the rendered catalog. Falls back to appending if
     * the markers are missing.
     *
     * Uses {@see strrpos()} (last-match) on purpose: the explanatory prose at
     * the top of the base markdown legitimately mentions the marker tokens,
     * so the actual injection point must be the trailing pair near the end of
     * the document, never the first occurrence in a blockquote.
     */
    private function injectCatalog(string $base, string $catalog): string
    {
        $begin = '<!-- CATALOG:BEGIN -->';
        $end = '<!-- CATALOG:END -->';

        $endPos = strrpos($base, $end);
        if ($endPos === false) {
            return rtrim($base) . "\n\n## Style & field catalog (auto-generated)\n\n" . $catalog;
        }

        $beginPos = strrpos(substr($base, 0, $endPos), $begin);
        if ($beginPos === false) {
            return rtrim($base) . "\n\n## Style & field catalog (auto-generated)\n\n" . $catalog;
        }

        $prefix = substr($base, 0, $beginPos + strlen($begin));
        $suffix = substr($base, $endPos);
        return $prefix . "\n" . $catalog . "\n" . $suffix;
    }
}
