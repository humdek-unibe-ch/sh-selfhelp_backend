<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\FreshTestInstallSchedulerGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FreshTestInstallSchedulerGuardTest extends TestCase
{
    public function testPauseStopsAndResumeRestartsWhenSchedulerIsRunning(): void
    {
        $commands = [];
        $guard = new FreshTestInstallSchedulerGuard(
            function (array $command, string $cwd) use (&$commands): array {
                $commands[] = [$command, $cwd];

                return match ($command) {
                    ['docker', 'compose', 'ps', '--services', '--status', 'running'] => ['exitCode' => 0, 'stdout' => "redis\nscheduler\nmercure", 'stderr' => ''],
                    ['docker', 'compose', 'stop', 'scheduler'] => ['exitCode' => 0, 'stdout' => 'stopped', 'stderr' => ''],
                    ['docker', 'compose', 'start', 'scheduler'] => ['exitCode' => 0, 'stdout' => 'started', 'stderr' => ''],
                    default => self::fail('Unexpected command: ' . implode(' ', $command)),
                };
            }
        );

        $paused = $guard->pauseIfRunning('D:/repo');
        $guard->resumeIfPaused('D:/repo', $paused);

        self::assertTrue($paused);
        self::assertSame(
            [
                [['docker', 'compose', 'ps', '--services', '--status', 'running'], 'D:/repo'],
                [['docker', 'compose', 'stop', 'scheduler'], 'D:/repo'],
                [['docker', 'compose', 'start', 'scheduler'], 'D:/repo'],
            ],
            $commands
        );
    }

    public function testPauseDoesNothingWhenSchedulerIsNotRunning(): void
    {
        $commands = [];
        $guard = new FreshTestInstallSchedulerGuard(
            function (array $command, string $cwd) use (&$commands): array {
                $commands[] = [$command, $cwd];

                return match ($command) {
                    ['docker', 'compose', 'ps', '--services', '--status', 'running'] => ['exitCode' => 0, 'stdout' => "redis\nmercure", 'stderr' => ''],
                    default => self::fail('Unexpected command: ' . implode(' ', $command)),
                };
            }
        );

        $paused = $guard->pauseIfRunning('D:/repo');
        $guard->resumeIfPaused('D:/repo', $paused);

        self::assertFalse($paused);
        self::assertCount(1, $commands);
    }

    public function testPauseThrowsWhenSchedulerStopFails(): void
    {
        $guard = new FreshTestInstallSchedulerGuard(
            fn (array $command, string $cwd): array => match ($command) {
                ['docker', 'compose', 'ps', '--services', '--status', 'running'] => ['exitCode' => 0, 'stdout' => 'scheduler', 'stderr' => ''],
                ['docker', 'compose', 'stop', 'scheduler'] => ['exitCode' => 1, 'stdout' => '', 'stderr' => 'permission denied'],
                default => ['exitCode' => 0, 'stdout' => '', 'stderr' => ''],
            }
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to stop local Docker scheduler');

        $guard->pauseIfRunning('D:/repo');
    }
}
