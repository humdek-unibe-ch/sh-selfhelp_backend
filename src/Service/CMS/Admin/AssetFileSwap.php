<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

/**
 * An asset's file put in place but not yet final, made by
 * {@see AssetStorageService::swapIn()}. The database change that goes with it
 * decides: {@see keep()} once it is committed, {@see undo()} when it is not, so
 * the file and the row never disagree.
 */
final class AssetFileSwap
{
    /**
     * @param string $target the asset's file (`<storage dir>/<uuid>`), now holding the new bytes
     * @param string|null $backup the previous bytes, or null when the asset had no file yet
     */
    public function __construct(
        private readonly string $target,
        private readonly ?string $backup,
    ) {
    }

    /**
     * The change is final: the previous bytes are not needed any more.
     */
    public function keep(): void
    {
        if ($this->backup !== null && is_file($this->backup)) {
            @unlink($this->backup);
        }
    }

    /**
     * The change is dropped: the previous bytes come back, or, for an asset
     * that had no file before, the new file goes.
     */
    public function undo(): void
    {
        if ($this->backup === null) {
            if (is_file($this->target)) {
                @unlink($this->target);
            }

            return;
        }

        if (is_file($this->backup)) {
            @rename($this->backup, $this->target);
        }
    }
}
