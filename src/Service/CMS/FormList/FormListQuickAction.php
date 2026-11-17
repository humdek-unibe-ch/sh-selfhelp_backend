<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Service\CMS\DataQuery\DataFilterGroup;

/**
 * A server-defined one-click change of a record ("mark as done"). The client
 * only names the action and the record; the server owns what is assigned and
 * when the action is offered.
 */
final readonly class FormListQuickAction
{
    /**
     * @param string $id the key the client sends
     * @param string $label the button text in the visitor's language
     * @param string|null $confirm question the client asks first; null means no confirmation
     * @param array<string, string|int|float|bool> $set column key => the value assigned
     * @param DataFilterGroup $when the record qualifies when it matches; an empty group offers the action on every record
     */
    public function __construct(
        public string $id,
        public string $label,
        public ?string $confirm,
        public array $set,
        public DataFilterGroup $when,
    ) {
    }
}
