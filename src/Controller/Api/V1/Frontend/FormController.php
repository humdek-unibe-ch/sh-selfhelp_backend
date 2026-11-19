<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Frontend;

use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\RequestValidationException;
use App\Exception\ServiceException;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\CMS\Effective\FormInputValidator;
use App\Service\CMS\Effective\FormWriteScope;
use App\Service\CMS\FormFileUploadService;
use App\Service\CMS\FormList\FormListBoundary;
use App\Service\CMS\FormList\FormListGuard;
use App\Service\CMS\FormValidationService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\Auth\UserContextService;
use App\Service\Security\DataAccessSecurityService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for public form data submissions.
 *
 * Routes are DB-defined (loaded by {@see \App\Routing\ApiRouteLoader}), not PHP
 * attributes — see the `form_submit` / `form_update` / `form_delete` rows in
 * the seeded `api_routes` table (POST/PUT/DELETE /cms-api/v1/forms/*).
 *
 * A form request is untrusted. Every write goes through the same steps, in this order, and
 * a refusal at any step happens before an upload is stored, a row is written or an action is scheduled:
 *
 *  1. the request is read strictly (ids, `expected_version`, `preview`; a multipart request carries text);
 *  2. the write is authorized against the effective page ({@see FormValidationService::authorize()}):
 *     the page is visible, the section is on it, the list it sits in offers the operation;
 *  3. the payload is checked against the inputs of the effective form ({@see FormInputValidator}): it can set the value
 *     of an input the person may change and nothing else, never the owner or any other row metadata;
 *  4. the record an update or a delete targets is bound to the section's table, to the person (ownership,
 *     table grants) and to the list's dataset; the same checks run again under the record lock;
 *  5. only then are the uploads stored and the record written; an upload whose record was not written is removed.
 *
 * An update or a delete always names the record version the person saw (`expected_version`): a request without
 * it, or with a malformed one, is refused with 400, and a stale one with 409.
 */
class FormController extends AbstractController
{
    use RequestValidatorTrait;

    /** Control fields of a multipart request: never a value of the form. */
    private const MULTIPART_CONTROL_KEYS = ['page_id', 'section_id', '__id_sections', 'preview'];

    public function __construct(
        private readonly DataService $dataService,
        private readonly FormValidationService $formValidationService,
        private readonly FormFileUploadService $formFileUploadService,
        private readonly FormInputValidator $inputValidator,
        private readonly FormListBoundary $listBoundary,
        private readonly ApiResponseFormatter $apiResponseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
        private readonly UserContextService $userContextService,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Submit form data
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function submitForm(Request $request): JsonResponse
    {
        try {
            $isJson = $this->isJsonRequest($request);
            if ($isJson) {
                $requestData = $this->validateRequest($request, 'requests/frontend/submit_form', $this->jsonSchemaValidationService);
                $pageId = $this->asIntField($requestData, 'page_id');
                $sectionId = $this->asIntField($requestData, 'section_id');
                $formData = $this->asArrayField($requestData, 'form_data');
                $preview = ($requestData['preview'] ?? false) === true;
            } else {
                $pageId = $this->idOf($request->request->all()['page_id'] ?? null);
                $sectionId = $this->idOf($request->request->all()['section_id'] ?? null);
                $formData = $this->multipartFormData($request);
                $preview = $this->flagOf($request->request->all()['preview'] ?? null);
            }

            // Determine if user is authenticated
            $currentUser = $this->userContextService->getCurrentUser();
            $isAuthenticated = $currentUser !== null;
            // Anonymous uploads are namespaced under the guest sentinel, never
            // the admin id 1. The saved record's owner is set to NULL by DataService.
            $userId = $currentUser ? (int) $currentUser->getId() : UserContextService::GUEST_USER_ID;

            // The page, the section and the operation, then the payload: nothing is stored before both hold.
            $scope = $this->formValidationService->authorize(FormListGuard::OP_CREATE, $pageId, $sectionId, $preview);
            $formData = $this->normalizeFormData($formData, !$isJson);
            $uploads = $this->uploadsOf($request);
            $this->formValidationService->assertPayloadShape($formData, $uploads !== []);
            $accepted = $this->inputValidator->accept($this->formOf($scope), $formData, $uploads, true);

            $stored = [];
            try {
                $data = $this->withStoredFiles($accepted['data'], $accepted['files'], $scope, $userId, $sectionId, $stored);

                // Save form data using section ID as table name
                $recordId = $this->dataService->saveData(
                    (string) $sectionId,
                    $data,
                    LookupService::TRANSACTION_BY_BY_USER,
                    null,
                    true,
                    $accepted['labels'],
                    RecordWriteContext::interactive(LookupService::DATA_REVISION_SOURCES_FORM, null),
                );
            } catch (\Throwable $e) {
                $this->discardStoredFiles($stored);
                throw $e;
            }

            if ($recordId === false) {
                $this->discardStoredFiles($stored);

                return $this->apiResponseFormatter->formatError(
                    'Failed to save form data',
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }

            // Prepare response data
            $responseData = [
                'record_id' => $recordId,
                'section_id' => $sectionId,
                'page_id' => $pageId,
                'version' => $this->dataService->getRecordVersion($recordId),
                'submitted_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'user_authenticated' => $isAuthenticated
            ];

            return $this->apiResponseFormatter->formatSuccess(
                $responseData,
                'responses/frontend/form_submitted'
            );

        } catch (ServiceException $e) {
            return $this->apiResponseFormatter->formatException($e);
        } catch (RequestValidationException $e) {
            // A body that breaks its request schema is the caller's mistake (400), never a server error: the
            // API exception listener builds the standard answer with the list of errors.
            throw $e;
        } catch (\Exception $e) {
            return $this->apiResponseFormatter->formatThrowable(
                $e,
                is_string($request->attributes->get('_route')) ? $request->attributes->get('_route') : null
            );
        }
    }

    /**
     * Update form data (for authenticated users only)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function updateForm(Request $request): JsonResponse
    {
        try {
            // Check authentication
            $currentUser = $this->userContextService->getCurrentUser();
            if (!$currentUser) {
                return $this->apiResponseFormatter->formatError(
                    'Authentication required',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            // Read the request strictly: the version and the target are required, and a malformed value
            // is refused here, before anything else is looked at.
            $isJson = $this->isJsonRequest($request);
            if ($isJson) {
                $requestData = $this->validateRequest($request, 'requests/frontend/update_form', $this->jsonSchemaValidationService);
                $pageId = $this->asIntField($requestData, 'page_id');
                $sectionId = $this->asIntField($requestData, 'section_id');
                $formData = $this->asArrayField($requestData, 'form_data');
                $updateBasedOn = $this->asNullableArrayField($requestData, 'update_based_on');
                $expectedVersion = $this->expectedVersionOf($requestData['expected_version'] ?? null);
                $preview = ($requestData['preview'] ?? false) === true;
            } else {
                $raw = $request->request->all();
                $pageId = $this->idOf($raw['page_id'] ?? null);
                $sectionId = $this->idOf($raw['section_id'] ?? null);
                $formData = $this->multipartFormData($request, ['update_based_on', 'expected_version']);
                // The record version the edit was based on (multipart sends it as text): digits only, no coercion.
                $expectedVersion = $this->expectedVersionOf($raw['expected_version'] ?? null);
                $preview = $this->flagOf($raw['preview'] ?? null);

                $updateBasedOn = null;
                if (array_key_exists('update_based_on', $raw)) {
                    $decoded = is_string($raw['update_based_on']) ? json_decode($raw['update_based_on'], true) : null;
                    if (!is_array($decoded)) {
                        throw new ServiceException('update_based_on must be a JSON object.', Response::HTTP_BAD_REQUEST, ['reason' => 'unsupported_target_selection']);
                    }
                    $updateBasedOn = [];
                    foreach ($decoded as $k => $v) {
                        $updateBasedOn[(string) $k] = $v;
                    }
                }
            }
            if ($updateBasedOn === null || $updateBasedOn === []) {
                // An update changes an entry it names; it never creates one.
                throw new ServiceException('An update names the entry it changes (update_based_on).', Response::HTTP_BAD_REQUEST, ['reason' => 'unsupported_target_selection']);
            }

            // The page, the section and the operation, then the payload.
            $scope = $this->formValidationService->authorize(FormListGuard::OP_UPDATE, $pageId, $sectionId, $preview);
            $formData = $this->normalizeFormData($formData, !$isJson);
            $uploads = $this->uploadsOf($request);
            $this->formValidationService->assertPayloadShape($formData, $uploads !== []);
            $accepted = $this->inputValidator->accept($this->formOf($scope), $formData, $uploads, false);
            $sectionOwnEntriesOnly = $scope->ownEntriesOnly;

            // The dataset of the list the form sits in: the same boundary the list shows, checked again under the lock.
            $listContext = $scope->listSectionId !== null
                ? $this->listBoundary->contextFor($scope->page, $scope->effective, $scope->listSectionId)
                : null;

            // Record edit mode rule (issue #30): when the update explicitly
            // targets a record id, users may always update their OWN record;
            // updating another user's record requires the section to opt out
            // of own_entries_only AND UPDATE permission on the form's data
            // table. The rule lives in canUpdateOwnedRecord so it stays in
            // lockstep with the form-record prefill loader
            // (DataService::getFormRecordDataForRecord).
            $effectiveOwnEntriesOnly = true;
            if (isset($updateBasedOn['record_id'])) {
                $targetRecordId = $this->recordIdOf($updateBasedOn['record_id']);
                $userId = (int) $currentUser->getId();
                $dataTable = $this->dataService->getDataTableByName((string) $sectionId);
                $dataTableId = $dataTable ? (int) $dataTable->getId() : 0;

                // F127: the record must live in THIS section's table. A record of
                // another table, a deleted record and a missing record all get the
                // same answer, so nothing is revealed or touched through a section
                // that is bound to a different table.
                $targetRow = $this->dataService->findRecordInTable($dataTableId, $targetRecordId);
                if ($targetRow === null) {
                    return $this->apiResponseFormatter->formatError(
                        'Record not found',
                        Response::HTTP_NOT_FOUND
                    );
                }

                $recordOwnerId = $targetRow->getIdUsers();
                $isOwnRecord = $recordOwnerId !== null && $recordOwnerId === $userId;

                if (!$isOwnRecord) {
                    // own_entries_only never exposes foreign records, so another
                    // owner looks like not found (same as delete and the list boundary).
                    if ($sectionOwnEntriesOnly) {
                        return $this->apiResponseFormatter->formatError(
                            'Record not found',
                            Response::HTTP_NOT_FOUND
                        );
                    }

                    $hasUpdatePermission = $dataTableId > 0 && $this->dataAccessSecurityService->hasPermission(
                        $userId,
                        'data_table',
                        $dataTableId,
                        DataAccessSecurityService::PERMISSION_UPDATE
                    );

                    // Shared sections defer to the centralized update rule.
                    if (!$this->dataAccessSecurityService->canUpdateOwnedRecord(false, $isOwnRecord, $hasUpdatePermission)) {
                        return $this->apiResponseFormatter->formatError(
                            'You do not have permission to update this entry.',
                            Response::HTTP_FORBIDDEN
                        );
                    }

                    // Permission granted: let DataService update the foreign record.
                    $effectiveOwnEntriesOnly = false;
                }

                // Someone who may change the entry still only reaches it through the dataset of the list: a record
                // outside it is as good as missing, for every door of the list.
                if ($listContext !== null && !$this->listBoundary->contains($listContext, $targetRecordId)) {
                    return $this->apiResponseFormatter->formatError(
                        'Record not found',
                        Response::HTTP_NOT_FOUND
                    );
                }
            }

            // The version the person saw is not optional; a save based on an older one stops here, before an
            // upload is stored (the record lock decides again where it cannot be raced).
            $expectedVersion = $this->requireVersion($expectedVersion);
            if (isset($targetRecordId)) {
                $this->assertVersionIsCurrent($targetRecordId, $expectedVersion);
            }

            $stored = [];
            try {
                $data = $this->withStoredFiles($accepted['data'], $accepted['files'], $scope, (int) $currentUser->getId(), $sectionId, $stored);
                $data = $this->withFileOps(
                    $data,
                    $accepted['file_ops'],
                    $accepted['files'],
                    $scope,
                    $targetRecordId ?? null,
                );

                // Update form data
                $recordId = $this->dataService->saveData(
                    (string) $sectionId,
                    $data,
                    LookupService::TRANSACTION_BY_BY_USER,
                    $updateBasedOn,
                    $effectiveOwnEntriesOnly,
                    $accepted['labels'],
                    RecordWriteContext::interactive(
                        LookupService::DATA_REVISION_SOURCES_FORM,
                        $expectedVersion,
                        $listContext !== null ? $this->listBoundary->guard($listContext) : null,
                    ),
                );
            } catch (\Throwable $e) {
                $this->discardStoredFiles($stored);
                throw $e;
            }

            if ($recordId === false) {
                $this->discardStoredFiles($stored);

                return $this->apiResponseFormatter->formatError(
                    'Failed to update form data',
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }

            // Prepare response data
            $responseData = [
                'record_id' => $recordId,
                'section_id' => $sectionId,
                'page_id' => $pageId,
                'version' => $this->dataService->getRecordVersion($recordId),
                'updated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
            ];

            return $this->apiResponseFormatter->formatSuccess(
                $responseData,
                'responses/frontend/form_updated'
            );

        } catch (ServiceException $e) {
            return $this->apiResponseFormatter->formatException($e);
        } catch (RequestValidationException $e) {
            // A body that breaks its request schema is the caller's mistake (400), never a server error: the
            // API exception listener builds the standard answer with the list of errors.
            throw $e;
        } catch (\Exception $e) {
            return $this->apiResponseFormatter->formatThrowable(
                $e,
                is_string($request->attributes->get('_route')) ? $request->attributes->get('_route') : null
            );
        }
    }

    /**
     * Delete form data (for authenticated users only)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteForm(Request $request): JsonResponse
    {
        try {
            // Check authentication
            $currentUser = $this->userContextService->getCurrentUser();
            if (!$currentUser) {
                return $this->apiResponseFormatter->formatError(
                    'Authentication required',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            // Validate request schema (DELETE with JSON body)
            $requestData = $this->validateRequest($request, 'requests/frontend/delete_form', $this->jsonSchemaValidationService);
            $recordId = $this->asIntField($requestData, 'record_id');
            $pageId = $this->asIntField($requestData, 'page_id');
            $sectionId = $this->asIntField($requestData, 'section_id');
            $expectedVersion = $this->expectedVersionOf($requestData['expected_version'] ?? null);
            $preview = ($requestData['preview'] ?? false) === true;

            // Validate that the section belongs to the page, is the right type, and the list it sits in offers deleting
            $validation = $this->formValidationService->authorize(FormListGuard::OP_DELETE, $pageId, $sectionId, $preview);
            $ownEntriesOnly = $validation->ownEntriesOnly;

            // F127: a delete action is bound to ONE data table and may only delete
            // a record of that table. An action that names no table cannot be
            // checked against a record, so it deletes nothing.
            $dataTableId = $validation->dataTableId ?? 0;
            if ($dataTableId <= 0) {
                return $this->apiResponseFormatter->formatError(
                    'This delete action is not bound to a data table.',
                    Response::HTTP_BAD_REQUEST
                );
            }

            // A record of another table, a deleted record and a missing record all
            // get the same answer.
            $targetRow = $this->dataService->findRecordInTable($dataTableId, $recordId);
            if ($targetRow === null) {
                return $this->apiResponseFormatter->formatError(
                    'Record not found',
                    Response::HTTP_NOT_FOUND
                );
            }

            // When own_entries_only=false the section shows all users' records.
            // Users may always delete their own records; deleting another user's
            // record requires DELETE permission on the data table. The rule lives
            // in DataAccessSecurityService::canDeleteOwnedRecord() so it stays in
            // lockstep with the entry-table renderer's per-row _can_delete flag.
            $userId = (int) $currentUser->getId();
            $recordOwnerId = $targetRow->getIdUsers();
            $isOwnRecord = $recordOwnerId !== null && $recordOwnerId === $userId;
            if ($ownEntriesOnly && !$isOwnRecord) {
                // Another owner looks like not found (same as outside the list boundary).
                return $this->apiResponseFormatter->formatError(
                    'Record not found',
                    Response::HTTP_NOT_FOUND
                );
            }
            if (!$ownEntriesOnly) {
                $hasDeletePermission = $this->dataAccessSecurityService->hasPermission(
                    $userId,
                    'data_table',
                    $dataTableId,
                    DataAccessSecurityService::PERMISSION_DELETE
                );

                if (!$this->dataAccessSecurityService->canDeleteOwnedRecord($ownEntriesOnly, $isOwnRecord, $hasDeletePermission)) {
                    return $this->apiResponseFormatter->formatError(
                        'You do not have permission to delete this entry.',
                        Response::HTTP_FORBIDDEN
                    );
                }
            }

            // Someone who may delete the entry still only reaches it through the dataset of the list the action
            // belongs to: a record outside it is as good as missing.
            $listContext = $validation->listSectionId !== null
                ? $this->listBoundary->contextFor($validation->page, $validation->effective, $validation->listSectionId)
                : null;
            if ($listContext !== null && !$this->listBoundary->contains($listContext, $recordId)) {
                return $this->apiResponseFormatter->formatError(
                    'Record not found',
                    Response::HTTP_NOT_FOUND
                );
            }

            $expectedVersion = $this->requireVersion($expectedVersion);
            $this->assertVersionIsCurrent($recordId, $expectedVersion);

            // Delete form data
            $success = $this->dataService->deleteData(
                $dataTableId,
                $recordId,
                $ownEntriesOnly,
                RecordWriteContext::interactive(
                    LookupService::DATA_REVISION_SOURCES_FORM,
                    $expectedVersion,
                    $listContext !== null ? $this->listBoundary->guard($listContext) : null,
                )
            );

            if (!$success) {
                return $this->apiResponseFormatter->formatError(
                    'Failed to delete form data or record not found',
                    Response::HTTP_NOT_FOUND
                );
            }

            return $this->apiResponseFormatter->formatSuccess(
                [
                    'record_id' => $recordId,
                    'section_id' => $sectionId,
                    'page_id' => $pageId,
                    'version' => $this->dataService->getRecordVersion($recordId),
                ],
                'responses/frontend/form_deleted'
            );

        } catch (ServiceException $e) {
            return $this->apiResponseFormatter->formatException($e);
        } catch (RequestValidationException $e) {
            // A body that breaks its request schema is the caller's mistake (400), never a server error: the
            // API exception listener builds the standard answer with the list of errors.
            throw $e;
        } catch (\Exception $e) {
            return $this->apiResponseFormatter->formatThrowable(
                $e,
                is_string($request->attributes->get('_route')) ? $request->attributes->get('_route') : null
            );
        }
    }

    // -- reading the request ------------------------------------------------------------------

    private function isJsonRequest(Request $request): bool
    {
        return str_starts_with((string) $request->headers->get('Content-Type', ''), 'application/json');
    }

    /**
     * An id of the request: a whole number of 1 or more, as an integer or as digits of a multipart text.
     *
     * @throws ServiceException 400 when it is missing or malformed
     */
    private function idOf(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1) {
            return (int) $value;
        }

        throw new ServiceException('page_id and section_id are required', Response::HTTP_BAD_REQUEST);
    }

    /**
     * The id of a record named by `update_based_on.record_id`.
     *
     * @throws ServiceException 400 when it is not a whole number of 1 or more
     */
    private function recordIdOf(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1) {
            return (int) $value;
        }

        throw new ServiceException('update_based_on.record_id must be a record number.', Response::HTTP_BAD_REQUEST, ['reason' => 'unsupported_target_selection']);
    }

    /**
     * The version the person saw, read strictly: exactly a whole number of 1 or more, as a number or as the digits of a
     * multipart text. Nothing is coerced: a float, a negative number, zero or a text with anything but digits fails
     * here (400), so none of them can turn into "no version" and skip the conflict check. A value that is absent is
     * null; {@see self::requireVersion()} refuses that once the request is known to be allowed.
     *
     * @throws ServiceException 400 when a value is present and malformed
     */
    private function expectedVersionOf(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) && $value >= 1) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1) {
            return (int) $value;
        }

        throw new ServiceException(
            'expected_version must be a whole number of 1 or more.',
            Response::HTTP_BAD_REQUEST,
            ['reason' => 'invalid_expected_version'],
        );
    }

    /**
     * An update or a delete always names the version the person saw. Asked once the page, the section, the payload and
     * the target are known to be acceptable, and always before an upload is stored or a row is written.
     *
     * @throws ServiceException 400 when the request carries no version
     */
    private function requireVersion(?int $expectedVersion): int
    {
        if ($expectedVersion === null) {
            throw new ServiceException(
                'The version of the entry you are changing is required (expected_version).',
                Response::HTTP_BAD_REQUEST,
                ['reason' => 'expected_version_required'],
            );
        }

        return $expectedVersion;
    }

    /**
     * A multipart flag (`preview`): only `1` and `true` switch it on.
     */
    private function flagOf(mixed $value): bool
    {
        return $value === true || $value === 1 || (is_string($value) && in_array(strtolower($value), ['1', 'true'], true));
    }

    /**
     * The values of a multipart request: every part that is not one of the request's own control fields.
     *
     * @param list<string> $extraControlKeys more control fields of the operation
     * @return array<string, mixed>
     */
    private function multipartFormData(Request $request, array $extraControlKeys = []): array
    {
        $formData = [];
        foreach ($request->request->all() as $key => $value) {
            if (!in_array((string) $key, [...self::MULTIPART_CONTROL_KEYS, ...$extraControlKeys], true)) {
                $formData[(string) $key] = $value;
            }
        }

        return $formData;
    }

    /**
     * The files of the request by the name of the part, as a list each.
     *
     * @return array<string, list<UploadedFile>>
     */
    private function uploadsOf(Request $request): array
    {
        $uploads = [];
        foreach ($request->files->all() as $name => $value) {
            $list = [];
            foreach (is_array($value) ? $value : [$value] as $file) {
                if ($file instanceof UploadedFile) {
                    $list[] = $file;
                }
            }
            if ($list !== []) {
                $uploads[(string) $name] = $list;
            }
        }

        return $uploads;
    }

    /**
     * Put the submitted values into the shape every client gets stored the same way.
     *
     * A multipart request carries every value as text, so a translatable value arrives as the JSON text of
     * its `[{language_id, value}, ...]` list, where a JSON request carries the list itself. It is decoded
     * here, and {@see DataService} then stores one cell per language (the text of the list used to be stored
     * as one cell and was shown as JSON).
     *
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    private function normalizeFormData(array $formData, bool $isMultipart): array
    {
        if (!$isMultipart) {
            return $formData;
        }
        foreach ($formData as $name => $value) {
            $translations = $this->translationListOf($value);
            if ($translations !== null) {
                $formData[$name] = $translations;
            }
        }

        return $formData;
    }

    /**
     * The `[{language_id, value}, ...]` list a JSON text holds, or null when the value is anything else.
     *
     * @return list<array{language_id: int, value: string}>|null
     */
    private function translationListOf(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value);
        if ($text === '' || $text[0] !== '[' || !str_ends_with($text, ']')) {
            return null;
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded) || $decoded === [] || !array_is_list($decoded)) {
            return null;
        }

        $translations = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !is_int($entry['language_id'] ?? null) || !is_string($entry['value'] ?? null)) {
                return null;
            }
            $translations[] = ['language_id' => $entry['language_id'], 'value' => $entry['value']];
        }

        return $translations;
    }

    private function formOf(FormWriteScope $scope): \App\Service\CMS\Effective\EffectiveForm
    {
        if ($scope->form === null) {
            throw new ServiceException('Invalid section type for submission', Response::HTTP_BAD_REQUEST);
        }

        return $scope->form;
    }

    // -- the record and its files ------------------------------------------------------------

    /**
     * Refuse a save that is based on an older version before an upload is stored. The record lock repeats the check
     * where it cannot be raced; this one only saves the work (and the stored file) of a request that is stale already.
     *
     * @throws ServiceException 409 when the record has a newer version
     */
    private function assertVersionIsCurrent(int $recordId, int $expectedVersion): void
    {
        $current = $this->dataService->getRecordVersion($recordId);
        if ($current !== $expectedVersion) {
            throw new ServiceException(
                'The entry was changed by someone else. Reload it and try again.',
                Response::HTTP_CONFLICT,
                ['reason' => 'stale_version', 'record_id' => $recordId, 'expected_version' => $expectedVersion, 'current_version' => $current],
            );
        }
    }

    /**
     * Store the uploaded files of the (already validated) request and name them in the values to write.
     * A single-file field is replaced; a multi-file field stores only the new uploads here —
     * {@see withFileOps()} merges them with what the record already holds.
     *
     * @param array<string, mixed> $data values by storage key
     * @param array<string, list<UploadedFile>> $files storage key => files
     * @param list<string|list<string>> $stored receives every stored path, so a failed write can remove them again
     * @return array<string, mixed>
     */
    private function withStoredFiles(array $data, array $files, FormWriteScope $scope, int $userId, int $sectionId, array &$stored): array
    {
        $form = $this->formOf($scope);
        foreach ($files as $key => $list) {
            $input = $form->input($key);
            if ($input === null) {
                continue;
            }
            // The directory of an upload is named by the input; the stored value names the file.
            $processed = $this->formFileUploadService->processUploadedFiles([$input->name => $list], $userId, $sectionId);
            if (!isset($processed[$input->name])) {
                continue;
            }
            $paths = $processed[$input->name];
            if (is_array($paths)) {
                $names = array_values(array_filter($paths, 'is_string'));
                $stored[] = $names;
                // Same flags as withFileOps: escaped slashes break str_contains(UPLOAD_DIR).
                $data[$key] = json_encode($names, JSON_UNESCAPED_SLASHES) ?: '[]';
            } elseif (is_string($paths)) {
                $stored[] = $paths;
                $data[$key] = $paths;
            }
        }

        return $data;
    }

    /**
     * Apply keep / remove / replace / add for file fields on update (contract 4).
     * Untouched fields stay out of `$data` so the existing cell is kept.
     * Old bytes are never deleted here: history still names them.
     *
     * @param array<string, mixed> $data
     * @param array<string, array{remove: bool, remove_indexes: list<int>}> $ops
     * @param array<string, list<UploadedFile>> $uploads
     * @return array<string, mixed>
     */
    private function withFileOps(array $data, array $ops, array $uploads, FormWriteScope $scope, ?int $recordId): array
    {
        if ($recordId === null || $recordId <= 0) {
            return $data;
        }
        $form = $this->formOf($scope);
        $keys = array_unique([...array_keys($ops), ...array_keys($uploads)]);
        foreach ($keys as $key) {
            $input = $form->input($key);
            if ($input === null || !$input->isFile) {
                continue;
            }
            $cell = $this->formFileUploadService->cellValue($recordId, $input->name)
                ?? $this->formFileUploadService->cellValue($recordId, $key);
            $existing = $cell !== null ? FormFileUploadService::keysIn($cell) : [];
            $op = $ops[$key] ?? null;
            if ($op !== null && $op['remove']) {
                $existing = [];
            } elseif ($op !== null && $op['remove_indexes'] !== []) {
                $drop = array_flip($op['remove_indexes']);
                $kept = [];
                foreach ($existing as $i => $logical) {
                    if (!isset($drop[$i])) {
                        $kept[] = $logical;
                    }
                }
                $existing = $kept;
            }

            $newRaw = isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
            $newKeys = $newRaw !== '' ? FormFileUploadService::keysIn($newRaw) : [];
            $multi = ($input->maxFiles ?? 1) > 1;
            if ($newKeys !== []) {
                // Replace (single) or append (multi) after any removals.
                $merged = $multi ? [...$existing, ...$newKeys] : $newKeys;
            } elseif ($op !== null) {
                $merged = $existing;
            } else {
                continue; // upload-only path already wrote $data[$key]
            }

            if (count($merged) === 0) {
                $data[$key] = '';
            } elseif (count($merged) === 1 && !$multi) {
                $data[$key] = $merged[0];
            } else {
                $data[$key] = json_encode($merged, JSON_UNESCAPED_SLASHES) ?: '[]';
            }
        }

        return $data;
    }

    /**
     * Remove files that were stored for a write that did not happen.
     *
     * @param list<string|list<string>> $stored
     */
    private function discardStoredFiles(array $stored): void
    {
        if ($stored === []) {
            return;
        }
        try {
            $this->formFileUploadService->deleteFiles($stored);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not remove the files of a refused write: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
