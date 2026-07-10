<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS\Frontend;

use App\Service\Core\LookupService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Records anonymous page-view analytics into the `page_views` /
 * `page_view_referrers` daily aggregates.
 *
 * Privacy model: the visitor key is a daily rotating HMAC — authenticated
 * users hash their user id, guests hash IP + User-Agent, both keyed with the
 * app secret plus the UTC date. No IP, UA, or user id is ever stored, and the
 * hash cannot be correlated across days.
 *
 * Recording is fire-and-forget: failures are logged and never break page
 * delivery. Live-preview requests are excluded by the caller.
 */
class PageViewTrackerService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LoggerInterface $logger,
        private readonly string $appSecret,
    ) {
    }

    /**
     * Record one page view. `$mode` is the resolved page-access mode from the
     * request (web | mobile | mobile_and_web); anything non-mobile counts as web.
     */
    public function recordView(int $pageId, string $mode, Request $request): void
    {
        try {
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
            $platform = $mode === LookupService::PAGE_ACCESS_TYPES_MOBILE ? 'mobile' : 'web';
            $visitorHash = $this->buildVisitorHash($request, $today);

            $this->connection->executeStatement(
                'INSERT INTO page_views (view_date, id_pages, platform, visitor_hash, views) VALUES (?, ?, ?, ?, 1) '
                . 'ON DUPLICATE KEY UPDATE views = views + 1',
                [$today, $pageId, $platform, $visitorHash],
            );

            $referrerHost = $this->resolveExternalReferrerHost($request);
            if ($referrerHost !== null) {
                $this->connection->executeStatement(
                    'INSERT INTO page_view_referrers (view_date, referrer_host, views) VALUES (?, ?, 1) '
                    . 'ON DUPLICATE KEY UPDATE views = views + 1',
                    [$today, $referrerHost],
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Page view tracking failed: ' . $e->getMessage(), ['page_id' => $pageId]);
        }
    }

    /**
     * Daily rotating anonymous fingerprint: user id when authenticated,
     * IP + User-Agent otherwise, HMAC-keyed with app secret + date.
     */
    private function buildVisitorHash(Request $request, string $today): string
    {
        $subject = null;
        $token = $this->tokenStorage->getToken();
        if ($token !== null) {
            $user = $token->getUser();
            if ($user instanceof UserInterface && method_exists($user, 'getId')) {
                $id = $user->getId();
                if (is_numeric($id)) {
                    $subject = 'u:' . (int) $id;
                }
            }
        }

        if ($subject === null) {
            $subject = 'g:' . ($request->getClientIp() ?? '0.0.0.0') . '|' . (string) $request->headers->get('User-Agent', '');
        }

        return substr(hash_hmac('sha256', $subject, $this->appSecret . '|' . $today), 0, 32);
    }

    /**
     * Validated external referrer host forwarded by the web client via the
     * `X-Referrer-Host` header. Returns null for missing/invalid/own-host values.
     */
    private function resolveExternalReferrerHost(Request $request): ?string
    {
        $raw = $request->headers->get('X-Referrer-Host');
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $host = strtolower(trim($raw));
        if (strlen($host) > 190 || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        // Ignore self-referrals (the API host or its frontend siblings).
        if ($host === strtolower($request->getHost())) {
            return null;
        }

        return $host;
    }
}
