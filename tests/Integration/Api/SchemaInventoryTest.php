<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guardrail inventory for the JSON-Schema contracts under
 * `config/schemas/api/v1` (plan "Test Foundation And Guardrails").
 *
 * Three deterministic, DB-free checks:
 *   1. every schema file parses as valid JSON;
 *   2. every relative `$ref` resolves to a file that exists (no dangling
 *      references — these silently break {@see \App\Service\JSON\JsonSchemaValidationService});
 *   3. every schema NAME a controller passes to `validateRequest()` /
 *      `formatSuccess()` exists on disk, except a small, explicit allowlist
 *      of KNOWN pre-existing drift (response-schema validation is opt-in, so
 *      a missing RESPONSE schema is latent — listing it keeps the drift
 *      visible and makes a NEW missing reference fail loudly).
 */
#[Group('security')]
final class SchemaInventoryTest extends TestCase
{
    /**
     * Schema names referenced by controllers that do NOT (yet) have a file.
     * All would be RESPONSE schemas passed to `formatSuccess()`, where
     * validation is opt-in (`VALIDATE_RESPONSE_SCHEMA`, off by default) so a
     * missing file is latent drift, not a runtime break. Documented here so the
     * guardrail stays green while the drift is visible; remove an entry when
     * the schema is added.
     *
     * Currently EMPTY: the four `responses/admin/page_version_*` /
     * `page_unpublished` schemas referenced by `PageVersionController` were
     * added (matching the controller's normalized responses — datetimes are
     * ISO-8601 strings via the Symfony serializer) and are now exercised by
     * `tests/Golden/PageVersioningWorkflowTest`. Keep this list empty unless a
     * new, genuinely-latent response-schema reference is introduced.
     *
     * @var list<string>
     */
    private const KNOWN_MISSING_REFERENCES = [];

    /**
     * The known-missing allowlist as a `list<string>` (the bare constant infers
     * to an empty array shape, which makes `in_array`/`foreach` over it read as
     * statically dead while the allowlist is empty). Behaviour is identical.
     *
     * @return list<string>
     */
    private function knownMissingReferences(): array
    {
        return self::KNOWN_MISSING_REFERENCES;
    }

    private function schemaDir(): string
    {
        return $this->projectDir() . '/config/schemas/api/v1';
    }

    private function projectDir(): string
    {
        // tests/Integration/Api/ -> project root is three levels up.
        return str_replace('\\', '/', \dirname(__DIR__, 3));
    }

    public function testEverySchemaFileIsValidJson(): void
    {
        $invalid = [];
        foreach ($this->schemaFiles() as $file) {
            $contents = (string) file_get_contents($file);
            json_decode($contents);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $invalid[] = $this->relative($file) . ' => ' . json_last_error_msg();
            }
        }

        self::assertSame(
            [],
            $invalid,
            "Schema files that are not valid JSON:\n" . implode("\n", $invalid)
        );
    }

    public function testEveryRelativeRefResolvesToAnExistingFile(): void
    {
        $dangling = [];
        foreach ($this->schemaFiles() as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($this->collectRefs($decoded) as $ref) {
                // Internal pointer only (no file part) -> resolved in-document.
                if ($ref === '' || str_starts_with($ref, '#')) {
                    continue;
                }
                $path = strtok($ref, '#'); // strip any JSON-pointer fragment (always a non-empty token here)
                $resolved = $this->resolveRef(\dirname($file), $path);
                if (!is_file($resolved)) {
                    $dangling[] = $this->relative($file) . ' -> ' . $ref;
                }
            }
        }

        self::assertSame(
            [],
            $dangling,
            "Schemas with dangling \$ref targets:\n" . implode("\n", $dangling)
        );
    }

    public function testEveryControllerReferencedSchemaExists(): void
    {
        $referenced = $this->collectControllerSchemaReferences();
        self::assertNotEmpty($referenced, 'Expected to find schema references in src/Controller.');

        $missing = [];
        foreach ($referenced as $name) {
            if (is_file($this->schemaDir() . '/' . $name . '.json')) {
                continue;
            }
            if (in_array($name, $this->knownMissingReferences(), true)) {
                continue;
            }
            $missing[] = $name;
        }
        sort($missing);

        self::assertSame(
            [],
            $missing,
            "Controller(s) reference schema name(s) with no matching file under config/schemas/api/v1:\n"
            . implode("\n", $missing)
            . "\nAdd the schema, fix the reference, or (for opt-in response schemas) document it in KNOWN_MISSING_REFERENCES."
        );
    }

    public function testKnownMissingReferencesAreStillReferencedAndStillMissing(): void
    {
        $referenced = $this->collectControllerSchemaReferences();

        $stale = [];
        foreach ($this->knownMissingReferences() as $name) {
            $stillMissing = !is_file($this->schemaDir() . '/' . $name . '.json');
            $stillReferenced = in_array($name, $referenced, true);
            if (!$stillMissing || !$stillReferenced) {
                $stale[] = $name . ($stillMissing ? '' : ' (file now exists)') . ($stillReferenced ? '' : ' (no longer referenced)');
            }
        }

        self::assertSame(
            [],
            $stale,
            "Stale KNOWN_MISSING_REFERENCES entries — remove them:\n" . implode("\n", $stale)
        );
    }

    /**
     * @return list<string> absolute paths of every *.json schema file
     */
    private function schemaFiles(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->schemaDir(), \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && $entry->getExtension() === 'json') {
                $files[] = str_replace('\\', '/', $entry->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Recursively collect every `$ref` string value in a decoded schema.
     *
     * @param array<array-key, mixed> $node
     * @return list<string>
     */
    private function collectRefs(array $node): array
    {
        $refs = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = $value;
            } elseif (is_array($value)) {
                foreach ($this->collectRefs($value) as $nested) {
                    $refs[] = $nested;
                }
            }
        }

        return $refs;
    }

    private function resolveRef(string $baseDir, string $relativePath): string
    {
        $combined = str_replace('\\', '/', $baseDir) . '/' . $relativePath;
        $parts = [];
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        // Preserve a leading drive letter (Windows) / root.
        $prefix = str_starts_with($combined, '/') ? '/' : '';

        return $prefix . implode('/', $parts);
    }

    /**
     * Scan src/Controller for schema-name string literals passed to the
     * validation / response helpers, skipping comment & docblock lines so
     * `@param ... 'requests/...'` examples are not mistaken for references.
     *
     * @return list<string>
     */
    private function collectControllerSchemaReferences(): array
    {
        $controllerDir = $this->projectDir() . '/src/Controller';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($controllerDir, \FilesystemIterator::SKIP_DOTS)
        );

        $names = [];
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }
            $lines = preg_split('/\R/', (string) file_get_contents($entry->getPathname())) ?: [];
            foreach ($lines as $line) {
                $trimmed = ltrim($line);
                // Skip comment / docblock lines (where @param examples live).
                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '//')) {
                    continue;
                }
                if (preg_match_all('#[\'"]((?:requests|responses|entities|common)/[A-Za-z0-9_/]+)[\'"]#', $line, $matches) > 0) {
                    foreach ($matches[1] as $name) {
                        $names[$name] = $name;
                    }
                }
            }
        }

        $list = array_values($names);
        sort($list);

        return $list;
    }

    private function relative(string $absolute): string
    {
        return str_replace($this->projectDir() . '/', '', str_replace('\\', '/', $absolute));
    }
}
