<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Security;

/**
 * Raised by `PluginMigrationGuard` when a plugin migration tries to
 * destroy protected core data.
 */
final class PluginMigrationGuardException extends \RuntimeException
{
}
