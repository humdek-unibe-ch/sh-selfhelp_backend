<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Exception\ServiceException;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\Resource\ResourceBindingGuard;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * C8: the public bind check that the section save and import call (and any later writer of a
 * `resourceFields` value may call) for every manifest `resourceFields` value.
 *
 * An editor binds an item only when their roles hold the **view** grant on it; the superuser
 * passes; an empty value clears the binding and passes; a system actor (user id 0) passes (F07).
 * What the section save and import answer with it is covered by `SectionTableBindingTest`.
 */
#[PhpUnitGroup('security')]
final class ResourceBindingGuardTest extends QaKernelTestCase
{
    private const TYPE = QaItemPluginFixture::TYPE_CODE;
    private const ITEM = 5;

    private ResourceBindingGuard $guard;
    private RoleFactory $roles;
    private RoleDataAccessFactory $grants;
    private UserFactory $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = $this->service(ResourceBindingGuard::class);
        $this->roles = new RoleFactory($this->em);
        $this->grants = new RoleDataAccessFactory(
            $this->em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class),
        );
        $this->users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );

        QaItemPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
        );
    }

    protected function tearDown(): void
    {
        QaItemPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    public function testAnEditorWhoMayViewTheItemMayBindIt(): void
    {
        $editor = $this->editorWith('qa_bind_view', DataAccessSecurityService::PERMISSION_READ);

        $this->guard->assertBindable(self::TYPE, (string) self::ITEM, (int) $editor->getId());
        $this->guard->assertBindable(self::TYPE, self::ITEM, (int) $editor->getId());

        $this->addToAssertionCount(1);
    }

    public function testAnEditorWithoutTheViewGrantIsRefusedWhateverElseTheyMayDo(): void
    {
        $none = $this->editorWith('qa_bind_none', null);
        $updateOnly = $this->editorWith(
            'qa_bind_update_only',
            DataAccessSecurityService::PERMISSION_UPDATE | DataAccessSecurityService::PERMISSION_DELETE | DataAccessSecurityService::PERMISSION_CREATE,
        );

        foreach ([$none, $updateOnly] as $editor) {
            $refusal = $this->refusal(fn () => $this->guard->assertBindable(self::TYPE, (string) self::ITEM, (int) $editor->getId()));
            self::assertSame(ResourceBindingGuard::ERROR_NOT_BINDABLE, $refusal->getMessage());
            self::assertSame(Response::HTTP_FORBIDDEN, $refusal->getCode());
            self::assertSame(['type' => self::TYPE], $refusal->getData());
        }
    }

    public function testAGrantOnAnotherItemDoesNotAllowThisOne(): void
    {
        $editor = $this->editorWith('qa_bind_other', DataAccessSecurityService::PERMISSION_READ, self::ITEM + 1);

        $refusal = $this->refusal(fn () => $this->guard->assertBindable(self::TYPE, (string) self::ITEM, (int) $editor->getId()));

        self::assertSame(ResourceBindingGuard::ERROR_NOT_BINDABLE, $refusal->getMessage());
    }

    public function testTheSuperuserMayBindAnyItem(): void
    {
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);

        $this->guard->assertBindable(self::TYPE, (string) self::ITEM, (int) $admin->getId());
        $this->guard->assertBindable(self::TYPE, '987654', (int) $admin->getId());

        $this->addToAssertionCount(1);
    }

    public function testAnEmptyValuePassesForEveryone(): void
    {
        $editor = $this->editorWith('qa_bind_empty', null);

        foreach ([null, '', '   '] as $empty) {
            $this->guard->assertBindable(self::TYPE, $empty, (int) $editor->getId());
        }

        $this->addToAssertionCount(1);
    }

    public function testASystemActorPasses(): void
    {
        // No signed-in user (console, fixtures, jobs): nobody to ask (F07). The item has no grant at all.
        $this->guard->assertBindable(self::TYPE, (string) self::ITEM, 0);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesThatAreNoItemId(): iterable
    {
        yield 'text' => ['abc'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'fraction' => ['5.5'];
        yield 'with a suffix' => ['5abc'];
        yield 'a list' => [[self::ITEM]];
    }

    #[DataProvider('valuesThatAreNoItemId')]
    public function testAValueThatIsNoPositiveWholeNumberIsRefusedEvenWithAGrant(mixed $value): void
    {
        $editor = $this->editorWith('qa_bind_shape', DataAccessSecurityService::PERMISSION_READ);

        $refusal = $this->refusal(fn () => $this->guard->assertBindable(self::TYPE, $value, (int) $editor->getId()));

        self::assertSame(ResourceBindingGuard::ERROR_NOT_BINDABLE, $refusal->getMessage());
        self::assertSame(Response::HTTP_FORBIDDEN, $refusal->getCode());
    }

    public function testATypeNoEnabledPluginRegistersIsAProgrammingError(): void
    {
        $editor = $this->editorWith('qa_bind_type', DataAccessSecurityService::PERMISSION_READ);

        $this->expectException(\InvalidArgumentException::class);
        $this->guard->assertBindable('qa_unregistered_type', (string) self::ITEM, (int) $editor->getId());
    }

    // -- helpers ------------------------------------------------------------------------

    /**
     * A user whose only role holds `$bits` on the item (no grant row when `$bits` is null).
     */
    private function editorWith(string $prefix, ?int $bits, int $itemId = self::ITEM): User
    {
        $role = $this->roles->createRole($prefix . '_role');
        $user = $this->users->createUser($prefix . '@selfhelp.test', 'QA ' . $prefix, roles: [$role]);
        if ($bits !== null) {
            $this->grants->grantResourceAccess($role, self::TYPE, $itemId, $bits);
        }
        $this->service(DataAccessSecurityService::class)->invalidateUserPermissions((int) $user->getId());

        return $user;
    }

    private function refusal(\Closure $call): ServiceException
    {
        try {
            $call();
        } catch (ServiceException $e) {
            return $e;
        }
        self::fail('Expected a ServiceException.');
    }
}
