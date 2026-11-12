<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\Decorator\EntityManagerDecorator;

/**
 * The real entity manager, except that the next `commit()` can be made to fail.
 *
 * A database that refuses the final commit cannot be produced on demand, but
 * it is the one step that comes after every other write, so a service that
 * promises "all or nothing" has to be tested against it. Every other call goes
 * straight to the wrapped manager, so the rollback undoes the real writes.
 */
final class FailingCommitEntityManager extends EntityManagerDecorator
{
    public const MESSAGE = 'qa commit failure';

    private bool $failNext = false;

    public function failNextCommit(): void
    {
        $this->failNext = true;
    }

    public function commit(): void
    {
        if ($this->failNext) {
            $this->failNext = false;

            throw new \RuntimeException(self::MESSAGE);
        }

        parent::commit();
    }
}
