<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Common;

final class StyleNames
{
   
    /**
     * Style that is used to display a user input form (read/view context)
     */
    public const STYLE_SHOW_USER_INPUT = 'show-user-input';

    /**
     * Style that is used for form record
     */
    public const STYLE_FORM_RECORD = 'form-record';

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
     * Styles that are allowed to be used for submitting data
     */
    public const FORM_STYLE_NAMES = [
        'form-record',
        'form-log',
    ];
}


