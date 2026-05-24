<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Archive;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\PluginManifestLoader;
use App\Plugin\Manifest\ResolvedSource;
use App\Plugin\Security\PluginCapabilityValidator;
use App\Plugin\Security\PluginCapabilityViolationException;
use App\Plugin\Security\PluginSignatureException;
use App\Plugin\Security\PluginSignatureVerifier;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Backs the `/admin/plugins/inspect-archive` endpoint.
 *
 * Extracts the upload, runs each stage of the validator pipeline
 * defensively, and converts failures into structured `errors[]`
 * entries with a precise `signature.status` ("verified" | "invalid" |
 * "unsigned" | "unverifiable"). The frontend uses the result to render
 * a preview card *before* the admin clicks Install — they need to see
 * exactly why an install would fail without triggering an operation.
 *
 * Anything that prevents us from even reaching the staging dir
 * (extractor exception, ZIP corruption, etc.) is re-thrown so the
 * generic API exception envelope can produce a 4xx/5xx response with
 * a clear message.
 */
final class PluginArchiveInspectionService
{
    public function __construct(
        private readonly PluginArchiveExtractor $extractor,
        private readonly PluginManifestLoader $manifestLoader,
        private readonly PluginArchiveValidator $validator,
        private readonly PluginCompatibilityValidator $compatibility,
        private readonly PluginCapabilityValidator $capabilities,
        private readonly PluginSignatureVerifier $signatureVerifier,
    ) {
    }

    /**
     * @param array{keyId:string,publicKeyBase64:string}|null $trustedKeyOverride
     *      Optional per-request trusted-key override. When supplied,
     *      this method clones the env-resolved `PluginSignatureVerifier`
     *      with the extra `(keyId, base64PublicKey)` pair merged on top
     *      and runs validation against the derived verifier instead.
     *      The host-wide trusted-keys env is left untouched and the
     *      override only lives for this single inspect call. Env keys
     *      win on duplicate keyIds — see
     *      `PluginSignatureVerifier::withAdditionalTrustedKey()`.
     *
     * @return array{
     *     ok: bool,
     *     signature: array{
     *         status: 'verified'|'invalid'|'unsigned'|'unverifiable',
     *         keyId: string|null,
     *         unknownKey: array{keyId:string,envSnippet:string}|null,
     *     },
     *     errors: list<string>,
     *     warnings: list<string>,
     *     manifest: array<string,mixed>|null,
     *     compatibility: array<string,mixed>|null,
     *     capabilities: list<string>,
     *     resolvedSource: array<string,mixed>|null,
     *     archive: array{
     *         mode: 'connected'|'standalone',
     *         backendIncluded: bool,
     *         backendPackage: string|null,
     *         backendVersion: string|null,
     *         installMode: 'composer-path-repository'|'composer-packagist',
     *     },
     * }
     */
    public function inspect(UploadedFile $upload, ?array $trustedKeyOverride = null): array
    {
        $errors = [];
        $warnings = [];
        $manifestArray = null;
        $capabilitiesList = [];
        $compatibility = null;
        $resolvedDescriptor = null;
        $signatureStatus = 'unverifiable';
        $signatureKeyId = null;
        $unknownKeyId = false;
        $archiveSummary = $this->defaultArchiveSummary();

        // Stage 1 — unzip into staging. The admin needs to know exactly
        // why an upload was rejected (wrong extension, zip-slip, too
        // big, missing files), so we never throw a generic 500 here —
        // we report the message back as `errors[]` so the UI can show
        // a precise preview-time error.
        try {
            $extracted = $this->extractor->extract($upload);
        } catch (PluginArchiveException $e) {
            return $this->buildResult(false, 'unverifiable', null, false, [$e->getMessage()], $warnings, null, null, [], null, $archiveSummary);
        }
        $stagingDir = $extracted['stagingDir'];

        // Stage 2 — load manifest from the archive even if signature
        // verification later fails. The admin still needs to see what
        // the manifest claims.
        try {
            $manifest = $this->manifestLoader->loadFromFile($stagingDir . '/plugin.json');
            $manifestArray = $manifest->toArray();
            $capabilitiesList = array_values($manifest->getCapabilities());
            $archiveSummary = $this->buildArchiveSummary($manifestArray, $stagingDir);
        } catch (\Throwable $e) {
            $errors[] = sprintf('plugin.json invalid: %s', $e->getMessage());
            return $this->buildResult(false, 'invalid', null, false, $errors, $warnings, null, null, [], null, $archiveSummary);
        }

        // Peek at signature.json defensively so the response can still
        // surface the keyId even when the validator pipeline rejects
        // the archive — the trust-helper UI needs that keyId to render
        // the env snippet.
        $signatureKeyId = $this->peekSignatureKeyId($stagingDir);

        // Stage 3 — full validator pipeline (canonical payload re-hash
        // + SHA-256 of every artifact + Ed25519 signature check).
        // When the caller supplied a trust override, build a one-shot
        // validator backed by a per-request verifier; otherwise reuse
        // the container-resolved validator.
        $validator = $this->resolveValidator($trustedKeyOverride);
        $resolved = null;
        try {
            $result = $validator->validate($stagingDir);
            $resolved = $result['resolved'];
            $signatureStatus = 'verified';
        } catch (PluginArchiveException $e) {
            // Tease apart "unsigned (allowed)" vs "signature invalid".
            $previous = $e->getPrevious();
            if ($previous instanceof PluginSignatureException) {
                $signatureStatus = 'invalid';
                if ($this->isUnknownKeyIdFailure($previous->getMessage())) {
                    $unknownKeyId = true;
                }
            } else {
                $signatureStatus = $this->classifySignatureFailure($e->getMessage());
            }
            $errors[] = $e->getMessage();
        }

        // Stage 4 — compatibility + capability cross-checks. Run even
        // when the signature step failed so the admin sees as much
        // detail as possible.
        try {
            $compatibility = $this->compatibility->check($manifest);
            if ($compatibility['severity'] === 'blocking') {
                foreach ($compatibility['reasons'] as $reason) {
                    $errors[] = 'compatibility: ' . $reason;
                }
            }
        } catch (\Throwable $e) {
            $warnings[] = sprintf('compatibility check raised: %s', $e->getMessage());
        }

        try {
            $this->capabilities->validate($manifest, $resolved ?? $this->placeholderResolvedSource($manifest, $stagingDir));
        } catch (PluginCapabilityViolationException $e) {
            $errors[] = 'capability: ' . $e->getMessage();
        }

        $resolvedDescriptor = $resolved !== null ? [
            'kind' => $resolved->kind,
            'sourceName' => $resolved->sourceName,
            'manifestUrl' => $resolved->manifestUrl,
            'keyId' => $resolved->keyId,
            'expectedChecksums' => $resolved->expectedChecksums,
            'archiveStagingDir' => $resolved->archiveStagingDir,
        ] : [
            'kind' => ResolvedSource::KIND_ARCHIVE,
            'sourceName' => null,
            'manifestUrl' => null,
            'keyId' => null,
            'expectedChecksums' => [],
            'archiveStagingDir' => $stagingDir,
        ];

        return $this->buildResult(
            $errors === [],
            $signatureStatus,
            $signatureKeyId,
            $unknownKeyId,
            $errors,
            $warnings,
            $manifestArray,
            $compatibility,
            $capabilitiesList,
            $resolvedDescriptor,
            $archiveSummary,
        );
    }

    /**
     * Resolves the validator instance to use for this inspect call.
     * When the caller supplied a trust override, we build a one-shot
     * validator with a per-request verifier so the env-resolved
     * baseline is left untouched. Otherwise we reuse the
     * container-resolved validator (the common case).
     *
     * @param array{keyId:string,publicKeyBase64:string}|null $trustedKeyOverride
     */
    private function resolveValidator(?array $trustedKeyOverride): PluginArchiveValidator
    {
        if ($trustedKeyOverride === null) {
            return $this->validator;
        }
        $derivedVerifier = $this->signatureVerifier->withAdditionalTrustedKey(
            $trustedKeyOverride['keyId'],
            $trustedKeyOverride['publicKeyBase64'],
        );
        // Reuse the container-resolved validator's allowComposerScripts
        // baseline by cloning it with the per-request verifier. This
        // avoids drifting away from the env-driven baseline that the
        // production-path validator uses.
        return $this->validator->withVerifier($derivedVerifier);
    }

    /**
     * Reads `signature.json` from the staging dir and returns its
     * `keyId` as a best-effort string. Returns `null` if the file is
     * missing, unreadable, or malformed — in those cases the
     * validator's own error path will surface a precise message.
     *
     * The trust-helper UI needs this keyId so the operator sees which
     * publisher key the archive claims to be signed by, even when the
     * validator rejects the upload because that keyId isn't trusted
     * yet.
     */
    private function peekSignatureKeyId(string $stagingDir): ?string
    {
        $path = $stagingDir . '/signature.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $keyId = $data['keyId'] ?? null;
        return is_string($keyId) && $keyId !== '' ? $keyId : null;
    }

    /**
     * Detects the specific signature failure that the trust-helper UI
     * can recover from: the publisher keyId is well-formed and the
     * signature itself is intact, but the host's trusted-keys set
     * either does not contain that keyId or is empty altogether.
     * Pasting the matching public key into the helper panel is the
     * legitimate fix in both cases.
     *
     * Tampering, payload mismatch, malformed signature material, and
     * acceptedKeyIds-policy violations deliberately do NOT trigger
     * the helper — those are not safely recoverable by adding a key.
     */
    private function isUnknownKeyIdFailure(string $message): bool
    {
        return str_contains($message, 'is not in SELFHELP_PLUGIN_TRUSTED_KEYS')
            || str_contains($message, 'No SELFHELP_PLUGIN_TRUSTED_KEYS configured');
    }

    /**
     * Default `archive` summary returned when the upload fails before
     * we can read plugin.json. Conservative defaults: connected mode
     * means the host falls back to Phase-1 behaviour.
     *
     * @return array{mode:'connected'|'standalone',backendIncluded:bool,backendPackage:string|null,backendVersion:string|null,installMode:'composer-path-repository'|'composer-packagist'}
     */
    private function defaultArchiveSummary(): array
    {
        return [
            'mode' => 'connected',
            'backendIncluded' => false,
            'backendPackage' => null,
            'backendVersion' => null,
            'installMode' => 'composer-packagist',
        ];
    }

    /**
     * Builds the `archive` summary surfaced in the inspect-archive
     * response. For standalone archives we additionally read the staged
     * backend/package/composer.json so the admin sees the actual
     * package name + version that the host will pass to composer require.
     *
     * Note: there is intentionally no `internetRequired` field. Both
     * `connected` and `standalone` archives need Composer to reach a
     * package source for third-party PHP dependencies at install time;
     * exposing a boolean here would mislead admins into thinking
     * `standalone` is offline-installable.
     *
     * @param array<string,mixed> $manifestData
     * @return array{mode:'connected'|'standalone',backendIncluded:bool,backendPackage:string|null,backendVersion:string|null,installMode:'composer-path-repository'|'composer-packagist'}
     */
    private function buildArchiveSummary(array $manifestData, string $stagingDir): array
    {
        $archive = $manifestData['archive'] ?? null;
        $mode = 'connected';
        if (is_array($archive) && isset($archive['mode']) && is_string($archive['mode']) && $archive['mode'] === 'standalone') {
            $mode = 'standalone';
        }

        if ($mode === 'connected') {
            return [
                'mode' => 'connected',
                'backendIncluded' => false,
                'backendPackage' => is_string($manifestData['backend']['composer']['package'] ?? null)
                    ? (string) $manifestData['backend']['composer']['package']
                    : null,
                'backendVersion' => is_string($manifestData['backend']['composer']['version'] ?? null)
                    ? (string) $manifestData['backend']['composer']['version']
                    : null,
                'installMode' => 'composer-packagist',
            ];
        }

        // Standalone — prefer the staged composer.json over the
        // manifest. They MUST match (the validator enforces that), but
        // if the admin uploads a tampered archive we want the preview
        // to show what the host will actually try to install.
        $backendComposer = $stagingDir . '/backend/package/composer.json';
        $backendPackage = null;
        $backendVersion = null;
        if (is_file($backendComposer)) {
            $raw = file_get_contents($backendComposer);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    if (isset($data['name']) && is_string($data['name']) && $data['name'] !== '') {
                        $backendPackage = (string) $data['name'];
                    }
                    if (isset($data['version']) && is_string($data['version']) && $data['version'] !== '') {
                        $backendVersion = (string) $data['version'];
                    }
                }
            }
        }
        if ($backendPackage === null && is_string($manifestData['backend']['composer']['package'] ?? null)) {
            $backendPackage = (string) $manifestData['backend']['composer']['package'];
        }
        if ($backendVersion === null && is_string($manifestData['version'] ?? null)) {
            $backendVersion = (string) $manifestData['version'];
        }

        return [
            'mode' => 'standalone',
            'backendIncluded' => true,
            'backendPackage' => $backendPackage,
            'backendVersion' => $backendVersion,
            'installMode' => 'composer-path-repository',
        ];
    }

    /**
     * Heuristic signature-status fallback for archive errors that
     * already wrap a signature failure but were thrown by the validator
     * before we got a typed exception. Keeps the API contract honest.
     */
    private function classifySignatureFailure(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'signature missing') || str_contains($lower, 'signature.json missing')) {
            return 'unsigned';
        }
        if (str_contains($lower, 'signature') || str_contains($lower, 'sha-256') || str_contains($lower, 'sha256')) {
            return 'invalid';
        }
        if (str_contains($lower, 'canonical signed payload')) {
            return 'invalid';
        }
        return 'unverifiable';
    }

    /**
     * Build a minimal ResolvedSource so the capability validator can
     * still run after a signature failure. We mark the source kind as
     * `archive` because that's accurate from the upload's POV.
     */
    private function placeholderResolvedSource(PluginManifest $manifest, string $stagingDir): ResolvedSource
    {
        $data = $manifest->toArray();
        $frontend = is_array($data['frontend'] ?? null) ? $data['frontend'] : [];
        $backend = is_array($data['backend'] ?? null) ? $data['backend'] : [];
        $runtime = is_array($frontend['runtime'] ?? null) ? $frontend['runtime'] : [];
        $composer = is_array($backend['composer'] ?? null) ? $backend['composer'] : [];
        /** @var array<string,mixed> $runtime */
        /** @var array<string,mixed> $composer */
        return new ResolvedSource(
            kind: ResolvedSource::KIND_ARCHIVE,
            sourceName: null,
            manifestUrl: null,
            signedPayload: '',
            signature: '',
            keyId: '',
            expectedChecksums: [],
            composer: $composer,
            runtime: $runtime,
            archiveStagingDir: $stagingDir,
        );
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param array<string,mixed>|null $manifest
     * @param array<string,mixed>|null $compatibility
     * @param list<string> $capabilities
     * @param array<string,mixed>|null $resolvedSource
     * @param array{mode:'connected'|'standalone',backendIncluded:bool,backendPackage:string|null,backendVersion:string|null,installMode:'composer-path-repository'|'composer-packagist'} $archive
     * @return array{
     *     ok: bool,
     *     signature: array{
     *         status: 'verified'|'invalid'|'unsigned'|'unverifiable',
     *         keyId: string|null,
     *         unknownKey: array{keyId:string,envSnippet:string}|null,
     *     },
     *     errors: list<string>,
     *     warnings: list<string>,
     *     manifest: array<string,mixed>|null,
     *     compatibility: array<string,mixed>|null,
     *     capabilities: list<string>,
     *     resolvedSource: array<string,mixed>|null,
     *     archive: array{
     *         mode: 'connected'|'standalone',
     *         backendIncluded: bool,
     *         backendPackage: string|null,
     *         backendVersion: string|null,
     *         installMode: 'composer-path-repository'|'composer-packagist',
     *     },
     * }
     */
    private function buildResult(
        bool $ok,
        string $signatureStatus,
        ?string $signatureKeyId,
        bool $unknownKeyId,
        array $errors,
        array $warnings,
        ?array $manifest,
        ?array $compatibility,
        array $capabilities,
        ?array $resolvedSource,
        array $archive,
    ): array {
        /** @var 'verified'|'invalid'|'unsigned'|'unverifiable' $status */
        $status = in_array($signatureStatus, ['verified', 'invalid', 'unsigned', 'unverifiable'], true)
            ? $signatureStatus
            : 'unverifiable';

        $unknownKey = null;
        if ($unknownKeyId && $signatureKeyId !== null) {
            $unknownKey = [
                'keyId' => $signatureKeyId,
                'envSnippet' => sprintf(
                    'SELFHELP_PLUGIN_TRUSTED_KEYS=%s=<paste-base64-here>',
                    $signatureKeyId,
                ),
            ];
        }

        return [
            'ok' => $ok,
            'signature' => [
                'status' => $status,
                'keyId' => $signatureKeyId,
                'unknownKey' => $unknownKey,
            ],
            'errors' => $errors,
            'warnings' => $warnings,
            'manifest' => $manifest,
            'compatibility' => $compatibility,
            'capabilities' => $capabilities,
            'resolvedSource' => $resolvedSource,
            'archive' => $archive,
        ];
    }
}
