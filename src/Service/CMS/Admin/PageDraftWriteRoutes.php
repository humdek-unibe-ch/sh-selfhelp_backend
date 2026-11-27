<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

/**
 * The routes that write a page's DRAFT (live-sync contract C8, C9), by route name.
 * Every one needs the page lock check and the draft version (`X-Page-Version`).
 *
 * The one list: {@see \App\EventListener\PageDraftWriteGuardListener} reads it,
 * and a test keeps it equal to the non-GET `/admin/pages/{page_id}/...` routes of
 * `api_routes`, so a new draft-write route cannot be forgotten. Not in the list:
 * creating, importing or exporting pages (no draft exists yet, or nothing is
 * written), reads, and the lock routes themselves.
 */
final class PageDraftWriteRoutes
{
    public const NAMES = [
        // Page settings and fields; deleting the page.
        'admin_pages_update',
        'admin_pages_delete',
        // Sections of the page: add, create, remove, move, update, import.
        'admin_pages_add_section',
        'admin_pages_create_section',
        'admin_pages_remove_section',
        'admin_pages_bulk_remove_sections',
        'admin_sections_add',
        'admin_sections_create_child',
        'admin_sections_remove',
        'admin_sections_update',
        'admin_sections_import_to_page',
        'admin_sections_import_to_section',
        'admin_sections_restore_from_version',
        // Versions: publish, unpublish, publish a version, delete a version.
        'admin_page_publish',
        'admin_page_unpublish',
        'admin_page_publish_specific_version',
        'admin_page_version_delete',
    ];

    /** The route that deletes the page: its version cannot be read back, and its lock goes with it. */
    public const DELETE_PAGE = 'admin_pages_delete';

    private function __construct()
    {
    }
}
