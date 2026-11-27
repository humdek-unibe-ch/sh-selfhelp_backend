<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace Humdek\QaLiveBundle\Controller;

use App\Exception\ServiceException;
use App\Plugin\Users\PluginRecordWriterInterface;
use App\Plugin\Users\UserNotManagedException;
use App\Service\Core\ApiResponseFormatter;
use Humdek\QaLiveBundle\QaLiveCatalogue;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class QaLiveWriteForController
{
    public function __construct(
        private readonly PluginRecordWriterInterface $writer,
        private readonly ApiResponseFormatter $responses,
    ) {
    }

    public function writeFor(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $ownerRaw = $body['owner_user_id'] ?? null;
        $ownerUserId = is_numeric($ownerRaw) ? (int) $ownerRaw : 0;
        $messageRaw = $body['message'] ?? null;
        $message = is_scalar($messageRaw) ? trim((string) $messageRaw) : '';
        if ($ownerUserId < 1 || $message === '') {
            return new JsonResponse(['error' => 'owner_user_id and message are required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $actorUserId = $this->userId($request);
        $contentRef = isset($body['content_ref']) && is_string($body['content_ref']) ? $body['content_ref'] : null;
        $table = isset($body['table']) && is_string($body['table']) && str_starts_with($body['table'], 'qa_live_')
            ? $body['table']
            : 'qa_live_replies';
        $data = ['message' => $message];
        if (isset($body['trigger_type']) && is_string($body['trigger_type']) && $body['trigger_type'] !== '') {
            $data['trigger_type'] = $body['trigger_type'];
        }
        $recordIdArg = isset($body['record_id']) && is_numeric($body['record_id']) ? (int) $body['record_id'] : null;
        if ($recordIdArg !== null && $recordIdArg < 1) {
            $recordIdArg = null;
        }

        try {
            $recordId = $this->writer->saveFor(
                QaLiveCatalogue::PLUGIN_ID,
                $ownerUserId,
                $actorUserId,
                $table,
                $data,
                $contentRef,
                $recordIdArg,
            );
        } catch (UserNotManagedException) {
            return new JsonResponse(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        } catch (ServiceException $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) {
                $status = Response::HTTP_BAD_REQUEST;
            }

            return new JsonResponse(['error' => $e->getMessage()], $status);
        }

        return $this->responses->formatSuccess(['record_id' => $recordId], null, Response::HTTP_CREATED);
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
