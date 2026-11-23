<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Test client that adds the page's current `X-Page-Version` to every write to
 * `/admin/pages/{id}/...` (live-sync contract C8: a draft write must name the
 * version it is based on).
 *
 * A test that is not about the version then keeps working without knowing the
 * version. A test that sends the header itself keeps its value; a test that wants
 * to send none (the missing-header answer) adds the server parameter
 * {@see NO_PAGE_VERSION}.
 */
final class PageVersionAwareBrowser extends KernelBrowser
{
    /** Server parameter that tells this client not to add `X-Page-Version`. */
    public const NO_PAGE_VERSION = 'NO_PAGE_VERSION';

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function request(
        string $method,
        string $uri,
        array $parameters = [],
        array $files = [],
        array $server = [],
        ?string $content = null,
        bool $changeHistory = true,
    ): Crawler {
        if (isset($server[self::NO_PAGE_VERSION])) {
            unset($server[self::NO_PAGE_VERSION]);
        } elseif (!isset($server['HTTP_X_PAGE_VERSION'])) {
            $version = $this->currentVersion($method, $uri);
            if ($version !== null) {
                $server['HTTP_X_PAGE_VERSION'] = (string) $version;
            }
        }

        return parent::request($method, $uri, $parameters, $files, $server, $content, $changeHistory);
    }

    private function currentVersion(string $method, string $uri): ?int
    {
        if (strtoupper($method) === 'GET' || preg_match('#/admin/pages/(\d+)(?:/|$|\?)#', $uri, $match) !== 1) {
            return null;
        }

        $em = $this->getContainer()->get(EntityManagerInterface::class);
        if (!$em instanceof EntityManagerInterface) {
            return null;
        }
        $version = $em->getConnection()->fetchOne('SELECT draft_version FROM pages WHERE id = ?', [(int) $match[1]]);

        return is_numeric($version) ? (int) $version : null;
    }
}
