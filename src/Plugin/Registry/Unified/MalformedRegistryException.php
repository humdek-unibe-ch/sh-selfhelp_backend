<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Registry\Unified;

/**
 * Thrown when a unified `registry.json` index or a release document
 * (`PluginRelease` / `CoreRelease`) is structurally invalid, references an
 * unexpected `kind`, or fails signature verification.
 *
 * Carries a clear, operator-facing message: the backend never silently
 * "fails safe" by skipping a malformed plugin release during an explicit
 * install/update request — it surfaces exactly what is wrong with the
 * registry document.
 */
final class MalformedRegistryException extends \RuntimeException
{
}
