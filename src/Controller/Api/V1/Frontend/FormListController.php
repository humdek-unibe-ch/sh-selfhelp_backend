<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Controller\Api\V1\Frontend;

use App\Entity\Language;
use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\RequestValidationException;
use App\Exception\ServiceException;
use App\Service\CMS\FormList\FormListBoundary;
use App\Service\CMS\FormList\FormListContextResolver;
use App\Service\CMS\FormList\FormListCurrentFileService;
use App\Service\CMS\FormList\FormListHistoryService;
use App\Service\CMS\FormList\FormListQuickActionService;
use App\Service\CMS\FormList\FormListStateService;
use App\Service\CMS\FormList\FormListUrlStateParser;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * The server-rendered state of a list and the actions around it:
 *
 *   GET  /cms-api/v1/lists/{section_id}/state
 *   POST /cms-api/v1/lists/{section_id}/records/{record_id}/actions/{action_id}
 *   GET  /cms-api/v1/lists/{section_id}/records/{record_id}/history
 *   GET  /cms-api/v1/lists/{section_id}/records/{record_id}/versions/{version}
 *   GET  /cms-api/v1/lists/{section_id}/records/{record_id}/compare
 *   POST /cms-api/v1/lists/{section_id}/records/{record_id}/restore
 *   GET  /cms-api/v1/lists/{section_id}/records/{record_id}/versions/{version}/files/{field_key}
 *   GET  /cms-api/v1/lists/{section_id}/records/{record_id}/files/{field_key}
 *
 * Creating, editing and deleting a record go through the ordinary form
 * endpoints, which ask the list for its rules (see FormListGuard).
 *
 * Like the form endpoints these routes carry no route permission: access is
 * decided per request. The page must be readable by the caller (its `select`
 * right: groups see), the section must sit on it and be visible, and every
 * record is read inside the list's dataset. What the caller may do with a
 * record is decided by the list's operation switches and the data table's grants.
 * The list is read from the version of the page the visitor is served (the
 * published one; the draft only for `preview=1` of a signed-in user).
 * Routes are DB-defined (see the seed migration of the list styles).
 */
class FormListController extends AbstractController
{
    use RequestValidatorTrait;

    /** A route has a handful of placeholders; more is not a route of this site. */
    private const MAX_ROUTE_PARAMETERS = 8;

    public function __construct(
        private readonly FormListContextResolver $resolver,
        private readonly FormListUrlStateParser $parser,
        private readonly FormListStateService $stateService,
        private readonly FormListQuickActionService $quickActions,
        private readonly FormListHistoryService $history,
        private readonly FormListCurrentFileService $currentFiles,
        private readonly FormListBoundary $boundary,
        private readonly ApiResponseFormatter $responses,
        private readonly JsonSchemaValidationService $schemas,
    ) {
    }

    /**
     * Query: `page_id` (required), `language_id`, `route[<name>]` (the route parameters of
     * the page the client shows: `/team/7` sends `route[record_id]=7`; a layout of one record can
     * read them as `{{route.record_id}}`; the server believes them only when one of the page's
     * routes accepts exactly these values), and the list's own parameters (`<key>.page`,
     * `<key>.q`, `<key>.sort`, `<key>.f.<control>`, `<key>.record`, `<key>.mode`) as they stand in
     * the page URL.
     */
    public function state(Request $request, int $section_id): JsonResponse
    {
        try {
            $ctx = $this->resolver->resolve(
                $this->pageIdOf($request->query->all()),
                $section_id,
                $this->languageOf($request->query->all()),
                $this->platformOf($request),
                $this->previewOf($request->query->all()),
                $this->routeOf($request->query->all()),
            );
            // The raw query string, not PHP's array: PHP rewrites the dot in `list.page`.
            $queryString = $request->server->get('QUERY_STRING', '');
            $url = $this->parser->parse(is_string($queryString) ? $queryString : '', $ctx->config);

            return $this->personal($this->responses->formatSuccess(
                $this->stateService->state($ctx, $url),
                'responses/frontend/form_list_state',
                Response::HTTP_OK,
                false,
                true,
            ));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * Body: `page_id`, `expected_version` (the version the client last received).
     */
    public function quickAction(Request $request, int $section_id, int $record_id, string $action_id): JsonResponse
    {
        try {
            $body = $this->validateRequest($request, 'requests/frontend/list_quick_action', $this->schemas);
            $ctx = $this->resolver->resolve($this->pageIdOf($body), $section_id, null, $this->platformOf($request), $this->previewOf($body));
            $result = $this->quickActions->run($ctx, $action_id, $record_id, $this->expectedVersionOf($body, true) ?? 0);

            return $this->personal($this->responses->formatSuccess($result, 'responses/frontend/form_list_action'));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * Query: `page_id`, `page`, `page_size`.
     */
    public function history(Request $request, int $section_id, int $record_id): JsonResponse
    {
        try {
            $query = $request->query->all();
            $ctx = $this->resolver->resolve($this->pageIdOf($query), $section_id, $this->languageOf($query), $this->platformOf($request), $this->previewOf($query));
            $result = $this->history->versions(
                $ctx,
                $record_id,
                $this->optionalInt($query, 'page', 1),
                $this->optionalInt($query, 'page_size', 20),
            );

            return $this->personal($this->responses->formatSuccess($result, 'responses/frontend/form_list_history', Response::HTTP_OK, false, true));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * Query: `page_id`.
     */
    public function version(Request $request, int $section_id, int $record_id, int $version): JsonResponse
    {
        try {
            $query = $request->query->all();
            $ctx = $this->resolver->resolve($this->pageIdOf($query), $section_id, $this->languageOf($query), $this->platformOf($request), $this->previewOf($query));

            return $this->personal($this->responses->formatSuccess(
                $this->history->version($ctx, $record_id, $version),
                'responses/frontend/form_list_version',
                Response::HTTP_OK,
                false,
                true,
            ));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * Query: `page_id`, `from`, `to` (versions).
     */
    public function compare(Request $request, int $section_id, int $record_id): JsonResponse
    {
        try {
            $query = $request->query->all();
            $ctx = $this->resolver->resolve($this->pageIdOf($query), $section_id, $this->languageOf($query), $this->platformOf($request), $this->previewOf($query));
            $from = $this->optionalInt($query, 'from', 0);
            $to = $this->optionalInt($query, 'to', 0);
            if ($from < 1 || $to < 1) {
                throw new ServiceException('`from` and `to` are version numbers.', Response::HTTP_BAD_REQUEST);
            }

            return $this->personal($this->responses->formatSuccess(
                $this->history->compare($ctx, $record_id, $from, $to),
                'responses/frontend/form_list_compare',
                Response::HTTP_OK,
                false,
                true,
            ));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * Body: `page_id`, `version`, `expected_version` (required unless `dry_run`),
     * `field_keys` (restore only these fields), `dry_run`.
     */
    public function restore(Request $request, int $section_id, int $record_id): JsonResponse
    {
        try {
            $body = $this->validateRequest($request, 'requests/frontend/list_restore', $this->schemas);
            $ctx = $this->resolver->resolve($this->pageIdOf($body), $section_id, null, $this->platformOf($request), $this->previewOf($body));

            $fieldKeys = null;
            if (is_array($body['field_keys'] ?? null)) {
                $fieldKeys = array_values(array_filter($body['field_keys'], 'is_string'));
            }
            $result = $this->history->restore(
                $ctx,
                $record_id,
                $this->intOf($body, 'version'),
                $fieldKeys,
                $this->expectedVersionOf($body, ($body['dry_run'] ?? false) !== true),
                ($body['dry_run'] ?? false) === true,
            );

            return $this->personal($this->responses->formatSuccess($result, 'responses/frontend/form_list_restore', Response::HTTP_OK, false, true));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * An uploaded file of an old version. Query: `page_id`, `file_language`
     * (the language of the stored value, default 1), `index` (position in a
     * multi-file field, default 0). Access is the history access of the record;
     * a version's file is never public.
     */
    public function versionFile(Request $request, int $section_id, int $record_id, int $version, string $field_key): Response
    {
        try {
            $query = $request->query->all();
            $ctx = $this->resolver->resolve($this->pageIdOf($query), $section_id, $this->languageOf($query), $this->platformOf($request), $this->previewOf($query));
            $file = $this->history->file(
                $ctx,
                $record_id,
                $version,
                $field_key,
                $this->optionalInt($query, 'file_language', Language::INDEPENDENT_ID),
                $this->optionalInt($query, 'index', 0),
            );

            $response = new BinaryFileResponse($file['absolutePath']);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file['name']);
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");

            return $response;
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * The current file of a record shown by this list. Query: `page_id`,
     * `index` (default 0), `language_id`, `preview`. Access follows the page
     * and the list boundary (F165); never reveals a hidden page or record.
     */
    public function currentFile(Request $request, int $section_id, int $record_id, string $field_key): Response
    {
        try {
            $query = $request->query->all();
            $ctx = $this->resolver->resolve(
                $this->pageIdOf($query),
                $section_id,
                $this->languageOf($query),
                $this->platformOf($request),
                $this->previewOf($query),
                $this->routeOf($query),
            );
            $row = $this->boundary->find($ctx, $record_id);
            if ($row === null) {
                throw new ServiceException('File not found', Response::HTTP_NOT_FOUND);
            }
            $file = $this->currentFiles->fileFromRow(
                $ctx,
                $row,
                $field_key,
                $this->optionalInt($query, 'index', 0),
            );

            $response = new BinaryFileResponse($file['absolutePath']);
            $response->setContentDisposition($file['disposition'], $file['name']);
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");

            return $response;
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    // -- request values ---------------------------------------------------------------------

    /**
     * `route[<name>]=<value>`: the route parameters of the page the client shows.
     *
     * @param array<array-key, mixed> $query
     * @return array<string, string>
     */
    private function routeOf(array $query): array
    {
        $given = $query['route'] ?? null;
        if ($given === null) {
            return [];
        }
        if (!is_array($given) || count($given) > self::MAX_ROUTE_PARAMETERS) {
            throw $this->invalidRoute();
        }

        $route = [];
        foreach ($given as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1 || !is_string($value) || $value === '' || strlen($value) > 255) {
                throw $this->invalidRoute();
            }
            $route[$name] = $value;
        }

        return $route;
    }

    private function invalidRoute(): ServiceException
    {
        return new ServiceException(
            '`route` must name route parameters with text values, like route[record_id]=7.',
            Response::HTTP_BAD_REQUEST,
            ['reason' => 'invalid_route', 'parameter' => 'route'],
        );
    }

    /**
     * @param array<array-key, mixed> $source query or body
     */
    private function pageIdOf(array $source): int
    {
        $value = $source['page_id'] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new ServiceException('`page_id` is required.', Response::HTTP_BAD_REQUEST);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private function languageOf(array $query): ?int
    {
        $value = $query['language_id'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new ServiceException('`language_id` is a language id.', Response::HTTP_BAD_REQUEST);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private function optionalInt(array $query, string $name, int $default): int
    {
        $value = $query[$name] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_string($value) && ctype_digit($value) && strlen($value) < 10) {
            return (int) $value;
        }

        throw new ServiceException(sprintf('`%s` is a whole number.', $name), Response::HTTP_BAD_REQUEST);
    }

    /**
     * @param array<array-key, mixed> $body a body the request schema has validated
     */
    private function intOf(array $body, string $name): int
    {
        $value = $body[$name] ?? null;

        return is_int($value) ? $value : 0;
    }

    /**
     * The version the person saw. Required for everything that writes; a value that is present but is not a whole
     * number of 1 or more is refused instead of being read as "none", so it cannot skip the conflict check.
     *
     * @param array<array-key, mixed> $body a body the request schema has validated
     * @return int|null the version; null only when it is not required and absent
     * @throws ServiceException 400
     */
    private function expectedVersionOf(array $body, bool $required): ?int
    {
        $value = $body['expected_version'] ?? null;
        if ($value === null) {
            if ($required) {
                throw new ServiceException(
                    'The version of the entry you are changing is required (expected_version).',
                    Response::HTTP_BAD_REQUEST,
                    ['reason' => 'expected_version_required'],
                );
            }

            return null;
        }
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        throw new ServiceException('expected_version must be a whole number of 1 or more.', Response::HTTP_BAD_REQUEST, ['reason' => 'invalid_expected_version']);
    }

    /**
     * `preview` asks for the draft of the page instead of the published version; honoured for a signed-in user only.
     *
     * @param array<array-key, mixed> $source query or body
     */
    private function previewOf(array $source): bool
    {
        $value = $source['preview'] ?? null;

        return $value === true || $value === 1 || (is_string($value) && in_array(strtolower($value), ['1', 'true'], true));
    }

    /**
     * The platform the caller renders for, as the page endpoints read it:
     * `X-Client-Type` header, then `?platform`, then the legacy `?mobile` flag.
     */
    private function platformOf(Request $request): string
    {
        $candidates = [$request->headers->get('X-Client-Type'), $request->query->get('platform')];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $normalised = strtolower($candidate);
            if (in_array($normalised, [
                LookupService::PAGE_ACCESS_TYPES_MOBILE,
                LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB,
                LookupService::PAGE_ACCESS_TYPES_WEB,
            ], true)) {
                return $normalised;
            }
        }

        return $request->query->get('mobile') ? LookupService::PAGE_ACCESS_TYPES_MOBILE : LookupService::PAGE_ACCESS_TYPES_WEB;
    }

    // -- responses --------------------------------------------------------------------------

    /** What a list shows belongs to the signed-in visitor: no shared cache may keep it. */
    private function personal(JsonResponse $response): JsonResponse
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private function fail(\Throwable $e): JsonResponse
    {
        if ($e instanceof RequestValidationException) {
            return $this->responses->formatError('Validation failed', Response::HTTP_BAD_REQUEST, null, $e->getValidationErrors());
        }
        if ($e instanceof \InvalidArgumentException) {
            return $this->responses->formatError($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
        if ($e instanceof ServiceException) {
            return $this->responses->formatException($e);
        }

        return $this->responses->formatThrowable($e);
    }
}
