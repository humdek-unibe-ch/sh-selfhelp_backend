<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Messenger;

use App\Plugin\Manifest\ResolvedSource;

final class UpdatePluginMessage
{
    /**
     * @param array<string,mixed> $manifestArray
     */
    public function __construct(
        public readonly int $operationId,
        public readonly array $manifestArray,
        public readonly ResolvedSource $resolvedSource,
    ) {
    }
}
