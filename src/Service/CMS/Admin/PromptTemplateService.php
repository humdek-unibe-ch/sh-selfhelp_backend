<?php

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
            $group = $meta['group'] ?? 'unknown';
            $canHave = !empty($meta['can_have_children']);
            $description = isset($meta['description']) && $meta['description'] !== null
                ? trim((string) $meta['description'])
                : '';

            $header = sprintf('### %s (%s)%s', $styleName, $group, $canHave ? ' — can_have_children' : '');
            $lines[] = $header;

            if ($description !== '') {
                $lines[] = '';
                $lines[] = str_replace(["\r\n", "\n"], ' ', $description);
            }

            if (!empty($meta['fields'])) {
                $lines[] = '';
                $lines[] = 'Fields:';
                foreach ($meta['fields'] as $fieldName => $fieldMeta) {
                    $type = $fieldMeta['type'] ?? '?';
                    $isTranslatable = ((int) ($fieldMeta['display'] ?? 0)) === 1;
                    $display = $isTranslatable ? 'translatable' : 'property';
                    $locale = $isTranslatable ? 'en-GB|de-CH|...' : 'all';
                    $default = $fieldMeta['default_value'];
                    $defaultRepr = $default === null ? 'null' : '"' . addslashes((string) $default) . '"';
                    $hidden = ((int) ($fieldMeta['hidden'] ?? 0)) > 0;
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
                        $fieldName,
                        $type,
                        $defaultRepr,
                        implode(', ', $tags)
                    );

                    // Append enum options for select/segment/slider/color-picker fields.
                    if (!empty($fieldMeta['options']) && is_array($fieldMeta['options'])) {
                        $values = array_values(array_unique(array_map(
                            static fn (array $opt): string => (string) ($opt['value'] ?? ''),
                            $fieldMeta['options']
                        )));
                        $values = array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
                        if (!empty($values)) {
                            $display = array_map(
                                static fn (string $v): string => '"' . $v . '"',
                                $values
                            );
                            $line .= ' — options: ' . implode(' | ', $display);
                        }
                    }

                    $lines[] = $line;
                }
            }

            if (!empty($meta['allowed_children'])) {
                $lines[] = '';
                $lines[] = 'Allowed children: ' . implode(', ', $meta['allowed_children']);
            } elseif ($canHave) {
                $lines[] = '';
                $lines[] = 'Allowed children: (any)';
            }

            if (!empty($meta['allowed_parents'])) {
                $lines[] = 'Allowed parents: ' . implode(', ', $meta['allowed_parents']);
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
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
