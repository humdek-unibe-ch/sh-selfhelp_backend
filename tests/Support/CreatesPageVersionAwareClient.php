<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * For a {@see \Symfony\Bundle\FrameworkBundle\Test\WebTestCase}: creates the test
 * client as a {@see PageVersionAwareBrowser}, so writes to a page's draft carry
 * the current `X-Page-Version` (live-sync contract C8) without each test knowing it.
 */
trait CreatesPageVersionAwareClient
{
    protected static function createPageVersionAwareClient(): KernelBrowser
    {
        if (static::$booted) {
            throw new \LogicException('The kernel must not be booted before the test client is created.');
        }

        $client = new PageVersionAwareBrowser(static::bootKernel());
        self::getClient($client); // registers it for the assertion helpers of the test case

        return $client;
    }
}
