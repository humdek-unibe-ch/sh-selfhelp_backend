<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\ScheduledJob;

use App\Entity\User;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * {@see PluginJobQueueInterface}: creates the job through
 * {@see JobSchedulerService::scheduleJob()}, the one place that creates jobs,
 * so the transaction log, the caches and the wake signal are the same as for a
 * job of an action.
 */
final class PluginJobQueue implements PluginJobQueueInterface
{
    /** Config keys the host writes itself; a plugin config must not use them. */
    public const RESERVED_CONFIG_KEYS = ['condition', 'schedule', 'action_job_type', 'recurring'];

    public function __construct(
        private readonly PluginRegistryService $plugins,
        private readonly JobSchedulerService $scheduler,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function queueNow(string $pluginId, string $type, array $config, ?int $userId): int
    {
        return $this->queue($pluginId, $type, $config, $userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    public function queueAt(string $pluginId, string $type, array $config, ?int $userId, \DateTimeImmutable $at): int
    {
        return $this->queue($pluginId, $type, $config, $userId, $at);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function queue(string $pluginId, string $type, array $config, ?int $userId, \DateTimeImmutable $at): int
    {
        $label = $this->labelOf($pluginId, $type);

        foreach (self::RESERVED_CONFIG_KEYS as $key) {
            if (array_key_exists($key, $config)) {
                throw new \InvalidArgumentException(sprintf('The job config key "%s" is reserved for the host.', $key));
            }
        }
        if ($userId !== null && !$this->entityManager->find(User::class, $userId) instanceof User) {
            throw new \InvalidArgumentException(sprintf('User %d does not exist.', $userId));
        }

        $jobData = [
            'type' => $type,
            'description' => $label,
            'date_to_be_executed' => $at->setTimezone(new \DateTimeZone('UTC')),
            'plugin_job_config' => $config,
        ];
        if ($userId !== null) {
            $jobData['user_id'] = $userId;
        }

        $job = $this->scheduler->scheduleJob($jobData, LookupService::TRANSACTION_BY_BY_SYSTEM);
        if ($job === false) {
            throw new \RuntimeException(sprintf('The job "%s" of plugin "%s" could not be queued.', $type, $pluginId));
        }

        return (int) $job->getId();
    }

    /**
     * The name of the job type, after checking that the enabled plugin offers it.
     *
     * @throws \InvalidArgumentException
     */
    private function labelOf(string $pluginId, string $type): string
    {
        $plugin = $this->plugins->findByPluginId($pluginId);
        if ($plugin === null || !$plugin->isEnabled()) {
            throw new \InvalidArgumentException(sprintf('Plugin "%s" is not enabled.', $pluginId));
        }

        foreach ((new PluginManifest($plugin->getManifestJson()))->getScheduledJobs() as $job) {
            if (($job['type'] ?? null) === $type) {
                $label = $job['label'] ?? null;

                return is_string($label) && trim($label) !== '' ? $label : $type;
            }
        }

        throw new \InvalidArgumentException(sprintf('Plugin "%s" does not offer the job type "%s".', $pluginId, $type));
    }
}
