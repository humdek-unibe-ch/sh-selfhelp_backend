<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\ApiVersionResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pure-unit coverage for {@see ApiVersionResolver} — the dependency-free helper
 * that resolves the API version from the URL/Accept header and maps a version +
 * domain to a controller FQCN.
 */
final class ApiVersionResolverTest extends TestCase
{
    private ApiVersionResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ApiVersionResolver();
    }

    public function testVersionIsResolvedFromTheUrlPath(): void
    {
        $request = Request::create('/cms-api/v1/admin/pages');

        self::assertSame('v1', $this->resolver->getVersion($request));
    }

    public function testVersionIsResolvedFromTheAcceptHeader(): void
    {
        $request = Request::create('/cms-api/anything');
        $request->headers->set('Accept', 'application/vnd.selfhelp.v1+json');

        self::assertSame('v1', $this->resolver->getVersion($request));
    }

    public function testUnknownUrlVersionFallsBackToDefault(): void
    {
        // v2 is matched by the URL regex but is NOT in AVAILABLE_VERSIONS, so the
        // resolver must fall through to the default rather than echo it back.
        $request = Request::create('/cms-api/v2/admin/pages');

        self::assertSame('v1', $this->resolver->getVersion($request));
    }

    public function testMissingVersionFallsBackToDefault(): void
    {
        $request = Request::create('/cms-api/lookups');

        self::assertSame('v1', $this->resolver->getVersion($request));
    }

    public function testGetControllerClassResolvesAnExistingController(): void
    {
        $class = $this->resolver->getControllerClass('v1', 'auth');

        self::assertSame('App\\Controller\\Api\\V1\\Auth\\AuthController', $class);
        self::assertTrue(class_exists($class), 'Resolved controller class must exist.');
    }

    public function testGetControllerClassThrowsForUnknownController(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->resolver->getControllerClass('v1', 'qaNonExistentDomain');
    }
}
