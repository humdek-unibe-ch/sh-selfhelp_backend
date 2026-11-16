<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS;

use App\Exception\ServiceException;
use App\Repository\PageRepository;
use App\Repository\SectionRepository;
use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\Effective\EffectiveFormFactory;
use App\Service\CMS\Effective\EffectivePage;
use App\Service\CMS\Effective\EffectivePageResolver;
use App\Service\CMS\Effective\FormWriteScope;
use App\Service\CMS\FormList\FormListConfig;
use App\Service\CMS\FormList\FormListConfigReader;
use App\Service\CMS\FormList\FormListGuard;
use App\Service\CMS\FormList\FormListLayout;
use App\Service\CMS\Frontend\PageService;
use App\Service\Core\BaseService;
use App\Service\Core\ConditionService;
use App\Service\Core\UserContextAwareService;
use Symfony\Component\HttpFoundation\Response;


/**
 * Authorizes a write to a form and says which configuration it is checked against.
 *
 * Who may write is decided by the same rules as who may read: the page is
 * visible to the visitor (the page ACL needs only `select`; groups see), the
 * section is on the page and visible there, and the list it sits in (if any)
 * offers the operation. What decides the rest is the *effective* page
 * ({@see EffectivePage}): the published version for a visitor, the draft only
 * for an explicit, signed-in preview. The operation flags, the table, the
 * owner scope and the inputs of the form all come from it, so a switch or an
 * input that was changed in the draft and not published yet has no effect on
 * what a visitor can do.
 *
 * Whether the person may change this record, and which records the list
 * contains, is decided where the record is touched
 * ({@see \App\Service\CMS\DataService}, under the record lock).
 */
class FormValidationService extends BaseService
{
    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly SectionRepository $sectionRepository,
        private readonly UserContextAwareService $userContextAwareService,
        private readonly ConditionService $conditionService,
        private readonly FormListGuard $listGuard,
        private readonly FormListConfigReader $listReader,
        private readonly EffectivePageResolver $effectivePages,
        private readonly EffectiveFormFactory $formFactory,
        private readonly PageService $pageService,
    ) {
    }

    /**
     * Validate form submission request
     *
     * @param int $pageId The page ID where form is submitted from
     * @param int $sectionId The section ID (form ID)
     * @param array<string, mixed> $formData The form data being submitted
     * @param bool $hasFiles Uploaded files travel outside $formData; they count as content
     * @param bool $preview a signed-in user acting on the draft of the page
     * @return array<string, mixed> Validation result with page and section info
     * @throws ServiceException If validation fails
     */
    public function validateFormSubmission(int $pageId, int $sectionId, array $formData, bool $hasFiles = false, bool $preview = false): array
    {
        $scope = $this->authorize(FormListGuard::OP_CREATE, $pageId, $sectionId, $preview);
        $this->assertPayloadShape($formData, $hasFiles);

        return $this->resultOf($scope);
    }

    /**
     * Validate a form update request: the same checks as a submission plus the
     * section's `own_entries_only` flag (default on when the field is unset),
     * which FormController::updateForm needs for the record edit mode rule
     * (issue #30) — updating another user's record requires the section to opt
     * out of own_entries_only AND table UPDATE permission.
     *
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    public function validateFormUpdate(int $pageId, int $sectionId, array $formData, bool $hasFiles = false, bool $preview = false): array
    {
        $scope = $this->authorize(FormListGuard::OP_UPDATE, $pageId, $sectionId, $preview);
        $this->assertPayloadShape($formData, $hasFiles);

        return $this->resultOf($scope) + ['own_entries_only' => $scope->ownEntriesOnly];
    }

    /**
     * Validate delete request: the page is visible, the section is on it, and it is of a delete-capable type.
     *
     * @return array<string, mixed>
     */
    public function validateFormDeletion(int $pageId, int $sectionId, bool $preview = false): array
    {
        $scope = $this->authorize(FormListGuard::OP_DELETE, $pageId, $sectionId, $preview);

        return $this->resultOf($scope) + [
            'own_entries_only' => $scope->ownEntriesOnly,
            // The table this delete action is bound to (F127): the record to
            // delete must belong to it. Null = the action names no table.
            'data_table_id' => $scope->dataTableId,
        ];
    }

    /**
     * Validate page access for anonymous users
     * This is a special validation for public form submissions
     *
     * @param int $pageId The page ID
     * @param int $sectionId The section ID to validate
     * @return array<string, mixed> Validation result with page and section info
     * @throws ServiceException If page is not accessible
     */
    public function validatePublicPageAccess(int $pageId, int $sectionId, bool $preview = false): array
    {
        return $this->resultOf($this->authorize(FormListGuard::OP_CREATE, $pageId, $sectionId, $preview));
    }

    /**
     * Authorize one write to a form section (or one delete through a delete action) and resolve
     * everything the write is checked against.
     *
     * Order: the page exists, the visitor may see it, the effective configuration is chosen, the section exists and
     * has the right type, it is on the page and visible there, and the list it sits in offers the operation.
     *
     * @param string $operation one of the {@see FormListGuard} OP_* constants
     * @param bool $preview act on the draft of the page (a signed-in user only); everybody else acts on the published version
     * @throws ServiceException 404 page or section unknown, 401 a guest on a list of own entries or a guest preview, 403 not allowed, 400 wrong section type or form of another table
     */
    public function authorize(string $operation, int $pageId, int $sectionId, bool $preview = false): FormWriteScope
    {
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        $authenticated = $this->userContextAwareService->getCurrentUser() !== null;
        if ($authenticated) {
            // Groups see, roles edit: a participant works with offered forms on a page they can see.
            $this->pageService->assertPageReadable($page, null, 'select');
        } elseif (!$page->isOpenAccess()) {
            $this->throwForbidden('Page is not open access');
        }

        $effective = $this->effectivePages->forPage($page, $preview);

        $section = $this->sectionRepository->find($sectionId);
        if (!$section) {
            $this->throwNotFound('Section not found');
        }

        $styleName = $section->getStyle()?->getName();
        $isDelete = $operation === FormListGuard::OP_DELETE;
        $allowedStyles = $isDelete
            ? [StyleNames::STYLE_ENTRY_TABLE, StyleNames::STYLE_ENTRY_RECORD_DELETE, StyleNames::STYLE_ENTRY_FORM_LIST]
            : StyleNames::FORM_STYLE_NAMES;
        if (!in_array($styleName, $allowedStyles, true)) {
            throw new ServiceException(
                $isDelete ? 'Invalid section type for delete operation' : 'Invalid section type for submission',
                Response::HTTP_BAD_REQUEST
            );
        }

        // The section must be on the page and visible there; the list it sits in
        // (if any) decides which operations it may perform.
        $listId = $styleName === StyleNames::STYLE_ENTRY_FORM_LIST ? $sectionId : $this->enclosingListIdIn($effective, $sectionId);
        if ($styleName === StyleNames::STYLE_ENTRY_FORM_LIST) {
            $this->visibleOccurrences($effective, $sectionId);
        }
        $list = null;
        if ($listId !== null) {
            $list = $this->listReader->readIn($effective, $listId, 1);
            $this->listGuard->assertOperation($list, $operation, $authenticated, $isDelete ? null : $sectionId);
        }

        $form = $isDelete ? null : $this->formFactory->build($effective, $sectionId, fn (array $row): bool => $this->conditionHolds($row));

        $ownEntriesOnly = $list !== null
            ? $list->ownEntriesOnly
            : $this->ownEntriesOnlyOf($effective, $sectionId);
        $dataTableId = null;
        if ($isDelete) {
            $dataTableId = $list !== null
                ? $this->listGuard->tableIdOf($list, $effective->rows())
                : $this->resolveBoundDataTableId($effective, $sectionId);
        }

        return new FormWriteScope(
            operation: $operation,
            page: $page,
            section: $section,
            effective: $effective,
            listSectionId: $listId,
            list: $list,
            form: $form,
            ownEntriesOnly: $ownEntriesOnly,
            dataTableId: $dataTableId,
            authenticated: $authenticated,
            preview: $preview,
        );
    }

    /**
     * Whether the section is on the page and visible to the visitor there: the list endpoints serve a section
     * only through a page the same way the page itself shows it.
     *
     * @throws ServiceException If the section is not on the page or is hidden by a condition
     */
    public function assertSectionVisibleIn(EffectivePage $effective, int $sectionId): void
    {
        $this->visibleOccurrences($effective, $sectionId);
    }

    /**
     * Check the structure of a submitted payload: not empty, string keys of sane length, values that are
     * scalars or a list of per-language values, and not larger than a text column. What the keys and values
     * may be for this form is decided by {@see \App\Service\CMS\Effective\FormInputValidator}.
     *
     * @param array<array-key, mixed> $formData The form data to validate
     * @throws ServiceException If validation fails
     */
    public function assertPayloadShape(array $formData, bool $hasFiles = false): void
    {
        if (empty($formData) && !$hasFiles) {
            throw new ServiceException(
                'Form data cannot be empty',
                Response::HTTP_BAD_REQUEST
            );
        }

        // Check for potentially dangerous fields
        $forbiddenFields = ['id', 'timestamp', 'id_action_trigger_types'];
        foreach ($forbiddenFields as $forbiddenField) {
            if (array_key_exists($forbiddenField, $formData)) {
                throw new ServiceException(
                    "Field '{$forbiddenField}' is not allowed in form data",
                    Response::HTTP_BAD_REQUEST,
                    ['reason' => 'protected_field', 'fields' => [$forbiddenField]],
                );
            }
        }

        // Validate data types and lengths
        foreach ($formData as $fieldName => $fieldValue) {
            if (!is_string($fieldName)) {
                throw new ServiceException(
                    'Field names must be strings',
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (strlen($fieldName) > 255) {
                throw new ServiceException(
                    "Field name '{$fieldName}' is too long (max 255 characters)",
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Translation arrays: a list of {language_id, value}
            if (is_array($fieldValue) && !empty($fieldValue) && is_array($fieldValue[0] ?? null) && isset($fieldValue[0]['language_id'])) {
                if (!$this->isValidTranslationArray($fieldValue)) {
                    throw new ServiceException(
                        "Field '{$fieldName}' contains invalid translation data format",
                        Response::HTTP_BAD_REQUEST
                    );
                }
                continue;
            }

            // An empty value is "nothing chosen" (an empty list of a multi select, no file): the input rules decide
            // whether that is acceptable. Anything else must be a scalar or a list of scalars.
            if ($fieldValue !== null && !is_scalar($fieldValue) && !$this->isScalarList($fieldValue)) {
                throw new ServiceException(
                    "Field '{$fieldName}' must contain a scalar value",
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (is_scalar($fieldValue) && strlen((string) $fieldValue) > 65535) { // TEXT field limit
                throw new ServiceException(
                    "Field '{$fieldName}' value is too long (max 65535 characters)",
                    Response::HTTP_BAD_REQUEST
                );
            }
        }
    }

    // -- the page tree, as the effective page holds it ---------------------------------------

    /**
     * The places of the section on the page that are visible to the current
     * visitor: for each one the tree rows from the page root down to the
     * section itself.
     *
     * The page tree lists every place (occurrence) of the section with its path
     * from the page root. A write is allowed when at least one occurrence is
     * visible: the section's own condition and the condition of every ancestor
     * on the way to the page must hold. A hidden form therefore refuses the
     * write, exactly like it is hidden on the render path (F05).
     *
     * @return list<list<array<array-key, mixed>>>
     * @throws ServiceException If the section is not on the page or every place is hidden
     */
    private function visibleOccurrences(EffectivePage $effective, int $sectionId): array
    {
        /** @var array<string, array<array-key, mixed>> $rowsByPath */
        $rowsByPath = [];
        /** @var list<string> $occurrencePaths */
        $occurrencePaths = [];
        foreach ($effective->rows() as $row) {
            $path = $this->asString($row['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $rowsByPath[$path] = $row;
            if ($this->asInt($row['id'] ?? null) === $sectionId) {
                $occurrencePaths[] = $path;
            }
        }

        if ($occurrencePaths === []) {
            $this->throwForbidden('Section does not belong to this page');
        }

        $visible = [];
        foreach ($occurrencePaths as $path) {
            if (!$this->isOccurrenceVisible($path, $rowsByPath)) {
                continue;
            }
            $chain = [];
            $prefix = '';
            foreach (explode(',', $path) as $id) {
                $prefix = $prefix === '' ? $id : $prefix . ',' . $id;
                $chain[] = $rowsByPath[$prefix];
            }
            $visible[] = $chain;
        }

        if ($visible === []) {
            $this->throwForbidden('This form is not available');
        }

        return $visible;
    }

    /**
     * The list a section sits in: the nearest list above it, in the first
     * visible place that has one. A section that has a place inside a list
     * follows that list's rules wherever else it is placed, so a place outside
     * the list is never a way around them.
     *
     * @throws ServiceException If the section is not on the page or is hidden by a condition
     */
    public function enclosingListIdIn(EffectivePage $effective, int $sectionId): ?int
    {
        foreach ($this->visibleOccurrences($effective, $sectionId) as $chain) {
            for ($i = count($chain) - 2; $i >= 0; --$i) {
                if (in_array($chain[$i]['style_name'] ?? null, StyleNames::LIST_STYLE_NAMES, true)) {
                    return $this->asInt($chain[$i]['id'] ?? null);
                }
            }
        }

        return null;
    }

    /**
     * Whether every section on the path from the page root down to (and
     * including) the occurrence passes its condition.
     *
     * @param array<string, array<array-key, mixed>> $rowsByPath page tree rows keyed by their root path
     */
    private function isOccurrenceVisible(string $path, array $rowsByPath): bool
    {
        $prefix = '';
        foreach (explode(',', $path) as $id) {
            $prefix = $prefix === '' ? $id : $prefix . ',' . $id;
            $row = $rowsByPath[$prefix] ?? null;
            if ($row === null || !$this->conditionHolds($row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate one tree row's condition for the current visitor. No condition
     * means visible; a condition that cannot be evaluated fails closed.
     *
     * @param array<array-key, mixed> $row
     */
    public function conditionHolds(array $row): bool
    {
        $condition = $row['condition'] ?? null;
        if ($condition === null || $condition === '' || (!is_string($condition) && !is_array($condition))) {
            return true;
        }

        $result = $this->conditionService->evaluateCondition(
            $condition,
            null,
            $this->asString($row['section_name'] ?? 'section')
        );

        return ($result['result'] ?? false) === true;
    }

    /**
     * The `own_entries_only` flag of a section: on unless the section says otherwise.
     */
    private function ownEntriesOnlyOf(EffectivePage $effective, int $sectionId): bool
    {
        $stored = $effective->rawFields($sectionId)['own_entries_only'] ?? [];
        $content = $stored === [] ? null : ($stored[1] ?? $stored[array_key_first($stored)]);

        return ($content ?? '1') === '1';
    }

    /**
     * The data table a section acts on: its own `data_table` property, otherwise
     * the nearest ancestor that names one (an `entry-record-delete` inside an
     * `entry-list` acts on the list's table). Occurrences are the section's
     * places in the page tree; when they disagree on the table the binding is
     * ambiguous and resolves to null, so nothing is deleted through it.
     */
    private function resolveBoundDataTableId(EffectivePage $effective, int $sectionId): ?int
    {
        $own = $this->parseNullableInt($effective->fieldContent($sectionId, 'data_table'));
        if ($own !== null && $own > 0) {
            return $own;
        }

        /** @var list<list<int>> $chains nearest ancestor first, one chain per occurrence */
        $chains = [];
        foreach ($effective->rowsOf($sectionId) as $row) {
            $ids = FormListLayout::pathIds($row);
            array_pop($ids); // the section itself
            $chains[] = array_reverse($ids);
        }

        $resolved = [];
        foreach ($chains as $chain) {
            foreach ($chain as $ancestorId) {
                $tableId = $this->parseNullableInt($effective->fieldContent($ancestorId, 'data_table'));
                if ($tableId !== null && $tableId > 0) {
                    $resolved[$tableId] = true;
                    break;
                }
            }
        }

        return count($resolved) === 1 ? (int) array_key_first($resolved) : null;
    }

    private function parseNullableInt(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (int) $value : null;
    }

    // -- value shapes -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function resultOf(FormWriteScope $scope): array
    {
        return [
            'page' => $scope->page,
            'section' => $scope->section,
            'validated' => true,
            'scope' => $scope,
        ];
    }

    private function isScalarList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an array contains valid translation data
     *
     * @param array<array-key, mixed> $translationArray The array to validate
     * @return bool True if valid translation array
     */
    private function isValidTranslationArray(array $translationArray): bool
    {
        if (empty($translationArray)) {
            return false;
        }

        foreach ($translationArray as $translation) {
            // Each translation must be an object with language_id and value
            if (!is_array($translation) ||
                !isset($translation['language_id']) ||
                !isset($translation['value'])) {
                return false;
            }

            // language_id must be a positive integer (or numeric string that can be converted to int)
            $languageId = $translation['language_id'];
            if (is_string($languageId)) {
                $languageId = is_numeric($languageId) ? (int) $languageId : null;
            }

            if (!is_int($languageId) || $languageId < 1) {
                return false;
            }

            // value must be scalar (null values are already rejected by the isset check above)
            $value = $translation['value'];
            if (!is_scalar($value)) {
                return false;
            }

            // If value is string, check length
            if (is_string($value) && strlen($value) > 65535) {
                return false;
            }
        }

        return true;
    }
}
