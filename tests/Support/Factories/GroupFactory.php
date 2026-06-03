<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Factories;

use App\Entity\Group;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds `qa_`-prefixed {@see Group} rows so tests can model membership-driven
 * ACL scenarios without touching the three seeded production groups (`admin`,
 * `therapist`, `subject`). Reuses an existing qa group by name when re-run so the
 * deterministic keyword never collides (the DAMA transaction rolls each row back
 * at tearDown).
 */
final class GroupFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function createGroup(
        string $name = 'qa_group',
        string $description = 'QA test group',
        bool $requires2fa = false,
    ): Group {
        $existing = $this->em->getRepository(Group::class)->findOneBy(['name' => $name]);
        if ($existing instanceof Group) {
            return $existing;
        }

        $group = new Group();
        $group->setName($name);
        $group->setDescription($description);
        $group->setRequires2fa($requires2fa);
        $this->em->persist($group);
        $this->em->flush();

        return $group;
    }
}
