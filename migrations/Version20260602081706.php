<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dedicated registration of the frontend form routes under `/cms-api/v1/forms/*`
 * (Slice 4 / plan §26 risk mitigation).
 *
 * `POST /forms/submit`, `PUT /forms/update`, and `DELETE /forms/delete` are
 * currently materialized by the legacy `api_routes` seed sweep in
 * Version20260501000300 (`LegacySeedTrait::seedFromLegacy`). That legacy file
 * is slated for retirement. This migration re-registers the three form routes
 * AUTHORITATIVELY and idempotently (delete-then-insert by route_name+version)
 * so the legacy seed can be dropped without the form-submission workflow — and
 * the golden FormActionJobChainTest — losing its route.
 *
 * The form routes carry no entries in `rel_api_routes_permissions` (they are
 * public frontend routes guarded by ACL/page access, not route permissions),
 * so this migration only manages `api_routes` rows.
 *
 * Idempotent: re-running up() yields the same three rows whether or not the
 * legacy seed already created them.
 */
final class Version20260602081706 extends AbstractMigration
{
    private const VERSION = 'v1';

    /**
     * @return list<array{0:string,1:string,2:string,3:string,4:string}>
     *   [route_name, path, controller, methods, params-json]
     */
    private function formRoutes(): array
    {
        return [
            [
                'form_submit',
                '/forms/submit',
                'App\\Controller\\Api\\V1\\Frontend\\FormController::submitForm',
                'POST',
                '{"files":{"in":"form","required":false,"description":"Uploaded files for file input fields"},"page_id":{"in":"body","required":true},"form_data":{"in":"body","required":true},"section_id":{"in":"body","required":true}}',
            ],
            [
                'form_update',
                '/forms/update',
                'App\\Controller\\Api\\V1\\Frontend\\FormController::updateForm',
                'PUT',
                '{"files":{"in":"form","required":false,"description":"Uploaded files for file input fields"},"page_id":{"in":"body","required":true},"form_data":{"in":"body","required":true},"section_id":{"in":"body","required":true},"update_based_on":{"in":"body","required":false}}',
            ],
            [
                'form_delete',
                '/forms/delete',
                'App\\Controller\\Api\\V1\\Frontend\\FormController::deleteForm',
                'DELETE',
                '{"page_id":{"in":"body","required":true},"record_id":{"in":"body","required":true},"section_id":{"in":"body","required":true}}',
            ],
        ];
    }

    public function getDescription(): string
    {
        return 'Authoritatively (re-)register /cms-api/v1/forms/* routes so the legacy api_routes seed can be retired.';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->formRoutes() as [$routeName, $path, $controller, $methods, $params]) {
            // Idempotent: remove any pre-existing definition (e.g. from the
            // legacy seed) then insert the authoritative row.
            $this->addSql(
                'DELETE FROM `api_routes` WHERE route_name = ? AND version = ?',
                [$routeName, self::VERSION]
            );
            $this->addSql(
                'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) '
                . 'VALUES (?, ?, ?, ?, ?, NULL, ?, NULL)',
                [$routeName, self::VERSION, $path, $controller, $methods, $params]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->formRoutes() as [$routeName]) {
            $this->addSql(
                'DELETE FROM `api_routes` WHERE route_name = ? AND version = ?',
                [$routeName, self::VERSION]
            );
        }
    }
}
