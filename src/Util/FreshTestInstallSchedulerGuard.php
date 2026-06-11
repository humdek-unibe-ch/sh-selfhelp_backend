<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Util;

use RuntimeException;

/**
 * Pauses the local Docker scheduler during fresh-test-install so Windows can
 * fully remove var/cache without the bind-mounted container racing to recreate
 * dev cache files.
 */
final class FreshTestInstallSchedulerGuard
{
    /**
     * @var \Closure(list<string>, string): array{exitCode: int, stdout: string, stderr: string}
     */
    private \Closure $commandRunner;

    /**
     * @param null|callable(list<string>, string): array{exitCode: int, stdout: string, stderr: string} $commandRunner
     */
    public function __construct(?callable $commandRunner = null)
    {
        $this->commandRunner = $commandRunner instanceof \Closure
            ? $commandRunner
            : \Closure::fromCallable($commandRunner ?? self::runCommand(...));
    }

    public function pauseIfRunning(string $projectDir): bool
    {
        if (!$this->isSchedulerRunning($projectDir)) {
            return false;
        }

        $result = ($this->commandRunner)(['docker', 'compose', 'stop', 'scheduler'], $projectDir);
        if ($result['exitCode'] !== 0) {
            throw new RuntimeException(
                "Failed to stop local Docker scheduler before fresh-test-install.\n"
                . $this->formatCommandFailure($result)
            );
        }

        return true;
    }

    public function resumeIfPaused(string $projectDir, bool $wasPaused): void
    {
        if (!$wasPaused) {
            return;
        }

        $result = ($this->commandRunner)(['docker', 'compose', 'start', 'scheduler'], $projectDir);
        if ($result['exitCode'] !== 0) {
            throw new RuntimeException(
                "Failed to restart local Docker scheduler after fresh-test-install.\n"
                . $this->formatCommandFailure($result)
            );
        }
    }

    private function isSchedulerRunning(string $projectDir): bool
    {
        $result = ($this->commandRunner)(['docker', 'compose', 'ps', '--services', '--status', 'running'], $projectDir);
        if ($result['exitCode'] !== 0) {
            return false;
        }

        $runningServices = preg_split('/\R+/', trim($result['stdout'])) ?: [];

        return in_array('scheduler', $runningServices, true);
    }

    /**
     * @param array{exitCode: int, stdout: string, stderr: string} $result
     */
    private function formatCommandFailure(array $result): string
    {
        $details = [];
        if ($result['stdout'] !== '') {
            $details[] = "stdout:\n" . $result['stdout'];
        }
        if ($result['stderr'] !== '') {
            $details[] = "stderr:\n" . $result['stderr'];
        }

        $message = sprintf('Command exited with code %d.', $result['exitCode']);

        return $details === [] ? $message : $message . "\n" . implode("\n", $details);
    }

    /**
     * @param list<string> $command
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private static function runCommand(array $command, string $cwd): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start process: ' . implode(' ', $command));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => is_string($stdout) ? trim($stdout) : '',
            'stderr' => is_string($stderr) ? trim($stderr) : '',
        ];
    }
}
