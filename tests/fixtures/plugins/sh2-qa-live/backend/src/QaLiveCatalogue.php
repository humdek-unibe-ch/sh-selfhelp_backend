<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace Humdek\QaLiveBundle;

/** Manifest constants for the test-only QA live plugin (P10). */
final class QaLiveCatalogue
{
    public const PLUGIN_ID = 'sh2-qa-live';
    public const VERSION = '1.0.0';
    public const TYPE_CODE = 'qa_live_item';
    public const STYLE = 'qa-live-item';
    public const FIELD_ITEM_ID = 'item_id';
    public const FIELD_PIN_TABLE_ID = 'pin_table_id';
    public const PERMISSION_READ = 'qa_live.items.read';
    public const PERMISSION_CREATE = 'qa_live.items.create';
    public const PERMISSION_UPDATE = 'qa_live.items.update';
    public const CACHE_CATEGORY = self::PLUGIN_ID . '.items';
    public const JOB_TYPE = 'qa_live_job';
}