<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Registry\Unified;

/**
 * One Docker image reference inside a {@see CoreRelease} (the backend, worker
 * or scheduler image). `digest` is the immutable `sha256:<64 hex>` content
 * digest the SelfHelp Manager pins when it pulls + runs the image.
 *
 * The backend only ever reads these for advisory preflight display; the
 * Manager is the trusted party that actually verifies the digest before it
 * runs a container (see {@see CoreRelease}).
 */
final class CoreImageRef
{
    public function __construct(
        public readonly string $image,
        public readonly string $digest,
        public readonly ?string $phpVersion = null,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data, string $context): self
    {
        $image = $data['image'] ?? null;
        if (!is_string($image) || $image === '') {
            throw new MalformedRegistryException(sprintf('%s: "image" must be a non-empty string.', $context));
        }
        $digest = $data['digest'] ?? null;
        if (!is_string($digest) || preg_match('/^sha256:[A-Fa-f0-9]{64}$/', $digest) !== 1) {
            throw new MalformedRegistryException(sprintf('%s: "digest" must be a "sha256:<64 hex>" string.', $context));
        }
        $phpVersion = $data['phpVersion'] ?? null;

        return new self(
            image: $image,
            digest: $digest,
            phpVersion: is_string($phpVersion) && $phpVersion !== '' ? $phpVersion : null,
        );
    }
}
