<?php

namespace App\Command;

use App\Service\CMS\Admin\PromptTemplateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Offline snapshot dumper for the AI section-generation prompt.
 *
 * This command is **NOT** part of the runtime path. The endpoint
 * `GET /cms-api/v1/admin/ai/section-prompt-template` always renders the
 * prompt on demand via {@see PromptTemplateService::render()} from the
 * committed base markdown plus the live style/field catalog — no console
 * command needs to run for the API to work.
 *
 * The only reason to keep this command is offline review: dump the
 * currently-rendered prompt to disk so an editor can diff two snapshots
 * (e.g. before/after a schema change) without hitting the API. The output
 * file is gitignored on purpose; do **not** commit it and do **not** rely
 * on it as a fallback for the runtime endpoint — it is stale the moment
 * any style or field changes.
 *
 * Default output path comes from
 * {@see PromptTemplateService::resolveOutputPath()} (governed by the
 * `ai_prompt_template_dir` container parameter; default `docs/ai`).
 *
 * Usage:
 *   php bin/console app:prompt-template:build
 *   php bin/console app:prompt-template:build --output=/custom/path.md
 */
#[AsCommand(
    name: 'app:prompt-template:build',
    description: 'Offline diff helper: dump the currently-rendered AI section-generation prompt to disk. NOT used at runtime — the endpoint renders on demand.'
)]
class BuildPromptTemplateCommand extends Command
{
    public function __construct(
        private readonly PromptTemplateService $promptTemplateService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
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

        $outputPath = $input->getOption('output')
            ?: $this->promptTemplateService->resolveOutputPath();

        try {
            $finalText = $this->promptTemplateService->render();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->info(sprintf('Base:   %s', $this->promptTemplateService->resolveBasePath()));
        $io->info(sprintf('Output: %s', $outputPath));

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
}
