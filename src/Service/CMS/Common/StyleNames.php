<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Common;

final class StyleNames
{
   
    /**
     * Built-in admin data grid over a form's entries (search / sort /
     * pagination / CSV / add-edit-delete row actions). Renamed from
     * `show-user-input` by Version20260710093048.
     */
    public const STYLE_ENTRY_TABLE = 'entry-table';

    /**
     * Style that is used for form record
     */
    public const STYLE_FORM_RECORD = 'form-record';

    /**
     * Route-aware form for CMS/public create+edit (dual routes via load_record_from).
     */
    public const STYLE_ENTRY_RECORD_FORM = 'entry-record-form';

    /**
     * Data-bound list: the backend clones the child template once per data row
     * (see PageService::processSectionsRecursively) before the page ships.
     */
    public const STYLE_ENTRY_LIST = 'entry-list';

    /**
     * Data-bound single record: the bound row's columns become top-level
     * interpolation tokens for the section and its children.
     */
    public const STYLE_ENTRY_RECORD = 'entry-record';

    /**
     * Delete trigger inside an entry subtree: the backend injects the bound
     * row's `record_id` as a field so the renderers can call the delete API.
     */
    public const STYLE_ENTRY_RECORD_DELETE = 'entry-record-delete';

    /**
     * Generic repeater: clones its child template once per row, rows coming
     * from `data_config` (like entry-list) or the style's `loop` JSON field.
     */
    public const STYLE_LOOP = 'loop';

    /**
     * The participant-facing list over one data table: a server-rendered page
     * of records with search / sort / filters, and the create, view, edit and
     * delete workflow around them (see App\Service\CMS\FormList). Its children
     * hold the inline form.
     */
    public const STYLE_ENTRY_FORM_LIST = 'entry-form-list';

    /**
     * Read-only list over one data table for public or composed pages: the same
     * server-rendered list state as `entry-form-list`, without any write.
     */
    public const STYLE_DATA_VIEW = 'data-view';

    /**
     * Styles whose state is served by the list endpoints.
     */
    public const LIST_STYLE_NAMES = [
        self::STYLE_ENTRY_FORM_LIST,
        self::STYLE_DATA_VIEW,
    ];

    /**
     * Form file upload input; its files are stored by FormFileUploadService.
     */
    public const STYLE_FILE_INPUT = 'file-input';

    /**
     * "About the platform" block. The backend adds the `about` payload to every
     * section of this style when the page is served, after the page cache is
     * read, because the detail shown depends on the caller (see
     * PageService::withPlatformAboutOnPage and PlatformAboutService).
     */
    public const STYLE_VERSION = 'version';

    /**
     * Styles that are allowed to be used for submitting data
     */
    public const FORM_STYLE_NAMES = [
        'form-record',
        'form-log',
        'entry-record-form',
    ];

    /**
     * Styles that are never drawn inside the row template of a data view: the
     * view only reads, so it holds no form, and a list inside a list would share
     * the page's list names. Sections of these styles are left out of the template.
     */
    public const ROW_TEMPLATE_EXCLUDED_STYLE_NAMES = [
        'form-record',
        'form-log',
        'entry-record-form',
        self::STYLE_ENTRY_FORM_LIST,
        self::STYLE_DATA_VIEW,
    ];
}


