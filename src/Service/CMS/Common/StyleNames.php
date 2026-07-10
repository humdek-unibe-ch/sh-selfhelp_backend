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
     * Styles that are allowed to be used for submitting data
     */
    public const FORM_STYLE_NAMES = [
        'form-record',
        'form-log',
        'entry-record-form',
    ];
}


