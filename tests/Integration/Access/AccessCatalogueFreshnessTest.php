<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Access;

use App\Service\Access\AccessCatalogueExporter;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The committed access files match the migrated database (F239, rules 1.5
 * and 4.14). CI runs it with the `security` group; the catalogue tests need
 * the frontend's `docs/reference/admin-screens.json` under
 * `SELFHELP_FRONTEND_DIR` (CI checks it out) or the sibling checkout.
 */
#[Group('security')]
final class AccessCatalogueFreshnessTest extends QaKernelTestCase
{
    private const REGENERATE = 'Run "php bin/console app:access:export" and commit the result.';

    private function exporter(): AccessCatalogueExporter
    {
        return $this->service(AccessCatalogueExporter::class);
    }

    public function testCommittedRouteTableMatchesTheDatabase(): void
    {
        $exporter = $this->exporter();
        $generated = $exporter->renderRouteTable($exporter->readRouteTable());

        self::assertTrue(
            $exporter->isFresh(AccessCatalogueExporter::ROUTES_FILE, $generated),
            AccessCatalogueExporter::ROUTES_FILE . ' is stale. ' . self::REGENERATE
        );
    }

    public function testCommittedPermissionCatalogueIsFresh(): void
    {
        $exporter = $this->exporter();
        $table = $exporter->readRouteTable();
        $screens = $exporter->loadScreens($exporter->resolveFrontendDir());

        self::assertTrue(
            $exporter->isFresh(AccessCatalogueExporter::CATALOGUE_FILE, $exporter->renderCatalogue($table, $screens)),
            AccessCatalogueExporter::CATALOGUE_FILE . ' is stale. ' . self::REGENERATE
        );
    }

    public function testEveryCorePermissionGuardsARouteOrAScreen(): void
    {
        $exporter = $this->exporter();
        $table = $exporter->readRouteTable();
        $screens = $exporter->loadScreens($exporter->resolveFrontendDir());

        self::assertSame(
            [],
            $exporter->findUnguardedPermissions($table, $screens),
            'These permissions guard no route and open no screen (rule 4.14): remove them or give them a route or screen.'
        );
    }
}
