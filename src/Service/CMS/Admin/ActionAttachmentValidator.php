<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Repository\AssetRepository;
use App\Service\Action\ActionConfig;
use App\Service\Core\BaseService;

/**
 * Which files an action may mail out. The action runs later with system rights
 * and mails whatever its config names, so the check is done when the action is
 * SAVED, against the person saving it: an attachment must be an uploaded asset
 * (its delivery URL), and that person must be allowed to view the asset (the
 * asset's groups, like on the website). A server file path is refused, and so
 * is an asset the author cannot see, otherwise an action would be a way to
 * mail out files the author has no right to read.
 *
 * The sender repeats the asset-only rule when it sends (JobSchedulerService),
 * so a config that reached the database another way still cannot read a path.
 */
class ActionAttachmentValidator extends BaseService
{
    public function __construct(
        private readonly AssetStorageService $assetStorage,
        private readonly AssetRepository $assetRepository,
        private readonly AssetAccessService $assetAccess,
    ) {
    }

    /**
     * @param array<mixed> $config the decoded action config
     *
     * @throws \App\Exception\ServiceException 400 when an attachment is not an
     *         existing uploaded asset, 403 when the caller may not view it
     */
    public function assertAllowed(array $config): void
    {
        foreach ($this->attachmentReferences($config) as $reference) {
            $uuid = $this->assetStorage->uuidFromReference($reference);
            if ($uuid === null) {
                $this->throwBadRequest(sprintf(
                    'Attachment "%s" is not an uploaded asset. Attach an asset by its delivery URL (%s<uuid>); server file paths are not allowed.',
                    $reference,
                    AssetStorageService::URL_PREFIX
                ));
            }

            $asset = $this->assetRepository->findOneByUuid($uuid);
            if ($asset === null) {
                $this->throwBadRequest(sprintf('Attachment asset "%s" does not exist.', $reference));
            }

            $this->assetAccess->assertCanView($asset);
        }
    }

    /**
     * Every non-blank attachment of every email notification in the config:
     * the notification of each job and of each reminder of a job.
     *
     * @param array<mixed> $config
     * @return list<string>
     */
    private function attachmentReferences(array $config): array
    {
        $references = [];
        foreach ($this->asArray($config[ActionConfig::BLOCKS] ?? null) as $block) {
            foreach ($this->asArray($this->asArray($block)[ActionConfig::JOBS] ?? null) as $job) {
                $job = $this->asArray($job);
                $notifications = [$job[ActionConfig::NOTIFICATION] ?? null];
                foreach ($this->asArray($job[ActionConfig::REMINDERS] ?? null) as $reminder) {
                    $notifications[] = $this->asArray($reminder)[ActionConfig::NOTIFICATION] ?? null;
                }

                foreach ($notifications as $notification) {
                    foreach ($this->asArray($this->asArray($notification)[ActionConfig::ATTACHMENTS] ?? null) as $attachment) {
                        if (!is_string($attachment)) {
                            $this->throwBadRequest('Attachments must be given as asset delivery URLs (strings).');
                        }
                        if (trim($attachment) !== '') {
                            $references[] = $attachment;
                        }
                    }
                }
            }
        }

        return $references;
    }
}
