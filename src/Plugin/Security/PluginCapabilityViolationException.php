<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Security;

/**
 * Raised when the installer or a runtime guard detects a plugin
 * attempting to use a capability it has not been granted (deny-by-default
 * matrix in `CapabilityCatalog`).
 */
final class PluginCapabilityViolationException extends \RuntimeException
{
}
