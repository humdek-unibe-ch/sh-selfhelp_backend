<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace Humdek\QaLiveBundle\Controller;

use App\Plugin\Resource\PluginResourceServiceInterface;
use App\Service\Core\ApiResponseFormatter;
use Humdek\QaLiveBundle\QaLiveCatalogue;
use Humdek\QaLiveBundle\Service\QaLiveItemService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class QaLiveAdminController
{
    public function __construct(
        private readonly QaLiveItemService $items,
        private readonly ApiResponseFormatter $responses,
        private readonly PluginResourceServiceInterface $resources,
    ) {
    }

    public function list(Request $request): JsonResponse
    {
        $all = $this->items->listAll();
        $userId = $this->userId($request);
        if ($userId < 1) {
            return $this->responses->formatSuccess(['items' => []]);
        }

        $ids = array_map(static fn (array $row): int => $row['id'], $all);
        $rights = $this->resources->rightsFor(QaLiveCatalogue::TYPE_CODE, $ids, $userId);
        $visible = array_values(array_filter(
            $all,
            static fn (array $row): bool => ($rights[$row['id']]['can_view'] ?? false) === true,
        ));

        return $this->responses->formatSuccess(['items' => $visible]);
    }

    public function create(Request $request): JsonResponse
    {
        $body = $this->decode($request);
        $titleRaw = $body['title'] ?? null;
        $title = is_scalar($titleRaw) ? trim((string) $titleRaw) : '';
        if ($title === '') {
            return new JsonResponse(['error' => 'title is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $item = $this->items->create($title, $this->userId($request));
        $id = $item->getId();

        return $this->responses->formatSuccess([
            'id' => $id,
            'title' => $item->getTitle(),
            'published_version' => $item->getPublishedVersion(),
        ], null, Response::HTTP_CREATED);
    }

    public function updateTitle(int $id, Request $request): JsonResponse
    {
        $body = $this->decode($request);
        $titleRaw = $body['title'] ?? null;
        $title = is_scalar($titleRaw) ? trim((string) $titleRaw) : '';
        if ($title === '') {
            return new JsonResponse(['error' => 'title is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $item = $this->items->updateTitle($id, $title);
        if ($item === null) {
            return new JsonResponse(['error' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->responses->formatSuccess([
            'id' => $id,
            'title' => $item->getTitle(),
            'published_version' => $item->getPublishedVersion(),
        ]);
    }

    public function publish(int $id): JsonResponse
    {
        $item = $this->items->publish($id);
        if ($item === null) {
            return new JsonResponse(['error' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->responses->formatSuccess([
            'id' => $id,
            'title' => $item->getTitle(),
            'published_version' => $item->getPublishedVersion(),
        ]);
    }

    public function delete(int $id): JsonResponse
    {
        if (!$this->items->delete($id)) {
            return new JsonResponse(['error' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->responses->formatSuccess(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function decode(Request $request): array
    {
        $raw = json_decode($request->getContent(), true);
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function userId(Request $request): int
    {
        $payload = $request->attributes->get('_jwt_payload');
        if (is_array($payload) && isset($payload['id_users']) && is_numeric($payload['id_users'])) {
            return (int) $payload['id_users'];
        }

        return 0;
    }
}
