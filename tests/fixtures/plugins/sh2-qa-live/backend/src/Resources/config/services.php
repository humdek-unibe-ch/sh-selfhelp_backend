<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

use Humdek\QaLiveBundle\Controller\QaLiveAdminController;
use Humdek\QaLiveBundle\Controller\QaLiveWriteForController;
use Humdek\QaLiveBundle\Job\QaLiveJobHandler;
use Humdek\QaLiveBundle\Resource\QaLiveResourceSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->load('Humdek\\QaLiveBundle\\', '../../*')
        ->exclude([
            '../../{Entity,Migrations,Resources,QaLiveCatalogue.php}',
            '../../QaLiveBundle.php',
        ]);

    $services->set(QaLiveAdminController::class)->tag('controller.service_arguments');
    $services->set(QaLiveWriteForController::class)->tag('controller.service_arguments');
    $services->set(QaLiveResourceSubscriber::class)->tag('kernel.event_subscriber');
    // Lazy: PluginScheduledJobRegistry iterates handlers at construct; this
    // handler needs DataService, which eventually needs the registry again.
    $services->set(QaLiveJobHandler::class)->lazy();
};