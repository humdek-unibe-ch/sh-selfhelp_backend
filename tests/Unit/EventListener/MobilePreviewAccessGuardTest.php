<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\MobilePreviewAccessGuard;
use App\Service\Auth\JWTService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Unit coverage for the mobile-preview scope guard.
 *
 * Asserts the observable contract of the scoped `purpose: mobile_preview` token:
 * it may hit the read-only core render allowlist with GET, plus any plugin
 * PUBLIC runtime route (`/cms-api/v{n}/plugins/...`) with any method so embedded
 * plugin styles work like on the live page. Every core mutation, every admin
 * route (core or plugin), and every non-listed core read is denied — while a
 * normal (non preview) token is never touched by this guard.
 */
final class MobilePreviewAccessGuardTest extends TestCase
{
    private function guard(): MobilePreviewAccessGuard
    {
        $jwt = $this->createStub(JWTService::class);
        // Mirror the real predicate: flag boolean OR purpose string.
        $jwt->method('isMobilePreviewPayload')->willReturnCallback(
            static fn (array $p): bool => !empty($p['mobile_preview']) || (($p['purpose'] ?? null) === 'mobile_preview'),
        );

        return new MobilePreviewAccessGuard($jwt, new NullLogger());
    }

    /**
     * @param array<string, mixed>|null $payload decoded JWT payload, or null for "no token"
     */
    private function event(string $path, string $routeName, string $method = 'GET', ?array $payload = null): ControllerEvent
    {
        $request = Request::create($path, $method);
        $request->attributes->set('_route', $routeName);
        if ($payload !== null) {
            $request->attributes->set('_jwt_payload', $payload);
        }
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new ControllerEvent($kernel, static fn () => new Response(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    /** @var array<string, mixed> */
    private const PREVIEW_PAYLOAD = [
        'id_users'       => 1,
        'purpose'        => 'mobile_preview',
        'mobile_preview' => true,
    ];

    private function assertDenied(ControllerEvent $event): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->guard()->onKernelController($event);
    }

    public function testAllowsPreviewTokenOnAllowlistedGet(): void
    {
        $this->guard()->onKernelController(
            $this->event('/cms-api/v1/languages', 'languages_get_all_v1', 'GET', self::PREVIEW_PAYLOAD),
        );
        $this->guard()->onKernelController(
            $this->event('/cms-api/v1/pages/by-keyword/home', 'pages_get_by_keyword_v1', 'GET', self::PREVIEW_PAYLOAD),
        );
        $this->guard()->onKernelController(
            $this->event('/cms-api/v1/pages/resolve?path=%2Fteam-members%2F4', 'pages_resolve_path_v1', 'GET', self::PREVIEW_PAYLOAD),
        );
        $this->guard()->onKernelController(
            $this->event('/cms-api/v1/auth/user-data', 'auth_user_data_get_v1', 'GET', self::PREVIEW_PAYLOAD),
        );
        $this->expectNotToPerformAssertions();
    }

    public function testBlocksPreviewTokenOnNonAllowlistedRoute(): void
    {
        $this->assertDenied(
            $this->event('/cms-api/v1/admin/pages', 'admin_pages_get_all_v1', 'GET', self::PREVIEW_PAYLOAD),
        );
    }

    public function testBlocksPreviewTokenOnNonGetMethod(): void
    {
        // Even an allowlisted route name is denied when the method is not GET.
        $this->assertDenied(
            $this->event('/cms-api/v1/languages', 'languages_get_all_v1', 'POST', self::PREVIEW_PAYLOAD),
        );
    }

    /**
     * A plugin style embedded in the previewed page (e.g. the SurveyJS runtime)
     * must reach the plugin's PUBLIC api with the SAME methods it uses on the
     * live page — load (GET), autosave (PUT) and submit (POST) — so the preview
     * behaves exactly like the standalone app.
     */
    public function testAllowsPreviewTokenOnPluginPublicRouteForAnyMethod(): void
    {
        $this->guard()->onKernelController(
            $this->event('/cms-api/v1/plugins/sh2-shp-survey-js/published/SV_TEST', 'surveyjs_public_published_v1', 'GET', self::PREVIEW_PAYLOAD),
        );
        $this->guard()->onKernelController(
            $this->event('/cms-api/v1/plugins/sh2-shp-survey-js/published/SV_TEST/submit', 'surveyjs_public_submit_v1', 'POST', self::PREVIEW_PAYLOAD),
        );
        $this->guard()->onKernelController(
            $this->event('/cms-api/v1/plugins/sh2-shp-survey-js/published/SV_TEST/progress', 'surveyjs_public_progress_put_v1', 'PUT', self::PREVIEW_PAYLOAD),
        );
        $this->expectNotToPerformAssertions();
    }

    /**
     * The previewed page's core forms must be testable end-to-end: submit
     * (POST), update (PUT) and delete (DELETE) are permitted so a form embedded
     * in the preview behaves exactly as on the live page. The preview runs as
     * the admin's (or impersonated) identity; FormController enforces ACL.
     */
    public function testAllowsPreviewTokenOnCoreFormRoutes(): void
    {
        $this->guard()->onKernelController(
            $this->event('/cms-api/v1/forms/submit', 'form_submit_v1', 'POST', self::PREVIEW_PAYLOAD),
        );
        $this->guard()->onKernelController(
            $this->event('/cms-api/v1/forms/update', 'form_update_v1', 'PUT', self::PREVIEW_PAYLOAD),
        );
        $this->guard()->onKernelController(
            $this->event('/cms-api/v1/forms/delete', 'form_delete_v1', 'DELETE', self::PREVIEW_PAYLOAD),
        );
        $this->expectNotToPerformAssertions();
    }

    /**
     * The form allowlist is exact: a non-form core mutation that merely lives
     * under a similar path is still denied.
     */
    public function testBlocksPreviewTokenOnNonFormCoreMutation(): void
    {
        $this->assertDenied(
            $this->event('/cms-api/v1/admin/pages', 'admin_pages_create_v1', 'POST', self::PREVIEW_PAYLOAD),
        );
    }

    /**
     * The permission-gated plugin ADMIN surface stays off-limits to a preview
     * token even though its public surface is now reachable.
     */
    public function testBlocksPreviewTokenOnPluginAdminRoute(): void
    {
        $this->assertDenied(
            $this->event('/cms-api/v1/admin/plugins/sh2-shp-survey-js/surveys', 'surveyjs_admin_list_v1', 'GET', self::PREVIEW_PAYLOAD),
        );
    }

    public function testAllowsPreviewTokenWhenRouteMatchesMintedScope(): void
    {
        $event = $this->event(
            '/cms-api/v1/pages/by-keyword/qa_home?language_id=1&preview=true',
            'pages_get_by_keyword_v1',
            'GET',
            self::PREVIEW_PAYLOAD + [
                'mobile_preview_scope' => [
                    'keyword'     => 'qa_home',
                    'language_id' => 1,
                    'draft'       => true,
                ],
            ],
        );
        $event->getRequest()->attributes->set('keyword', 'qa_home');

        $this->guard()->onKernelController($event);
        $this->expectNotToPerformAssertions();
    }

    public function testBlocksPreviewTokenWhenRouteCrossesMintedScope(): void
    {
        $event = $this->event(
            '/cms-api/v1/pages/by-keyword/qa_other?language_id=1&preview=true',
            'pages_get_by_keyword_v1',
            'GET',
            self::PREVIEW_PAYLOAD + [
                'mobile_preview_scope' => [
                    'keyword'     => 'qa_home',
                    'language_id' => 1,
                    'draft'       => true,
                ],
            ],
        );
        $event->getRequest()->attributes->set('keyword', 'qa_other');

        $this->assertDenied($event);
    }

    public function testBlocksPreviewTokenResolveWhenKeywordScopePinned(): void
    {
        $event = $this->event(
            '/cms-api/v1/pages/resolve?path=%2Fteam-members%2F4',
            'pages_resolve_path_v1',
            'GET',
            self::PREVIEW_PAYLOAD + [
                'mobile_preview_scope' => [
                    'keyword'     => 'qa_home',
                    'language_id' => 1,
                    'draft'       => true,
                ],
            ],
        );

        $this->assertDenied($event);
    }

    public function testIgnoresNormalTokenEvenOnAdminRoute(): void
    {
        // A normal (non-preview) token is outside this guard's concern — the
        // standard ApiSecurityListener handles it.
        $this->guard()->onKernelController(
            $this->event('/cms-api/v1/admin/pages', 'admin_pages_get_all_v1', 'GET', ['id_users' => 1]),
        );
        $this->expectNotToPerformAssertions();
    }

    public function testIgnoresRequestWithoutJwtPayload(): void
    {
        $this->guard()->onKernelController(
            $this->event('/cms-api/v1/mobile-preview/session/exchange', 'mobile_preview_session_exchange', 'POST', null),
        );
        $this->expectNotToPerformAssertions();
    }

    public function testIgnoresNonApiTraffic(): void
    {
        $this->guard()->onKernelController(
            $this->event('/some/other/path', 'app_other', 'GET', self::PREVIEW_PAYLOAD),
        );
        $this->expectNotToPerformAssertions();
    }
}
