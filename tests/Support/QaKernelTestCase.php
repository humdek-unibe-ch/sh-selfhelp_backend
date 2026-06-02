<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class for kernel-level (service/integration) tests that need the
 * container and the seeded QA baseline but not a full HTTP client.
 *
 * Use this for {@see \App\Service} integration tests. For controller/HTTP
 * tests extend {@see QaWebTestCase} instead.
 */
abstract class QaKernelTestCase extends KernelTestCase
{
    use InteractsWithQaBaseline;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertQaBaselineLoaded($this->em);
    }

    /**
     * Fetch a public service from the test container.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function service(string $id): object
    {
        /** @var T $service */
        $service = self::getContainer()->get($id);

        return $service;
    }
}
