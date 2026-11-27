<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Resource;

use App\Plugin\Manifest\PluginManifest;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which style fields and job config properties hold the id of a plugin
 * resource (contract C2): `styles[].resourceFields` and
 * `scheduledJobs[].resourceFields` of the **enabled** plugins, limited to
 * registered resource types ({@see RegisteredResourceTypes}).
 *
 * This is the one place that answers "is this field a reference to a plugin
 * item". The change notice audience ({@see \App\Service\Sync\ResourceReadIndex})
 * and the bind check ({@see ResourceBindingGuard}) ask it, and so must every
 * later reader; nothing else reads `resourceFields`.
 *
 * It reads the stored manifests directly (one small query, kept for a few
 * seconds per process) and is reset whenever plugins change
 * ({@see \App\Plugin\Cache\PluginCacheInvalidator}).
 */
class ResourceFieldMap implements ResetInterface
{
    /** How long one process keeps the map; plugin changes also reset it. */
    private const KEEP_SECONDS = 30;

    /** @var array<string, array<string, string>>|null style name => field name => type code */
    private ?array $styles = null;

    /** @var array<string, array<string, string>>|null job type => config property => type code */
    private ?array $jobTypes = null;

    private int $loadedAt = 0;

    public function __construct(
        private readonly Connection $connection,
        private readonly RegisteredResourceTypes $registeredTypes,
    ) {
    }

    /**
     * @return array<string, array<string, string>> style name => field name => resource type code
     */
    public function styles(): array
    {
        $this->load();

        return $this->styles ?? [];
    }

    /**
     * @return array<string, array<string, string>> plugin job type => config property => resource type code
     */
    public function jobTypes(): array
    {
        $this->load();

        return $this->jobTypes ?? [];
    }

    /** The resource type a style field holds, or null when the field is not a resource field. */
    public function typeOfStyleField(string $style, string $field): ?string
    {
        return $this->styles()[$style][$field] ?? null;
    }

    /** The resource type a job config property holds, or null when it is not a resource field. */
    public function typeOfJobField(string $jobType, string $property): ?string
    {
        return $this->jobTypes()[$jobType][$property] ?? null;
    }

    public function reset(): void
    {
        $this->styles = null;
        $this->jobTypes = null;
        $this->loadedAt = 0;
    }

    private function load(): void
    {
        if ($this->styles !== null && (time() - $this->loadedAt) < self::KEEP_SECONDS) {
            return;
        }

        $registered = array_flip($this->registeredTypes->codes());
        $styles = [];
        $jobTypes = [];
        foreach ($this->connection->fetchFirstColumn('SELECT manifest_json FROM plugins WHERE enabled = 1') as $stored) {
            $decoded = is_string($stored) ? json_decode($stored, true) : null;
            if (!is_array($decoded)) {
                continue;
            }
            /** @var array<string, mixed> $decoded */
            $manifest = new PluginManifest($decoded);

            foreach ($manifest->getResourceFields() as $style => $fields) {
                foreach ($fields as $field => $type) {
                    if (isset($registered[$type])) {
                        $styles[$style][$field] = $type;
                    }
                }
            }
            foreach ($manifest->getJobResourceFields() as $jobType => $properties) {
                foreach ($properties as $property => $type) {
                    if (isset($registered[$type])) {
                        $jobTypes[$jobType][$property] = $type;
                    }
                }
            }
        }

        $this->styles = $styles;
        $this->jobTypes = $jobTypes;
        $this->loadedAt = time();
    }
}
