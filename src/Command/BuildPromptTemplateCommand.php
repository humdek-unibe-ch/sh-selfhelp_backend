<?php

namespace App\Command;

use App\Service\CMS\Admin\StyleSchemaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Build the AI section-generation prompt template.
 *
 * Reads the hand-maintained base markdown plus the live style/field catalog
 * (via StyleSchemaService) and writes the combined template to the frontend
 * docs folder. The admin UI's `Copy AI prompt` button serves the resulting
 * file verbatim via `GET /cms-api/v1/admin/ai/section-prompt-template`.
 *
 * Usage:
 *   php bin/console app:prompt-template:build
 *   php bin/console app:prompt-template:build --output=/custom/path.md
 */
#[AsCommand(
    name: 'app:prompt-template:build',
    description: 'Regenerate the AI section-generation prompt template (base + style/field catalog appendix).'
)]
class BuildPromptTemplateCommand extends Command
{
    public function __construct(
        private readonly StyleSchemaService $styleSchemaService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'base',
                'b',
                InputOption::VALUE_REQUIRED,
                'Path to the hand-maintained base markdown file.',
                null
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Path to the generated markdown file.',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Build AI Section-Generation Prompt Template');

        $basePath = $input->getOption('base') ?: $this->resolveBasePath();
        $outputPath = $input->getOption('output') ?: $this->resolveOutputPath();

        if (!is_file($basePath)) {
            $io->error(sprintf('Base template not found at %s', $basePath));
            return Command::FAILURE;
        }

        $io->info(sprintf('Base:   %s', $basePath));
        $io->info(sprintf('Output: %s', $outputPath));

        $base = (string) file_get_contents($basePath);
        $catalog = $this->renderCatalog($this->styleSchemaService->getSchema());

        $finalText = $this->injectCatalog($base, $catalog);

        $outDir = dirname($outputPath);
        if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            $io->error(sprintf('Failed to create output directory %s', $outDir));
            return Command::FAILURE;
        }

        if (file_put_contents($outputPath, $finalText) === false) {
            $io->error(sprintf('Failed to write %s', $outputPath));
            return Command::FAILURE;
        }

        $io->success(sprintf('Wrote %s (%d bytes)', $outputPath, strlen($finalText)));
        return Command::SUCCESS;
    }

    /**
     * Resolve the default base markdown path (sibling `sh-selfhelp_frontend` repo first, fallback to repo-local copy).
     */
    private function resolveBasePath(): string
    {
        $siblingFrontend = $this->projectDir . '/../sh-selfhelp_frontend/docs/AI Prompts/prompt_template_base.md';
        if (is_file($siblingFrontend)) {
            return $siblingFrontend;
        }
        return $this->projectDir . '/docs/ai/prompt_template_base.md';
    }

    /**
     * Resolve the default output path.
     */
    private function resolveOutputPath(): string
    {
        $siblingFrontend = $this->projectDir . '/../sh-selfhelp_frontend/docs/AI Prompts/ai_section_generation_prompt.md';
        $siblingDir = dirname($siblingFrontend);
        if (is_dir($siblingDir)) {
            return $siblingFrontend;
        }
        return $this->projectDir . '/docs/ai/ai_section_generation_prompt.md';
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
     * Replace the `<!-- CATALOG:BEGIN --> ... <!-- CATALOG:END -->` block in the base
     * markdown with the rendered catalog. If the markers are missing we append.
     */
    private function injectCatalog(string $base, string $catalog): string
    {
        $begin = '<!-- CATALOG:BEGIN -->';
        $end = '<!-- CATALOG:END -->';

        $beginPos = strpos($base, $begin);
        $endPos = strpos($base, $end);
        if ($beginPos === false || $endPos === false || $endPos < $beginPos) {
            return rtrim($base) . "\n\n## Style & field catalog (auto-generated)\n\n" . $catalog;
        }

        $prefix = substr($base, 0, $beginPos + strlen($begin));
        $suffix = substr($base, $endPos);
        return $prefix . "\n" . $catalog . "\n" . $suffix;
    }
}
