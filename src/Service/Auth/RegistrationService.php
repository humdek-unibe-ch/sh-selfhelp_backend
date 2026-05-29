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
use App\Service\Cache\Core\CacheService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles self-registration of new users.
 *
 * Supports two modes driven by the CMS register section fields:
 *   - open_registration = 1: any visitor may register with email + password.
 *   - open_registration = 0: a valid registration code is required.
 *
 * Both values are read server-side from the CMS section — the client is never
 * trusted to send the security policy.
 *
 * In both modes the new account is blocked and a validation email is sent
 * so the user must confirm their address before signing in.
 */
class RegistrationService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserValidationService $userValidationService,
        private readonly TransactionService $transactionService,
        private readonly LookupService $lookupService,
        private readonly CacheService $cache,
    ) {
    }

    /**
     * Register a new user.
     *
     * @param int         $pageId ID of the CMS register page (used to locate the register section and read policy fields).
     * @param string      $email  User's email address.
     * @param string|null $code   Registration code (required when open_registration = 0).
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

        ['open_registration' => $openRegistration, 'group_id' => $groupId] =
            $this->resolveSectionPolicy($pageId);

        if (!$openRegistration) {
            $group = $this->consumeRegistrationCode($code);
        } else {
            $group = $this->resolveGroup($groupId);
        }

        $user = $this->entityManager->wrapInTransaction(function () use ($email, $group, $code, $openRegistration) {
            if (!$openRegistration) {
                $vc = $this->entityManager->getRepository(ValidationCode::class)->findOneBy(['code' => trim((string) $code)]);
                if ($vc !== null) {
                    $vc->setConsumed(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                }
            }
            $user = new User();
            $user->setEmail($email);
            $user->setBlocked(true);

            $userType = $this->lookupService->findByTypeAndCode(
                LookupService::USER_TYPES,
                LookupService::USER_TYPES_USER
            );
            if ($userType) {
                $user->setUserType($userType);
            }

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->assignGroup($user, $group);
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
                    'action'  => 'self_registration',
                    'email'   => $email,
                    'groupId' => $group->getId(),
                ]) ?: null
            );

            $this->invalidateCaches();

            return is_numeric($validationResult['job_id']) ? (int) $validationResult['job_id'] : 0;
        });

        $this->userValidationService->executeScheduledValidationEmail((int) $user);
    }

    /**
     * Read open_registration and group_id from the register-style section on the given page.
     * Falls back to the style default_value when the admin has not set a value.
     *
     * @return array{open_registration: bool, group_id: int}
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

        return [
            'open_registration' => $openRegistration === '1',
            'group_id'          => (int) $groupValue,
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
     * Validate a registration code and return its group.
     * The code is deleted inside the transaction so removal is atomic with user creation.
     */
    private function consumeRegistrationCode(?string $code): Group
    {
        if ($code === null || trim($code) === '') {
            throw new \InvalidArgumentException('A registration code is required.');
        }

        $vc = $this->entityManager->getRepository(ValidationCode::class)->findOneBy([
            'code' => trim($code),
        ]);

        if ($vc === null) {
            throw new \InvalidArgumentException('Invalid registration code.');
        }

        if ($vc->getConsumed() !== null) {
            throw new \InvalidArgumentException('This registration code has already been used.');
        }

        $group = $vc->getGroup();
        if ($group === null) {
            throw new \InvalidArgumentException('Registration code has no group assigned.');
        }

        return $group;
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
