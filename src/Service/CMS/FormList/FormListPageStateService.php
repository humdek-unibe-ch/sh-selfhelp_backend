<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Exception\ServiceException;
use App\Service\CMS\Common\StyleNames;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the server-rendered state of every list of a page into the page payload,
 * so the first paint of a list is part of the page itself: the web server render
 * and the native screen show rows without a second request, and a bookmarked
 * URL (`/news?news.q=sleep&news.page=2`) is already in the state.
 *
 * The state is added to the payload that leaves the page endpoints, after the
 * page cache: the cached page holds the configuration only and is shared, while
 * the rows depend on the visitor, the URL and the data. The list's own cache
 * ({@see FormListStateService}) keeps the read cheap and fresh.
 *
 * A list that cannot be rendered does not take the page down. The section gets
 * a `list_error` (sign in, not allowed, a malformed list parameter, a broken
 * configuration) and the client draws the list's error state.
 */
class FormListPageStateService
{
    /**
     * What a list section carries for the server only: the table, the dataset boundary, the switches, the
     * columns, the controls and the quick actions, as the author stored them. The client draws from the list
     * state (`list_state`), so none of it leaves the server: it tells a visitor how the data is queried and
     * which values the author's filters and actions hold. Kept only for an author who switched `debug` on and
     * asked for the draft as a signed-in user (the page inspector).
     *
     * `list_key`, `title`, `form_presentation` and `show_history` stay: they are what the renderers read from the section.
     */
    public const SERVER_ONLY_FIELDS = [
        'data_table', 'own_entries_only', 'allow_create', 'allow_update', 'allow_delete',
        'list_layout', 'list_row_css', 'page_size', 'list_columns', 'list_sort', 'list_controls',
        'list_fixed_filter', 'quick_actions', 'detail_url', 'data_config',
    ];

    public function __construct(
        private readonly FormListContextResolver $resolver,
        private readonly FormListUrlStateParser $parser,
        private readonly FormListStateService $stateService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Whether the page payload holds a list: the page then belongs to the visitor
     * and must not be kept by a shared cache.
     *
     * @param array<string, mixed> $pageData the page endpoint's payload (`page.sections`)
     */
    public function containsLists(array $pageData): bool
    {
        $page = $pageData['page'] ?? null;

        return is_array($page) && is_array($page['sections'] ?? null) && $this->anyList($page['sections']);
    }

    /**
     * @param array<string, mixed> $pageData the page endpoint's payload
     * @param string $queryString the raw query string of the page request (the list parameters are in it)
     * @param int|null $languageId the language the page was requested in
     * @param string|null $platform `web` or `mobile`
     * @param bool $preview the draft of the page was requested (a signed-in user); the lists are then read from the draft too
     * @return array<string, mixed> the payload with `list_state` or `list_error` on every list section
     */
    public function withListStates(array $pageData, string $queryString, ?int $languageId, ?string $platform, bool $preview = false): array
    {
        $page = $pageData['page'] ?? null;
        if (!is_array($page) || !is_array($page['sections'] ?? null) || !$this->anyList($page['sections'])) {
            return $pageData;
        }
        $pageId = is_numeric($page['id'] ?? null) ? (int) $page['id'] : 0;
        if ($pageId < 1) {
            return $pageData;
        }

        // The route the page was resolved with ({{route.*}}): the layout of one record of a data view reads it.
        $route = [];
        if (is_array($page['route_params'] ?? null)) {
            foreach ($page['route_params'] as $name => $value) {
                if (is_string($name) && is_scalar($value)) {
                    $route[$name] = (string) $value;
                }
            }
        }

        $page['sections'] = $this->decorate($page['sections'], $pageId, $queryString, $languageId, $platform, $preview, $route);
        $pageData['page'] = $page;

        return $pageData;
    }

    /**
     * @param array<array-key, mixed> $sections
     * @param array<string, string> $route
     * @return list<array<string, mixed>>
     */
    private function decorate(array $sections, int $pageId, string $queryString, ?int $languageId, ?string $platform, bool $preview, array $route): array
    {
        $result = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            /** @var array<string, mixed> $section */
            if (in_array($section['style_name'] ?? null, StyleNames::LIST_STYLE_NAMES, true) && is_numeric($section['id'] ?? null)) {
                $section = $this->withState($section, (int) $section['id'], $pageId, $queryString, $languageId, $platform, $preview, $route);
                $section = $this->withoutServerFields($section, $preview);
            }
            if (($section['style_name'] ?? null) === StyleNames::STYLE_DATA_VIEW) {
                // The children of a data view are the template of one record: the list state
                // already holds them rendered per record, the page does not carry them.
                $section['children'] = [];
            }
            if (is_array($section['children'] ?? null)) {
                $section['children'] = $this->decorate($section['children'], $pageId, $queryString, $languageId, $platform, $preview, $route);
            }
            $result[] = $section;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $section
     * @param array<string, string> $route
     * @return array<string, mixed>
     */
    private function withState(array $section, int $sectionId, int $pageId, string $queryString, ?int $languageId, ?string $platform, bool $preview, array $route): array
    {
        try {
            $ctx = $this->resolver->resolve($pageId, $sectionId, $languageId, $platform, $preview, $route);
            $url = $this->parser->parse($queryString, $ctx->config);
            $section['list_state'] = $this->stateService->state($ctx, $url);
        } catch (ServiceException $e) {
            $section['list_error'] = $this->errorOf($e);
        } catch (\Throwable $e) {
            $this->logger->error('A list could not be rendered into its page.', ['section' => $sectionId, 'page' => $pageId, 'exception' => $e]);
            $section['list_error'] = ['status' => Response::HTTP_INTERNAL_SERVER_ERROR, 'message' => 'This list could not be loaded.', 'reason' => null, 'parameter' => null];
        }

        return $section;
    }

    /**
     * Take the server-only configuration of a list out of the section that leaves the server (see {@see self::SERVER_ONLY_FIELDS}).
     *
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private function withoutServerFields(array $section, bool $preview): array
    {
        if ($preview && !empty($section['debug'])) {
            return $section;
        }

        foreach (self::SERVER_ONLY_FIELDS as $field) {
            unset($section[$field]);
        }
        // A published section may still carry its stored translations next to the fields read from them.
        if (is_array($section['translations'] ?? null)) {
            foreach ($section['translations'] as $languageId => $byField) {
                if (!is_array($byField)) {
                    continue;
                }
                foreach (self::SERVER_ONLY_FIELDS as $field) {
                    unset($byField[$field]);
                }
                $section['translations'][$languageId] = $byField;
            }
        }

        return $section;
    }

    /**
     * @return array{status: int, message: string, reason: string|null, parameter: string|null}
     */
    private function errorOf(ServiceException $e): array
    {
        $data = $e->getData() ?? [];
        $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        $reason = $data['reason'] ?? null;
        $parameter = $data['parameter'] ?? null;
        if ($status === Response::HTTP_UNAUTHORIZED && $reason === null) {
            $reason = 'login_required';
        }

        return [
            'status' => $status,
            'message' => $e->getMessage(),
            'reason' => is_string($reason) ? $reason : null,
            'parameter' => is_string($parameter) ? $parameter : null,
        ];
    }

    /**
     * @param array<array-key, mixed> $sections
     */
    private function anyList(array $sections): bool
    {
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (in_array($section['style_name'] ?? null, StyleNames::LIST_STYLE_NAMES, true)) {
                return true;
            }
            if (is_array($section['children'] ?? null) && $this->anyList($section['children'])) {
                return true;
            }
        }

        return false;
    }
}
