<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

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
    use NarrowsJson;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
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

    /**
     * The booted kernel, narrowed to non-null. Use for APIs (Console
     * {@see \Symfony\Bundle\FrameworkBundle\Console\Application},
     * {@see \Symfony\Component\HttpKernel\Event\ExceptionEvent}) that reject the
     * nullable static property type. Does not reboot (which would drop the DAMA
     * transaction); relies on {@see setUp} having booted the kernel.
     */
    protected static function bootedKernel(): KernelInterface
    {
        self::assertNotNull(self::$kernel, 'Kernel must be booted (call parent::setUp() first).');

        return self::$kernel;
    }
}
