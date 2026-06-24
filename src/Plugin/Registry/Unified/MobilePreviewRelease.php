<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Registry\Unified;

/**
 * A signed, Docker-based **mobile-preview** release document referenced from the
 * unified registry index via `RegistryIndex::mobilePreview[].releaseUrl`.
 *
 * The `selfhelp-mobile-preview` web image is an OPTIONAL, stateless front door
 * (an Expo web export plus a thin `/cms-api` proxy to the private backend). It
 * ships INDEPENDENTLY of the core on the mobile repo's own tags, so an instance
 * already on the newest core can still move to a newer compatible preview. The
 * compatibility contract relevant to the CMS lives in the signed metadata:
 *   - the preview's `backendCompatibility.requiredCoreRange` (this document) must
 *     admit the running core version.
 *
 * Trust boundary (mirrors {@see FrontendRelease}):
 *   - The **SelfHelp Manager** is the FINAL trusted verifier. Before it pulls +
 *     runs the preview image it re-resolves this document, verifies the Ed25519
 *     signature against its trusted keys AND verifies the image `digest` at pull
 *     time, and re-runs the per-plugin RN/Expo twin-axis gate.
 *   - The **CMS/backend** reads this document for ADVISORY preflight only (the
 *     mobile-preview update compatibility verdict). It never pulls images and
 *     never executes Docker, but it still verifies the signature here so a
 *     tampered advisory cannot mislead the operator.
 *
 * Mirrors the shared `MobilePreviewRelease` TypeScript interface
 * (`@selfhelp/shared` `distribution.ts`) and the Manager Zod schema.
 */
final class MobilePreviewRelease
{
    public const KIND = 'selfhelp-mobile-preview-release';

    /**
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $version,
        public readonly string $channel,
        public readonly string $image,
        public readonly string $digest,
        public readonly string $requiredCoreRange,
        public readonly string $mobileRendererVersion,
        public readonly ?string $reactNativeVersion,
        public readonly ?string $expoSdkVersion,
        public readonly SignatureBlock $security,
        public readonly bool $blocked,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data, string $context): self
    {
        $kind = $data['kind'] ?? null;
        if ($kind !== self::KIND) {
            throw new MalformedRegistryException(sprintf(
                '%s: expected kind "%s", got %s.',
                $context,
                self::KIND,
                is_string($kind) ? '"' . $kind . '"' : gettype($kind),
            ));
        }

        $id = self::requireString($data, 'id', $context);
        $version = self::requireString($data, 'version', $context);
        $channel = $data['channel'] ?? null;
        if (!is_string($channel) || !in_array($channel, RegistryReleaseRef::CHANNELS, true)) {
            throw new MalformedRegistryException(sprintf(
                '%s: mobile-preview release "%s@%s" channel must be one of %s.',
                $context,
                $id,
                $version,
                implode('|', RegistryReleaseRef::CHANNELS),
            ));
        }

        $backendCompat = self::requireObject($data, 'backendCompatibility', $context);
        $requiredCoreRange = self::requireString($backendCompat, 'requiredCoreRange', $context . ' backendCompatibility');

        $security = $data['security'] ?? null;
        if (!is_array($security)) {
            throw new MalformedRegistryException(sprintf('%s: mobile-preview release "%s@%s" requires a security block.', $context, $id, $version));
        }

        return new self(
            id: $id,
            version: $version,
            channel: $channel,
            image: self::requireString($data, 'image', $context),
            digest: self::requireString($data, 'digest', $context),
            requiredCoreRange: $requiredCoreRange,
            mobileRendererVersion: self::requireString($data, 'mobileRendererVersion', $context),
            reactNativeVersion: self::optionalString($data, 'reactNativeVersion'),
            expoSdkVersion: self::optionalString($data, 'expoSdkVersion'),
            security: SignatureBlock::fromArray($security, $context . ' security'),
            blocked: (bool) ($data['blocked'] ?? false),
            raw: $data,
        );
    }

    /**
     * @param array<array-key,mixed> $data
     * @return array<string,mixed>
     */
    private static function requireObject(array $data, string $key, string $context): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new MalformedRegistryException(sprintf('%s: "%s" must be an object.', $context, $key));
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /**
     * @param array<array-key,mixed> $data
     */
    private static function requireString(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new MalformedRegistryException(sprintf('%s: "%s" must be a non-empty string.', $context, $key));
        }
        return $value;
    }

    /**
     * @param array<array-key,mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }
}
