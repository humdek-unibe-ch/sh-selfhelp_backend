<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\Group;
use App\Entity\Section;
use App\Entity\User;
use App\Entity\ValidationCode;
use App\Entity\ValidationCodeGroup;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles self-registration of new users.
 *
 * Supports two modes driven by the CMS register section fields:
 *   - open_registration = 1: any visitor may register with just an email. A
 *     unique registration code is minted server-side, linked to the new user
 *     and immediately marked consumed (so it shows up as a used historical
 *     code in the admin list). Any code the client submitted is ignored. The
 *     user is enrolled into EVERY group the section's `group` multi-select
 *     lists, so different register styles can assign different group sets.
 *   - open_registration = 0: a valid registration code is required and is
 *     consumed atomically. The user joins the UNION of the group(s) the code
 *     was issued for AND the group(s) the register section configures, so a
 *     single registration can grant membership in several groups at once.
 *
 * A registration code may itself grant several groups: the full set lives in
 * `validation_code_groups`, with `validation_codes.id_groups` kept as the
 * primary (first) group for backward-compatible listing/filtering.
 *
 * The policy (open_registration flag and group set) is read server-side from
 * the CMS section — the client is never trusted to send it.
 *
 * In both modes the new account is left in the invited (pending) state — it is
 * NOT blocked — and a validation email is sent so the user must confirm their
 * address (and set a password) before signing in.
 */
class RegistrationService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserValidationService $userValidationService,
        private readonly TransactionService $transactionService,
        private readonly LookupService $lookupService,
        private readonly CacheService $cache,
        private readonly RegistrationCodeService $registrationCodeService,
    ) {
    }

    /**
     * Register a new user.
     *
     * @param int         $pageId ID of the CMS register page (used to locate the register section and read policy fields).
     * @param string      $email  User's email address.
     * @param string|null $code   Registration code (required when open_registration = 0; ignored when open_registration = 1).
     * @throws \InvalidArgumentException On validation failure.
     * @throws \RuntimeException On system failure.
     */
    public function register(
        int $pageId,
        string $email,
        ?string $code = null,
    ): void {
        $email = mb_strtolower(trim($email));

        $this->assertEmailNotTaken($email);

        ['open_registration' => $openRegistration, 'group_ids' => $groupIds] =
            $this->resolveSectionPolicy($pageId);

        $normalizedCode = $code !== null ? trim($code) : null;

        // Everything that decides "is this code still valid?" and "is the user
        // created?" runs in ONE transaction so the code is consumed only after
        // the user exists and a rollback leaves validation_codes untouched.
        $jobId = $this->entityManager->wrapInTransaction(function () use ($email, $openRegistration, $groupIds, $normalizedCode): int {
            // The register section always configures at least one group; both
            // modes enrol the user into those section groups. They come from
            // the admin-configured section, never from the request body.
            $sectionGroups = $this->resolveGroups($groupIds);

            if (!$openRegistration) {
                if ($normalizedCode === null || $normalizedCode === '') {
                    throw new \InvalidArgumentException('A registration code is required.');
                }

                // Atomically claim the code: the row is locked FOR UPDATE so a
                // concurrent registration with the same code blocks here and
                // then sees consumed != null — only one request can ever win.
                $vc = $this->claimRegistrationCode($normalizedCode);

                // The code grants its own group(s); merge them with the
                // register section's group(s) so one registration enrols the
                // user into the UNION of both sets (deduplicated by id).
                $codeGroups = $this->resolveCodeGroups($vc);
                if ($codeGroups === []) {
                    throw new \InvalidArgumentException('Registration code has no group assigned.');
                }
                $groups = $this->mergeGroups($codeGroups, $sectionGroups);
            } else {
                // Open mode enrols the user into EVERY group the register
                // section selected, so one register style can assign multiple
                // groups (e.g. "subject" + "therapist").
                $vc     = null;
                $groups = $sectionGroups;
            }

            $user = new User();
            $user->setEmail($email);

            $userType = $this->lookupService->findByTypeAndCode(
                LookupService::USER_TYPES,
                LookupService::USER_TYPES_USER
            );
            if ($userType) {
                $user->setUserType($userType);
            }

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            foreach ($groups as $membershipGroup) {
                $this->assignGroup($user, $membershipGroup);
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            // Link + consume the code only now that the user has an ID. The
            // owning side (ValidationCode.user) writes validation_codes.id_users.
            if ($vc !== null) {
                // Closed mode: consume the code the visitor supplied.
                $vc->setConsumed($now);
                $vc->setUser($user);
            } else {
                // Open mode: mint a fresh, already-consumed code so every
                // self-registered account still owns a unique historical code
                // (surfaced as a used code in the admin registration-code list).
                // It carries every section group, mirroring a multi-group code.
                $generated = new ValidationCode();
                $generated->setCode($this->registrationCodeService->generateUnique());
                $generated->setGroup($groups[0]);
                $generated->setUser($user);
                $generated->setConsumed($now);
                $this->entityManager->persist($generated);
                foreach ($groups as $membershipGroup) {
                    $codeGroup = new ValidationCodeGroup();
                    $codeGroup->setCode($generated);
                    $codeGroup->setGroup($membershipGroup);
                    $this->entityManager->persist($codeGroup);
                }
            }

            $this->entityManager->flush();

            $validationResult = $this->userValidationService->setupUserValidation($user);
            if (!($validationResult['success'] ?? false)) {
                $errMsg = is_string($validationResult['error'] ?? null) ? $validationResult['error'] : 'unknown error';
                throw new \RuntimeException('Failed to setup validation email: ' . $errMsg);
            }

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                LookupService::TRANSACTION_BY_BY_USER,
                'users',
                $user->getId(),
                false,
                json_encode([
                    'action'   => 'self_registration',
                    'email'    => $email,
                    'groupIds' => array_map(static fn(Group $g): ?int => $g->getId(), $groups),
                ]) ?: null
            );

            $this->invalidateCaches();

            return is_numeric($validationResult['job_id']) ? (int) $validationResult['job_id'] : 0;
        });

        // Send the validation email only after the user + code consumption have
        // been committed.
        if ($jobId > 0) {
            $this->userValidationService->executeScheduledValidationEmail($jobId);
        }
    }

    /**
     * Read open_registration and the configured group IDs from the
     * register-style section on the given page. Falls back to the style
     * default_value when the admin has not set a value.
     *
     * The `group` field is a select-group MultiSelect, so the frontend stores
     * the chosen group IDs as a separator-joined string (e.g. "2,3"). Every
     * integer in the value is treated as a group ID (deduplicated) — this is
     * what lets a single register style enrol new users into multiple groups.
     *
     * @return array{open_registration: bool, group_ids: non-empty-list<int>}
     */
    private function resolveSectionPolicy(int $pageId): array
    {
        $conn = $this->entityManager->getConnection();

        $sectionId = $conn->fetchOne(
            'SELECT s.id
               FROM sections s
               JOIN styles st ON st.id = s.id_styles
              WHERE st.name = :styleName
                AND (
                    EXISTS (
                        SELECT 1 FROM rel_pages_sections rps
                        WHERE rps.id_sections = s.id AND rps.id_pages = :pageId
                    )
                    OR EXISTS (
                        SELECT 1 FROM rel_sections_hierarchy rsh
                        JOIN rel_pages_sections rps ON rps.id_sections = rsh.id_parent_section
                        WHERE rsh.id_child_section = s.id AND rps.id_pages = :pageId
                    )
                )
              LIMIT 1',
            ['pageId' => $pageId, 'styleName' => 'register']
        );

        if ($sectionId === false) {
            throw new \InvalidArgumentException('No register section found for this page.');
        }

        $section = $this->entityManager->getRepository(Section::class)->find(is_numeric($sectionId) ? (int) $sectionId : 0);
        if ($section === null) {
            throw new \InvalidArgumentException('Register section could not be loaded.');
        }

        $openRegistration = $this->readSectionField($section, 'open_registration');
        $groupValue       = $this->readSectionField($section, 'group');

        if ($groupValue === null || trim($groupValue) === '') {
            throw new \InvalidArgumentException('Registration group is not configured for this section.');
        }

        // Separator-agnostic: pull every integer ID out of the stored value so
        // the parsing is correct whether the MultiSelect joined with ",", ";",
        // or wrapped the IDs in a JSON array.
        preg_match_all('/\d+/', $groupValue, $matches);
        $groupIds = array_values(array_unique(array_map(
            static fn(string $id): int => (int) $id,
            $matches[0]
        )));

        if ($groupIds === []) {
            throw new \InvalidArgumentException('Registration group is not configured for this section.');
        }

        return [
            'open_registration' => $openRegistration === '1',
            'group_ids'         => $groupIds,
        ];
    }

    /**
     * Read a single field value for a section, falling back to the style default_value.
     */
    private function readSectionField(Section $section, string $fieldName): ?string
    {
        $conn = $this->entityManager->getConnection();

        $value = $conn->fetchOne(
            'SELECT sft.content
               FROM sections_fields_translation sft
               JOIN fields f ON f.id = sft.id_fields
              WHERE sft.id_sections = :sectionId
                AND f.name = :fieldName
              LIMIT 1',
            ['sectionId' => $section->getId(), 'fieldName' => $fieldName]
        );

        if (is_string($value) && $value !== '') {
            return $value;
        }

        // Fall back to style default_value
        $style = $section->getStyle();
        if ($style === null) {
            return null;
        }

        $default = $conn->fetchOne(
            'SELECT rfs.default_value
               FROM rel_fields_styles rfs
               JOIN fields f ON f.id = rfs.id_fields
              WHERE rfs.id_styles = :styleId
                AND f.name = :fieldName
              LIMIT 1',
            ['styleId' => $style->getId(), 'fieldName' => $fieldName]
        );

        return is_string($default) && $default !== '' ? $default : null;
    }

    private function assertEmailNotTaken(string $email): void
    {
        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing !== null) {
            throw new \InvalidArgumentException('An account with this email address already exists.');
        }
    }

    private function resolveGroup(int $groupId): Group
    {
        $group = $this->entityManager->getRepository(Group::class)->find($groupId);
        if ($group === null) {
            throw new \InvalidArgumentException('Invalid registration group configuration.');
        }
        return $group;
    }

    /**
     * Resolve every configured group, validating each exists. Used by both
     * modes so the user is enrolled into all section-selected groups.
     *
     * @param non-empty-list<int> $groupIds
     * @return non-empty-list<Group>
     */
    private function resolveGroups(array $groupIds): array
    {
        return array_map(fn(int $groupId): Group => $this->resolveGroup($groupId), $groupIds);
    }

    /**
     * Resolve every group a registration code grants. The full set lives in
     * `validation_code_groups` (multi-group codes); a code with no link rows
     * falls back to its legacy single `validation_codes.id_groups` group.
     *
     * @return list<Group>
     */
    private function resolveCodeGroups(ValidationCode $vc): array
    {
        $code = $vc->getCode();

        /** @var list<int> $groupIds */
        $groupIds = [];
        if ($code !== null) {
            $rows = $this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT id_groups FROM validation_code_groups WHERE code = :code',
                ['code' => $code]
            );
            foreach ($rows as $row) {
                if (is_numeric($row)) {
                    $groupIds[] = (int) $row;
                }
            }
        }

        if ($groupIds === []) {
            $legacyGroup = $vc->getGroup();

            return $legacyGroup !== null ? [$legacyGroup] : [];
        }

        return array_map(
            fn(int $groupId): Group => $this->resolveGroup($groupId),
            array_values(array_unique($groupIds))
        );
    }

    /**
     * Merge two group sets into one list, deduplicated by group id. The
     * primary set is non-empty, so the result is guaranteed non-empty.
     *
     * @param non-empty-list<Group> $primary
     * @param list<Group>           $secondary
     * @return non-empty-list<Group>
     */
    private function mergeGroups(array $primary, array $secondary): array
    {
        $byId = [];
        foreach ($primary as $group) {
            $byId[(int) $group->getId()] = $group;
        }
        foreach ($secondary as $group) {
            $byId[(int) $group->getId()] = $group;
        }

        return array_values($byId);
    }

    /**
     * Lock and validate a registration code so it can be consumed atomically.
     *
     * MUST be called inside an open transaction: the row is fetched with a
     * PESSIMISTIC_WRITE lock (SELECT ... FOR UPDATE). A second concurrent
     * registration using the same code blocks on the lock and, once the first
     * transaction commits, sees consumed != null and is rejected. This is what
     * guarantees a code creates at most one user.
     */
    private function claimRegistrationCode(string $code): ValidationCode
    {
        $vc = $this->entityManager->find(ValidationCode::class, $code, LockMode::PESSIMISTIC_WRITE);

        if ($vc === null) {
            throw new \InvalidArgumentException('Invalid registration code.');
        }

        if ($vc->getConsumed() !== null) {
            throw new \InvalidArgumentException('This registration code has already been used.');
        }

        return $vc;
    }

    private function assignGroup(User $user, Group $group): void
    {
        $usersGroup = new \App\Entity\UsersGroup();
        $usersGroup->setUser($user);
        $usersGroup->setGroup($group);
        $this->entityManager->persist($usersGroup);
    }

    private function invalidateCaches(): void
    {
        $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->invalidateAllListsInCategory();
    }
}
