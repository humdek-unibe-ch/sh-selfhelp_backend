<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Per-group binding of a validation code.
 *
 * Renamed from `CodesGroup` (legacy `codes_groups` table) to
 * `ValidationCodeGroup` (`validation_code_groups`) under the
 * strict_split policy: this is the lifecycle table for the
 * validation-code → group assignment, not a pure link.
 */
#[ORM\Entity]
#[ORM\Table(name: 'validation_code_groups')]
class ValidationCodeGroup
{
    public function __construct()
    {
        // Empty constructor
    }

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ValidationCode::class)]
    #[ORM\JoinColumn(name: 'code', referencedColumnName: 'code', onDelete: 'CASCADE')]
    private ?ValidationCode $code = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'id_groups', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Group $group = null;

    public function getCode(): ?ValidationCode
    {
        return $this->code;
    }
    public function setCode(ValidationCode $code): self { $this->code = $code; return $this; }

    public function getGroup(): ?Group
    {
        return $this->group;
    }

    public function setGroup(Group $group): self { $this->group = $group; return $this; }
}
// ENTITY RULE
