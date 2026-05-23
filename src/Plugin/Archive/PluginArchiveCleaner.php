<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Archive;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Purges orphan staging dirs older than `$retentionDays`. Failed
 * `.shplugin` installs intentionally leave their staging dir behind
 * so an operator can inspect it; this cleaner reaps them after a
 * grace period.
 */
final class PluginArchiveCleaner
{
    public function __construct(
        private readonly string $projectDir,
        private readonly int $retentionDays = 7,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return list<string> purged directories
     */
    public function purgeOldStaging(): array
    {
        $base = rtrim($this->projectDir, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'plugins';
        if (!is_dir($base)) {
            return [];
        }
        $cutoff = time() - ($this->retentionDays * 86400);
        $purged = [];
        foreach (glob($base . '/*-*/staging') ?: [] as $stagingDir) {
            $mtime = @filemtime($stagingDir);
            if ($mtime !== false && $mtime < $cutoff) {
                $this->filesystem->remove($stagingDir);
                $purged[] = $stagingDir;
            }
        }
        return $purged;
    }
}
