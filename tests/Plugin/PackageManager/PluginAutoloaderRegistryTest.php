<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Plugin\PackageManager;

use App\Plugin\PackageManager\PluginAutoloaderRegistry;
use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the secondary autoloader registry. Two
 * invariants matter:
 *
 *   1. `set()` stashes the loader and `get()` returns the same
 *      instance. This is the contract that
 *      `PackageManagerRunner::resolvePluginClassLoader()` relies on.
 *
 *   2. `set()` last-write-wins so the boot path can re-stash the
 *      loader after a `composer require` regenerates the maps.
 *
 * Static state is reset between tests via reflection — the registry
 * intentionally does not expose a public reset method so the
 * production API stays minimal.
 */
final class PluginAutoloaderRegistryTest extends TestCase
{
    private function clearRegistry(): void
    {
        $ref = new \ReflectionProperty(PluginAutoloaderRegistry::class, 'loader');
        $ref->setValue(null, null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearRegistry();
    }

    protected function tearDown(): void
    {
        $this->clearRegistry();
        parent::tearDown();
    }

    public function testGetReturnsNullWhenNothingSet(): void
    {
        $this->assertNull(PluginAutoloaderRegistry::get());
    }

    public function testSetStoresLoaderAndGetReturnsIt(): void
    {
        $loader = new ClassLoader();
        PluginAutoloaderRegistry::set($loader);
        $this->assertSame($loader, PluginAutoloaderRegistry::get());
    }

    public function testSetOverwritesPreviousLoader(): void
    {
        $first = new ClassLoader();
        $second = new ClassLoader();
        PluginAutoloaderRegistry::set($first);
        PluginAutoloaderRegistry::set($second);
        $this->assertSame($second, PluginAutoloaderRegistry::get());
    }
}
