<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\PackageManager;

final class PackageManagerResult
{
    public function __construct(
        public readonly string $command,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $success,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'command' => $this->command,
            'exitCode' => $this->exitCode,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'success' => $this->success,
        ];
    }
}
