<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Service\CMS\Admin\PromptTemplateService;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration coverage for {@see \App\Command\BuildPromptTemplateCommand}
 * (plan Phase 9: command tests). Writes the rendered prompt to a throwaway
 * temp file (never the committed default path) and asserts the command's
 * output contract + that the file matches the on-demand render — proving the
 * offline dumper stays in sync with the runtime endpoint.
 */
final class BuildPromptTemplateCommandTest extends QaKernelTestCase
{
    private const COMMAND = 'app:prompt-template:build';

    private Application $application;
    private string $outputPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = new Application(self::bootedKernel());
        $this->application->setAutoExit(false);
        $this->outputPath = sys_get_temp_dir() . '/qa_prompt_template_' . bin2hex(random_bytes(6)) . '.md';
    }

    protected function tearDown(): void
    {
        if ($this->outputPath !== '' && is_file($this->outputPath)) {
            @unlink($this->outputPath);
        }
        parent::tearDown();
    }

    public function testWritesRenderedPromptToTheRequestedPath(): void
    {
        $tester = new CommandTester($this->application->find(self::COMMAND));
        $tester->execute(['--output' => $this->outputPath], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Wrote', $tester->getDisplay());

        self::assertFileExists($this->outputPath, 'The command must write the output file.');
        $written = (string) file_get_contents($this->outputPath);
        self::assertNotSame('', $written, 'The rendered prompt must not be empty.');

        // The dumped file must equal the on-demand render (the endpoint contract).
        $expected = $this->service(PromptTemplateService::class)->render();
        self::assertSame($expected, $written, 'The dumped prompt must match the live render.');
    }
}
