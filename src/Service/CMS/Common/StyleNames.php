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
    public const STYLE_SHOW_USER_INPUT = 'showUserInput';

    /**
     * Style that is used for form record
     */
    public const STYLE_FORM_RECORD = 'form-record';

    /**
     * Styles that are allowed to be used for submitting data
     */
    public const FORM_STYLE_NAMES = [
        'form-record',
        'form-log',
    ];
}


