<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Registry\Unified;

/**
 * The unified `registry.json` index — the SINGLE catalogue consumed by BOTH
 * installers: the Manager (core/frontend/scheduler/worker Docker releases) and
 * the CMS/backend (plugin releases + advisory preflight).
 *
 * Mirrors the shared `RegistryIndex` TypeScript interface
 * (`@selfhelp/shared` `distribution.ts`) and the Manager Zod schema. Every
 * top-level component array is a list of {@see RegistryReleaseRef} — one ref
 * per published version — each pointing at a standalone signed release
 * document via `releaseUrl`.
 *
 * The backend CONSUMES `core[]` (for the signed advisory-only update preflight),
 * `frontend[]` (the version list for the CMS frontend-only update picker — refs
 * only; the SIGNED frontend Docker release docs remain the Manager's concern,
 * which re-resolves and verifies them before pulling), `mobilePreview[]` (the
 * version list for the CMS mobile-preview update picker — refs only, same trust
 * boundary as frontend), and `plugins[]` (the Available list + install). The
 * `scheduler[]`/`worker[]` Docker release refs
 * are the Manager's concern and are intentionally NOT parsed here — the backend
 * never pulls those images, so modelling them would be unused surface. The wire
 * shape still carries them (see the registry-index JSON schema); they are
 * simply ignored.
 */
final class RegistryIndex
{
    /**
     * @param list<RegistryReleaseRef> $core
     * @param list<RegistryReleaseRef> $frontend
     * @param list<RegistryReleaseRef> $plugins
     * @param list<RegistryReleaseRef> $mobilePreview
     */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $requiresManager,
        public readonly string $baseUrl,
        public readonly array $core,
        public readonly array $plugins,
        public readonly array $frontend = [],
        public readonly array $mobilePreview = [],
        public readonly ?string $trustedKeysUrl = null,
        public readonly ?string $advisoriesUrl = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data, string $context = 'registry.json'): self
    {
        $schemaVersion = self::requireString($data, 'schemaVersion', $context);
        $requiresManager = self::requireString($data, 'requiresManager', $context);
        $baseUrl = self::requireString($data, 'baseUrl', $context);

        $trustedKeysUrl = $data['trustedKeysUrl'] ?? null;
        $advisoriesUrl = $data['advisoriesUrl'] ?? null;

        return new self(
            schemaVersion: $schemaVersion,
            requiresManager: $requiresManager,
            baseUrl: $baseUrl,
            core: self::parseRefs($data, 'core', $context),
            plugins: self::parseRefs($data, 'plugins', $context),
            frontend: self::parseRefs($data, 'frontend', $context),
            mobilePreview: self::parseRefs($data, 'mobilePreview', $context),
            trustedKeysUrl: is_string($trustedKeysUrl) && $trustedKeysUrl !== '' ? $trustedKeysUrl : null,
            advisoriesUrl: is_string($advisoriesUrl) && $advisoriesUrl !== '' ? $advisoriesUrl : null,
        );
    }

    /**
     * Group plugin release refs by plugin id (multi-version per plugin),
     * version-sorted descending (newest first).
     *
     * @return array<string, list<RegistryReleaseRef>>
     */
    public function pluginRefsById(): array
    {
        $byId = [];
        foreach ($this->plugins as $ref) {
            $byId[$ref->id][] = $ref;
        }
        foreach ($byId as $id => $refs) {
            usort(
                $refs,
                static fn (RegistryReleaseRef $a, RegistryReleaseRef $b): int => \App\Plugin\Versioning\SemverHelper::compare($b->version, $a->version),
            );
            $byId[$id] = $refs;
        }
        return $byId;
    }

    /**
     * Core release refs sorted newest-first. The Manager follows these to the
     * signed Docker {@see CoreRelease} documents; the backend reads the newest
     * one for advisory preflight only.
     *
     * @return list<RegistryReleaseRef>
     */
    public function coreRefsSorted(): array
    {
        $refs = $this->core;
        usort(
            $refs,
            static fn (RegistryReleaseRef $a, RegistryReleaseRef $b): int => \App\Plugin\Versioning\SemverHelper::compare($b->version, $a->version),
        );
        return $refs;
    }

    /**
     * Frontend release refs sorted newest-first. Feeds the CMS frontend-only
     * update version picker; only refs (version/channel/blocked) are read here —
     * the SIGNED frontend Docker release document is the Manager's concern,
     * re-resolved + signature-verified before any image is pulled.
     *
     * @return list<RegistryReleaseRef>
     */
    public function frontendRefsSorted(): array
    {
        $refs = $this->frontend;
        usort(
            $refs,
            static fn (RegistryReleaseRef $a, RegistryReleaseRef $b): int => \App\Plugin\Versioning\SemverHelper::compare($b->version, $a->version),
        );
        return $refs;
    }

    /**
     * Mobile-preview release refs sorted newest-first. Feeds the CMS
     * mobile-preview update version picker; only refs (version/channel/blocked)
     * are read here — the optional `selfhelp-mobile-preview` web image ships
     * independently of the core, so a newer compatible preview can exist for an
     * instance already on the newest core. The SelfHelp Manager remains the
     * trusted verifier that re-resolves compatibility + the per-plugin RN/Expo
     * gate and verifies the signed Docker release before pulling.
     *
     * @return list<RegistryReleaseRef>
     */
    public function mobilePreviewRefsSorted(): array
    {
        $refs = $this->mobilePreview;
        usort(
            $refs,
            static fn (RegistryReleaseRef $a, RegistryReleaseRef $b): int => \App\Plugin\Versioning\SemverHelper::compare($b->version, $a->version),
        );
        return $refs;
    }

    /**
     * Resolve a possibly-relative `releaseUrl` against the index `baseUrl`.
     * Absolute (scheme-qualified) URLs are returned verbatim.
     */
    public function resolveUrl(string $url): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            return $url;
        }
        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * @param array<string,mixed> $data
     * @return list<RegistryReleaseRef>
     */
    private static function parseRefs(array $data, string $key, string $context): array
    {
        $raw = $data[$key] ?? [];
        if (!is_array($raw)) {
            throw new MalformedRegistryException(sprintf('%s: "%s" must be an array of release refs.', $context, $key));
        }
        $out = [];
        foreach (array_values($raw) as $i => $entry) {
            if (!is_array($entry)) {
                throw new MalformedRegistryException(sprintf('%s: "%s[%d]" must be an object.', $context, $key, $i));
            }
            $out[] = RegistryReleaseRef::fromArray($entry, sprintf('%s %s[%d]', $context, $key, $i));
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function requireString(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new MalformedRegistryException(sprintf('%s: "%s" must be a non-empty string.', $context, $key));
        }
        return $value;
    }
}
