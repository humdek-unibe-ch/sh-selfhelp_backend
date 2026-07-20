<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\Group;
use App\Entity\Lookup;
use App\Entity\User;
use App\Entity\UsersGroup;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seed demo users for exercising the admin Users page (status tiles, filters,
 * bulk actions, CSV export).
 *
 * DEV/TEST ONLY: refuses to run in prod. Every seeded row is prefixed `demo.`
 * so `--purge` can remove exactly what this command created and never touches
 * a real account.
 *
 * The users are built through the same entity model as production
 * (`CreateAdminUserCommand` / `QaBaselineFixture`): real `userStatus` /
 * `userTypes` lookups and real `rel_groups_users` membership, so the admin
 * page's permission and status logic sees indistinguishable data.
 */
#[AsCommand(
    name: 'app:demo:seed-users',
    description: 'Seed demo users (dev only) with varied status/blocked/group data for admin Users page testing.',
)]
final class SeedDemoUsersCommand extends Command
{
    /** Every seeded email uses this prefix so --purge can find them again. */
    private const EMAIL_PREFIX = 'demo.';
    private const EMAIL_DOMAIN = '@selfhelp.local';

    /** Deterministic seed: the same run produces the same users every time. */
    private const RANDOM_SEED = 20260717;

    private const FIRST_NAMES = [
        'Anna', 'Ben', 'Clara', 'David', 'Elena', 'Felix', 'Greta', 'Hugo',
        'Ida', 'Jonas', 'Karin', 'Lukas', 'Mira', 'Noah', 'Olivia', 'Pascal',
        'Quinn', 'Rosa', 'Simon', 'Tara', 'Urs', 'Vera', 'Willem', 'Xenia',
        'Yara', 'Zoe',
    ];

    private const LAST_NAMES = [
        'Ackermann', 'Brunner', 'Christen', 'Dubois', 'Egger', 'Frei',
        'Gerber', 'Huber', 'Imhof', 'Jost', 'Keller', 'Lehmann', 'Meier',
        'Nussbaum', 'Ott', 'Portmann', 'Roth', 'Steiner', 'Tanner', 'Vogel',
        'Wyss', 'Zimmermann',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'How many demo users to create', '75')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Delete every demo.* user instead of creating any');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Guard: seeding fake users into production would be unrecoverable.
        if ($this->appEnv === 'prod') {
            $io->error('Refusing to run in prod. Demo users are for dev/test only.');

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('purge')) {
            return $this->purge($io);
        }

        $rawCount = $input->getOption('count');
        $count = is_numeric($rawCount) ? (int) $rawCount : 0;
        if ($count < 1) {
            $io->error('--count must be a positive integer.');

            return Command::FAILURE;
        }

        return $this->seed($io, $count);
    }

    private function seed(SymfonyStyle $io, int $count): int
    {
        $statuses = $this->loadStatuses();
        $userType = $this->entityManager->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_TYPES,
            'lookupCode' => LookupService::USER_TYPES_USER,
        ]);
        if (!$userType instanceof Lookup) {
            $io->error('The userTypes/user lookup is missing. Run migrations first.');

            return Command::FAILURE;
        }

        /** @var list<Group> $groups */
        $groups = $this->entityManager->getRepository(Group::class)->findBy([], ['id' => 'ASC']);
        if ($groups === []) {
            $io->error('No groups found. Run migrations first.');

            return Command::FAILURE;
        }

        // Deterministic so re-running yields the same distribution.
        mt_srand(self::RANDOM_SEED);

        $created = 0;
        $skipped = 0;
        $tally = [];

        for ($i = 1; $i <= $count; $i++) {
            $first = self::FIRST_NAMES[($i - 1) % count(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[intdiv($i - 1, count(self::FIRST_NAMES)) % count(self::LAST_NAMES)];
            $email = sprintf('%s%s.%s%d%s', self::EMAIL_PREFIX, strtolower($first), strtolower($last), $i, self::EMAIL_DOMAIN);

            if ($this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
                $skipped++;
                continue;
            }

            // Spread across the buckets the admin page shows, plus the two
            // legacy statuses, so the tiles and filters have something to
            // distinguish. Roughly: 55% active, 25% invited, 12% blocked,
            // 8% legacy (interested/auto_created).
            [$statusCode, $blocked] = $this->pickStatus($i);

            $user = new User();
            $user->setEmail($email);
            $user->setName($first . ' ' . $last);
            $user->setUserName(sprintf('%s%s_%s%d', self::EMAIL_PREFIX, strtolower($first), strtolower($last), $i));
            $user->setStatus($statuses[$statusCode]);
            $user->setUserType($userType);
            $user->setBlocked($blocked);
            $user->setIntern(false);
            $user->setPassword($this->passwordHasher->hashPassword($user, 'DemoPassw0rd!2026'));

            // Vary last_login: some never, others spread over the last ~120 days.
            if ($i % 7 !== 0) {
                $user->setLastLogin(
                    (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                        ->modify(sprintf('-%d days', mt_rand(0, 120)))
                        ->modify(sprintf('-%d minutes', mt_rand(0, 1440)))
                );
            }

            $this->entityManager->persist($user);

            // Group membership: most users in one group, some in two, a few in
            // none (so the group filter has non-members to exclude).
            foreach ($this->pickGroups($groups, $i) as $group) {
                $membership = new UsersGroup();
                $membership->setUser($user);
                $membership->setGroup($group);
                $this->entityManager->persist($membership);
            }

            $label = $blocked ? 'blocked' : $statusCode;
            $tally[$label] = ($tally[$label] ?? 0) + 1;
            $created++;

            // Flush in batches so a large --count does not hold every pending
            // insert in memory. No clear() here: it would detach the Group and
            // Lookup references the remaining iterations still reuse.
            if ($created % 25 === 0) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('Created %d demo users (%d skipped, already existed).', $created, $skipped));

        ksort($tally);
        $rows = [];
        foreach ($tally as $bucket => $n) {
            $rows[] = [$bucket, $n];
        }
        $io->table(['bucket (as the tiles count it)', 'users'], $rows);
        $io->note('Password for every demo user: DemoPassw0rd!2026');
        $io->note('Remove them again with: php bin/console app:demo:seed-users --purge');

        return Command::SUCCESS;
    }

    private function purge(SymfonyStyle $io): int
    {
        /** @var list<User> $users */
        $users = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.email LIKE :prefix')
            ->setParameter('prefix', self::EMAIL_PREFIX . '%' . self::EMAIL_DOMAIN)
            ->getQuery()
            ->getResult();

        if ($users === []) {
            $io->warning('No demo users found.');

            return Command::SUCCESS;
        }

        foreach ($users as $user) {
            $this->entityManager->remove($user);
        }
        $this->entityManager->flush();

        $io->success(sprintf('Deleted %d demo users.', count($users)));

        return Command::SUCCESS;
    }

    /**
     * Load the userStatus lookups this command seeds, keyed by lookup code.
     *
     * @return array<string, Lookup>
     */
    private function loadStatuses(): array
    {
        /** @var list<Lookup> $rows */
        $rows = $this->entityManager->getRepository(Lookup::class)
            ->findBy(['typeCode' => LookupService::USER_STATUS]);

        $byCode = [];
        foreach ($rows as $row) {
            $code = $row->getLookupCode();
            if (is_string($code)) {
                $byCode[$code] = $row;
            }
        }

        return $byCode;
    }

    /**
     * Decide a user's (status, blocked) pair from its index.
     *
     * Index-driven rather than random so the distribution is stable and every
     * bucket the admin page can show is represented, including the legacy
     * `interested` / `auto_created` statuses that fall into NO tile — those
     * are exactly the rows that make `active + invited + blocked < total`, so
     * having a few makes the non-summing behaviour visible while testing.
     *
     * @return array{0: string, 1: bool}
     */
    private function pickStatus(int $i): array
    {
        return match (true) {
            $i % 25 === 0 => [LookupService::USER_STATUS_ACTIVE, false],
            $i % 12 === 0 => ['auto_created', false],
            $i % 11 === 0 => ['interested', false],
            $i % 8 === 0 => [LookupService::USER_STATUS_INVITED, true],
            $i % 6 === 0 => [LookupService::USER_STATUS_ACTIVE, true],
            $i % 3 === 0 => [LookupService::USER_STATUS_INVITED, false],
            default => [LookupService::USER_STATUS_ACTIVE, false],
        };
    }

    /**
     * Pick group membership for a user index: most get one group, every 4th
     * gets two, every 10th gets none.
     *
     * @param list<Group> $groups
     * @return list<Group>
     */
    private function pickGroups(array $groups, int $i): array
    {
        if ($i % 10 === 0) {
            return [];
        }

        $primary = $groups[$i % count($groups)];
        if ($i % 4 !== 0) {
            return [$primary];
        }

        $secondary = $groups[($i + 1) % count($groups)];

        return $secondary === $primary ? [$primary] : [$primary, $secondary];
    }
}
