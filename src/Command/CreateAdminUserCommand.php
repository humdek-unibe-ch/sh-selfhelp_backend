<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command;

use App\Entity\Group;
use App\Entity\Lookup;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\ValidationCode;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Bootstrap helper to create a pre-validated admin user on a fresh install.
 *
 * The migrations seed groups, roles and lookups but DO NOT create any
 * humans-with-passwords (the legacy admin/sysadmin/tpf bcrypt hashes in
 * new_create_db.sql are intentionally NOT carried over because they
 * leak shared secrets). After running migrations on an empty database
 * the operator runs this command once to get a working admin login:
 *
 *   php bin/console app:create-admin-user admin@example.com "Admin User"
 *   # prompted for a password (hidden)
 *
 *   # or non-interactive:
 *   php bin/console app:create-admin-user admin@example.com "Admin User" \
 *     --password=S3cret! --name=admin
 *
 * The created user is:
 *   - status     = active   (id_status -> lookups['userStatus']['active'])
 *   - blocked    = 0
 *   - intern     = 0
 *   - user_type  = user     (id_user_types -> lookups['userTypes']['user'])
 *   - groups     = [admin]
 *   - roles      = [admin]
 *
 * Re-running the command with the same email updates the password and
 * re-asserts admin group/role membership instead of erroring out, so it
 * doubles as an "I forgot my admin password" recovery tool.
 */
#[AsCommand(
    name: 'app:create-admin-user',
    description: 'Create a pre-validated admin user (or reset its password) for fresh installs.'
)]
class CreateAdminUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly CacheService $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email (used as login identifier).')
            ->addArgument('display-name', InputArgument::OPTIONAL, 'Display name shown in the admin UI.', 'Admin')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Password (prompted if omitted).')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'user_name column (defaults to email).')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Group keyword to add the user to.', 'admin')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Role keyword to add the user to.', 'admin')
            ->addOption('no-admin', null, InputOption::VALUE_NONE, 'Skip admin group/role assignment (creates a plain validated user).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        $displayName = (string) $input->getArgument('display-name');
        $userName = (string) ($input->getOption('name') ?? $email);

        $password = $input->getOption('password');
        if ($password === null || $password === '') {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new Question('Password (hidden input): ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = $helper->ask($input, $output, $question);
            if (!is_string($password) || $password === '') {
                $io->error('Password is required.');
                return Command::FAILURE;
            }
        }

        $em = $this->entityManager;

        // Look up the active user status. We do not invent lookup rows here
        // because the seed migrations already create them; failing loud if
        // they are missing is the safer path on a half-migrated install.
        $activeStatus = $em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_STATUS,
            'lookupCode' => LookupService::USER_STATUS_ACTIVE,
        ]);
        if (!$activeStatus instanceof Lookup) {
            $io->error('Active user status lookup not found. Run migrations first: php bin/console doctrine:migrations:migrate.');
            return Command::FAILURE;
        }

        $userType = $em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_TYPES,
            'lookupCode' => LookupService::USER_TYPES_USER,
        ]);
        if (!$userType instanceof Lookup) {
            $io->error('User type lookup not found. Run migrations first.');
            return Command::FAILURE;
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        $isNew = false;
        if (!$user instanceof User) {
            $user = new User();
            $user->setEmail($email);
            $isNew = true;
        }

        $user
            ->setName($displayName)
            ->setUserName($userName)
            ->setBlocked(false)
            ->setIntern(false)
            ->setStatus($activeStatus)
            ->setUserType($userType);

        $hashed = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashed);

        if ($isNew) {
            $em->persist($user);
        }

        if (!$input->getOption('no-admin')) {
            $groupKw = (string) $input->getOption('group');
            $roleKw = (string) $input->getOption('role');

            $group = $em->getRepository(Group::class)->findOneBy(['name' => $groupKw]);
            $role = $em->getRepository(Role::class)->findOneBy(['name' => $roleKw]);

            if (!$group instanceof Group) {
                $io->warning("Group '{$groupKw}' not found — skipping group assignment.");
            }
            if (!$role instanceof Role) {
                $io->warning("Role '{$roleKw}' not found — skipping role assignment.");
            }

            // Group membership goes through the rel_groups_users join table.
            // We attach it raw so we do not pull in AdminUserService's full
            // transactional / validation pipeline for a bootstrap command.
            $em->flush();

            if ($group instanceof Group) {
                $conn = $em->getConnection();
                $conn->executeStatement(
                    'INSERT IGNORE INTO `rel_groups_users` (`id_groups`, `id_users`) VALUES (:g, :u)',
                    ['g' => $group->getId(), 'u' => $user->getId()],
                );
            }
            if ($role instanceof Role) {
                $user->addRole($role);
            }
        }

        $validationCode = $this->ensureActiveValidationCode($user);

        $user->bumpAclVersion();
        $em->flush();
        $this->invalidateUserCaches($user->getId());

        $io->success(($isNew ? 'Created' : 'Updated') . " admin user #{$user->getId()} <{$email}>.");
        $io->writeln('You can now log in with:');
        $io->writeln('  email:    ' . $email);
        $io->writeln('  password: (the one you just set)');
        $io->writeln('  user code: ' . $validationCode);

        return Command::SUCCESS;
    }

    private function ensureActiveValidationCode(User $user): string
    {
        foreach ($user->getValidationCodes() as $existingCode) {
            if ($existingCode->getConsumed() === null) {
                return (string) $existingCode->getCode();
            }
        }

        $validationCode = new ValidationCode();
        $validationCode->setCode($this->generateUniqueValidationCode());
        $validationCode->setUser($user);
        $this->entityManager->persist($validationCode);

        return (string) $validationCode->getCode();
    }

    private function generateUniqueValidationCode(): string
    {
        do {
            $code = strtoupper(bin2hex(random_bytes(8)));
        } while ($this->entityManager->getRepository(ValidationCode::class)->find($code) instanceof ValidationCode);

        return $code;
    }

    private function invalidateUserCaches(int $userId): void
    {
        $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->invalidateAllListsInCategory();

        $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);

        $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->invalidateAllListsInCategory();
    }
}
