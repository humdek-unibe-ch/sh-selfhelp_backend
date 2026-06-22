<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Repository\LanguageRepository;
use App\Service\CMS\CmsPreferenceService;
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
        private readonly LanguageRepository $languageRepository,
        private readonly CmsPreferenceService $cmsPreferenceService,
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
     * @param list<string>|null $only Optional allow-list of style names. When
     *        provided (and non-empty) the catalog is restricted to those
     *        styles (compact / task-filtered prompt). `null` = full catalog,
     *        which keeps the legacy no-arg behaviour the offline dumper relies
     *        on.
     *
     * @throws \RuntimeException if the base markdown is missing.
     */
    public function render(?array $only = null): string
    {
        $basePath = $this->resolveBasePath();
        if (!is_file($basePath)) {
            throw new \RuntimeException(sprintf(
                'Prompt template base markdown not found at %s. Make sure the file is committed in the backend repo.',
                $basePath
            ));
        }

        $base = (string) file_get_contents($basePath);
        $catalog = $this->renderCatalog($this->filterSchema($this->styleSchemaService->getSchema(), $only));

        return $this->injectCatalog($base, $catalog);
    }

    /**
     * Restrict a full schema map to an optional allow-list of style names.
     * Shared by {@see render()} (markdown) and the controller's JSON format so
     * the `?styles=` filter behaves identically for both representations.
     *
     * @param array<string, array<string, mixed>> $schema
     * @param list<string>|null $only
     * @return array<string, array<string, mixed>>
     */
    public function filterSchema(array $schema, ?array $only): array
    {
        if ($only === null || $only === []) {
            return $schema;
        }
        return array_intersect_key($schema, array_flip($only));
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
        foreach ($this->renderLanguageContract() as $languageLine) {
            $lines[] = $languageLine;
        }
        $lines[] = 'Field scope legend (scope implies the locale + platform — never re-derive it from the name):';
        $lines[] = '- content — translatable copy; one entry per real locale (e.g. en-GB, de-CH), never "all".';
        $lines[] = '- common — cross-platform property; locale "all" only; renders on web AND native.';
        $lines[] = '- web — web-only property (Mantine/browser); locale "all"; the native renderer ignores it.';
        $lines[] = '- mobile — native-only property (HeroUI Native); locale "all"; the web renderer ignores it.';
        $lines[] = 'Reserved names shared_width / shared_height / shared_icon are common (cross-platform) despite the prefix.';
        $lines[] = 'Each field reads: `- name (scope, type, default=…[, options: …][, hidden][, disabled])`.';
        $lines[] = 'Style headers read: `### name (group, renderTarget=both|web|mobile)[ — can_have_children]`.';
        $lines[] = '';

        foreach ($schema as $styleName => $meta) {
            $group = $this->scalarToString($meta['group'] ?? 'unknown');
            $canHave = !empty($meta['can_have_children']);
            $rawDescription = $meta['description'] ?? null;
            $description = is_scalar($rawDescription) ? trim((string) $rawDescription) : '';
            $renderTarget = $this->scalarToString($meta['renderTarget'] ?? 'both');
            if ($renderTarget === '') {
                $renderTarget = 'both';
            }

            $header = sprintf(
                '### %s (%s, renderTarget=%s)%s',
                $styleName,
                $group,
                $renderTarget,
                $canHave ? ' — can_have_children' : ''
            );
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
                    // Scope is the single source of truth (backend-derived); it
                    // already encodes translatability + platform + required
                    // locale, so we emit it verbatim instead of re-deriving.
                    $scope = $this->scalarToString($fieldMeta['scope'] ?? '');
                    if ($scope === '') {
                        $scope = $this->scalarToInt($fieldMeta['display'] ?? 0) === 1 ? 'content' : 'common';
                    }
                    $default = $fieldMeta['default_value'] ?? null;
                    $defaultRepr = $default === null ? 'null' : '"' . addslashes($this->scalarToString($default)) . '"';
                    $hidden = $this->scalarToInt($fieldMeta['hidden'] ?? 0) > 0;
                    $disabled = !empty($fieldMeta['disabled']);

                    $tags = [$scope, $type, 'default=' . $defaultRepr];
                    if ($hidden) {
                        $tags[] = 'hidden';
                    }
                    if ($disabled) {
                        $tags[] = 'disabled';
                    }

                    $line = sprintf(
                        '- %s (%s)',
                        $this->scalarToString($fieldName),
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

                    // Authoring hints — only when present, trimmed to one line so
                    // the catalog stays token-compact.
                    $help = $fieldMeta['help'] ?? null;
                    $helpStr = is_scalar($help) ? $this->oneLine((string) $help, 140) : '';
                    if ($helpStr !== '') {
                        $line .= ' — help: ' . $helpStr;
                    }
                    $placeholder = $fieldMeta['placeholder'] ?? null;
                    $placeholderStr = is_scalar($placeholder) ? $this->oneLine((string) $placeholder, 80) : '';
                    if ($placeholderStr !== '') {
                        $line .= ' — placeholder: "' . $placeholderStr . '"';
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
     * Build the dynamic content-language contract block injected at the top of
     * the catalog. The active content languages and the CMS default language
     * come from the live database, so the prompt teaches the install's real
     * locales instead of a hardcoded list — this is what makes generated
     * sections render for every audience (en-GB + de-CH, default fallback).
     *
     * @return list<string>
     */
    private function renderLanguageContract(): array
    {
        $languages = $this->languageRepository->findAllExceptInternal();

        $defaultLanguageId = null;
        try {
            $defaultLanguageId = $this->cmsPreferenceService->getDefaultLanguageId();
        } catch (\Throwable) {
            // No CMS preference resolvable — degrade to "first locale is default".
        }

        $locales = [];
        $defaultLocale = '';
        foreach ($languages as $language) {
            $locale = (string) $language->getLocale();
            if ($locale === '') {
                continue;
            }
            $locales[] = $locale;
            if ($defaultLanguageId !== null && $language->getId() === $defaultLanguageId) {
                $defaultLocale = $locale;
            }
        }

        // Degenerate install with only the internal `all` language: skip the
        // block so the prompt stays valid.
        if ($locales === []) {
            return [];
        }

        if ($defaultLocale === '') {
            $defaultLocale = $locales[0];
        }

        $lines = [];
        $lines[] = 'Content languages — author EVERY content-scope field in EACH of these locales. A content field';
        $lines[] = 'missing the default language renders EMPTY for the default audience (render-time fallback only';
        $lines[] = 'fills NON-default locales from the default, never the default itself):';
        foreach ($locales as $locale) {
            $lines[] = '- ' . $locale . ($locale === $defaultLocale ? ' (default)' : '');
        }
        $lines[] = 'Property fields (scope common/web/mobile) are language-independent: always use the locale "all".';
        $lines[] = '';

        return $lines;
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
     * Collapse whitespace/newlines and truncate to keep an authoring hint on a
     * single, token-cheap catalog line.
     */
    private function oneLine(string $value, int $max): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', $value));
        if ($clean === '') {
            return '';
        }
        if (mb_strlen($clean) > $max) {
            $clean = rtrim(mb_substr($clean, 0, $max - 1)) . '…';
        }
        return $clean;
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
