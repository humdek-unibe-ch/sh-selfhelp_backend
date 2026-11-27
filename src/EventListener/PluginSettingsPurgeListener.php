<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Plugin\Event\Lifecycle\PluginPurgedEvent;
use App\Plugin\Settings\PluginSettingsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Deletes the stored settings of a plugin when it is purged (contract C14).
 *
 * `plugin_settings` rows are keyed by the plugin id and carry no foreign key to `plugins`, so
 * uninstalling a plugin (which deletes its `plugins` row) leaves them, encrypted secrets included, and a
 * reinstall finds them again. Only the purge, which the administrator confirms as irreversible, removes
 * them. `PluginUninstaller` and `PluginPurger` do not know about settings; this listener is the whole link.
 *
 * Best-effort like the other listeners that follow a committed lifecycle change: a failure is logged and
 * does not turn a finished purge into an error.
 */
final class PluginSettingsPurgeListener
{
    public function __construct(
        private readonly PluginSettingsService $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: PluginPurgedEvent::class)]
    public function onPurged(PluginPurgedEvent $event): void
    {
        $pluginId = $event->plugin->getPluginId();

        try {
            $this->settings->purge($pluginId);
        } catch (\Throwable $e) {
            $this->logger->error('Could not delete the stored settings of a purged plugin.', [
                'plugin' => $pluginId,
                'exception' => $e,
            ]);
        }
    }
}
