<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Security;

/**
 * Raised when a plugin's checksum or signature fails verification in
 * strict mode.
 */
final class PluginSignatureException extends \RuntimeException
{
}
