<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

final class ExampleBundleTestPaths
{
    /**
     * Every CMS-in-CMS template bundle shipped in the frontend gallery, keyed
     * by template id. Used by the parameterized golden import test so a new
     * template cannot ship without an import+render guard.
     *
     * @return array<string, string> template id -> bundle path
     */
    public static function cmsInCmsBundles(): array
    {
        $bundles = [];
        foreach (['team-members', 'news', 'faq', 'events', 'contact-directory', 'testimonials'] as $id) {
            $bundles[$id] = self::resolve('cms-in-cms/' . $id . '.bundle.json');
        }

        return $bundles;
    }

    public static function teamMembersBundle(): string
    {
        return self::resolve('cms-in-cms/team-members.bundle.json');
    }

    public static function heroHomeBundle(): string
    {
        return self::resolve('pages/hero-home.bundle.json', 'hero-home.bundle.json');
    }

    public static function mobileOnboardingBundle(): string
    {
        return self::resolve('pages/mobile-onboarding.bundle.json', 'mobile-onboarding.bundle.json');
    }

    public static function menuDemoBundle(): string
    {
        return self::resolve('navigation/menu-demo.bundle.json');
    }

    private static function resolve(string $frontendRelative, ?string $fixtureFallback = null): string
    {
        $frontendRoot = dirname(__DIR__, 3) . '/sh-selfhelp_frontend/examples';
        $frontendPath = $frontendRoot . '/' . $frontendRelative;
        if (is_file($frontendPath)) {
            return $frontendPath;
        }

        $fixtureName = $fixtureFallback ?? basename($frontendRelative);
        $fixturePath = dirname(__DIR__, 2) . '/tests/fixtures/examples/' . $fixtureName;
        if (is_file($fixturePath)) {
            return $fixturePath;
        }

        throw new \RuntimeException('Example bundle not found: ' . $frontendRelative);
    }
}
