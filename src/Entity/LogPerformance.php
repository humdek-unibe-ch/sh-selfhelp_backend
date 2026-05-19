<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'log_performance')]
class LogPerformance
{
    #[ORM\Id]
    #[ORM\Column(name: 'id_user_activities', type: 'integer')]
    private int $idUserActivities;

    #[ORM\Column(name: 'log', type: 'text', nullable: true)]
    private ?string $log = null;

    #[ORM\OneToOne(targetEntity: UserActivity::class, inversedBy: 'logPerformance')]
    #[ORM\JoinColumn(name: 'id_user_activities', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?UserActivity $userActivity = null;

    public function getIdUserActivity(): ?int
    {
        return $this->idUserActivities;
    }

    public function setIdUserActivity(int $idUserActivities): self
    {
        $this->idUserActivities = $idUserActivities;
        return $this;
    }

    public function getIdUserActivities(): ?int
    {
        return $this->idUserActivities;
    }

    public function setIdUserActivities(int $idUserActivities): self
    {
        $this->idUserActivities = $idUserActivities;
        return $this;
    }

    public function getLog(): ?string
    {
        return $this->log;
    }

    public function setLog(?string $log): static
    {
        $this->log = $log;

        return $this;
    }

    public function getUserActivity(): ?UserActivity
    {
        return $this->userActivity;
    }

    public function setUserActivity(?UserActivity $userActivity): static
    {
        $this->userActivity = $userActivity;

        return $this;
    }
}
// ENTITY RULE
