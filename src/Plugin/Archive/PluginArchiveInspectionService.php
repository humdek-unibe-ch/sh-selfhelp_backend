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
use App\Plugin\Versioning\PluginCompatibilityValidator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Backs the `/admin/plugins/inspect-archive` endpoint.
 *
 * Extracts the upload, runs each stage of the validator pipeline
 * defensively, and converts failures into structured `errors[]`
 * entries with a precise `signatureStatus` ("verified" | "invalid" |
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
    ) {
    }

    /**
     * @return array{
     *     ok: bool,
     *     signatureStatus: 'verified'|'invalid'|'unsigned'|'unverifiable',
     *     errors: list<string>,
     *     warnings: list<string>,
     *     manifest: array<string,mixed>|null,
     *     compatibility: array<string,mixed>|null,
     *     capabilities: list<string>,
     *     resolvedSource: array<string,mixed>|null,
     * }
     */
    public function inspect(UploadedFile $upload): array
    {
        $errors = [];
        $warnings = [];
        $manifestArray = null;
        $capabilitiesList = [];
        $compatibility = null;
        $resolvedDescriptor = null;
        $signatureStatus = 'unverifiable';

        // Stage 1 — unzip into staging. The admin needs to know exactly
        // why an upload was rejected (wrong extension, zip-slip, too
        // big, missing files), so we never throw a generic 500 here —
        // we report the message back as `errors[]` so the UI can show
        // a precise preview-time error.
        try {
            $extracted = $this->extractor->extract($upload);
        } catch (PluginArchiveException $e) {
            return $this->buildResult(false, 'unverifiable', [$e->getMessage()], $warnings, null, null, [], null);
        }
        $stagingDir = $extracted['stagingDir'];

        // Stage 2 — load manifest from the archive even if signature
        // verification later fails. The admin still needs to see what
        // the manifest claims.
        try {
            $manifest = $this->manifestLoader->loadFromFile($stagingDir . '/plugin.json');
            $manifestArray = $manifest->toArray();
            $capabilitiesList = array_values($manifest->getCapabilities());
        } catch (\Throwable $e) {
            $errors[] = sprintf('plugin.json invalid: %s', $e->getMessage());
            return $this->buildResult(false, 'invalid', $errors, $warnings, null, null, [], null);
        }

        // Stage 3 — full validator pipeline (canonical payload re-hash
        // + SHA-256 of every artifact + Ed25519 signature check).
        $resolved = null;
        try {
            $result = $this->validator->validate($stagingDir);
            $resolved = $result['resolved'];
            $signatureStatus = 'verified';
        } catch (PluginArchiveException $e) {
            // Tease apart "unsigned (allowed)" vs "signature invalid".
            $previous = $e->getPrevious();
            if ($previous instanceof PluginSignatureException) {
                $signatureStatus = 'invalid';
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
            $errors,
            $warnings,
            $manifestArray,
            $compatibility,
            $capabilitiesList,
            $resolvedDescriptor,
        );
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
     * @return array{
     *     ok: bool,
     *     signatureStatus: 'verified'|'invalid'|'unsigned'|'unverifiable',
     *     errors: list<string>,
     *     warnings: list<string>,
     *     manifest: array<string,mixed>|null,
     *     compatibility: array<string,mixed>|null,
     *     capabilities: list<string>,
     *     resolvedSource: array<string,mixed>|null,
     * }
     */
    private function buildResult(
        bool $ok,
        string $signatureStatus,
        array $errors,
        array $warnings,
        ?array $manifest,
        ?array $compatibility,
        array $capabilities,
        ?array $resolvedSource,
    ): array {
        /** @var 'verified'|'invalid'|'unsigned'|'unverifiable' $status */
        $status = in_array($signatureStatus, ['verified', 'invalid', 'unsigned', 'unverifiable'], true)
            ? $signatureStatus
            : 'unverifiable';
        return [
            'ok' => $ok,
            'signatureStatus' => $status,
            'errors' => $errors,
            'warnings' => $warnings,
            'manifest' => $manifest,
            'compatibility' => $compatibility,
            'capabilities' => $capabilities,
            'resolvedSource' => $resolvedSource,
        ];
    }
}
