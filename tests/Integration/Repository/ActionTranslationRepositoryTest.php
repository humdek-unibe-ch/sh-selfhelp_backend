<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Action;
use App\Entity\ActionTranslation;
use App\Entity\Language;
use App\Repository\ActionTranslationRepository;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\ActionFactory;
use App\Tests\Support\QaKernelTestCase;

/**
 * Integration coverage for {@see ActionTranslationRepository} against the real DB
 * (plan Phase 12: translation repositories).
 *
 * Exercises every query method the admin action-translation API relies on:
 * fetch-all-for-action (ordered by key), language filtering, the
 * key+language unique lookup (hit and miss), the action-scoped id lookup
 * (which must refuse a translation that belongs to a different action), and the
 * scalar key listing. Fixtures use the seeded `de-CH` / `en-GB` languages and
 * `qa_`-prefixed actions; DAMA rolls everything back at tearDown.
 */
final class ActionTranslationRepositoryTest extends QaKernelTestCase
{
    private ActionTranslationRepository $repository;
    private ActionFactory $actions;
    private Language $de;
    private Language $en;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->service(ActionTranslationRepository::class);
        $this->actions = new ActionFactory($this->em);
        $this->de = $this->language('de-CH');
        $this->en = $this->language('en-GB');
    }

    public function testFindByActionIdReturnsEveryLanguageOrderedByKey(): void
    {
        $action = $this->action('qa_action_tr_all');
        $this->addTranslation($action, 'subject', $this->de, 'qa Betreff');
        $this->addTranslation($action, 'body', $this->de, 'qa Inhalt');
        $this->addTranslation($action, 'subject', $this->en, 'qa Subject');

        $result = $this->repository->findByActionId($this->idOf($action));

        self::assertCount(3, $result);
        // Ordered by translationKey ASC: body, subject, subject.
        self::assertSame('body', $result[0]->getTranslationKey());
        self::assertSame('subject', $result[1]->getTranslationKey());
        self::assertSame('subject', $result[2]->getTranslationKey());
    }

    public function testFindByActionIdFiltersByLanguage(): void
    {
        $action = $this->action('qa_action_tr_lang');
        $this->addTranslation($action, 'subject', $this->de, 'qa Betreff');
        $this->addTranslation($action, 'body', $this->de, 'qa Inhalt');
        $this->addTranslation($action, 'subject', $this->en, 'qa Subject');

        $result = $this->repository->findByActionId($this->idOf($action), $this->idOf($this->en));

        self::assertCount(1, $result);
        self::assertSame('subject', $result[0]->getTranslationKey());
        self::assertSame('qa Subject', $result[0]->getContent());
    }

    public function testFindByActionKeyAndLanguageReturnsMatchAndNullWhenMissing(): void
    {
        $action = $this->action('qa_action_tr_key');
        $this->addTranslation($action, 'subject', $this->en, 'qa Subject');

        $match = $this->repository->findByActionKeyAndLanguage($this->idOf($action), 'subject', $this->idOf($this->en));
        self::assertInstanceOf(ActionTranslation::class, $match);
        self::assertSame('qa Subject', $match->getContent());

        $missing = $this->repository->findByActionKeyAndLanguage($this->idOf($action), 'does_not_exist', $this->idOf($this->en));
        self::assertNull($missing, 'A missing translation key must resolve to null.');

        $wrongLanguage = $this->repository->findByActionKeyAndLanguage($this->idOf($action), 'subject', $this->idOf($this->de));
        self::assertNull($wrongLanguage, 'A key that exists only in another language must resolve to null.');
    }

    public function testFindOneByActionAndIdRefusesTranslationOfAnotherAction(): void
    {
        $action = $this->action('qa_action_tr_owner');
        $other = $this->action('qa_action_tr_other');
        $translation = $this->addTranslation($action, 'subject', $this->en, 'qa Subject');
        $translationId = $this->idOf($translation);

        $found = $this->repository->findOneByActionAndId($this->idOf($action), $translationId);
        self::assertInstanceOf(ActionTranslation::class, $found);
        self::assertSame($translationId, $found->getId());

        $crossAction = $this->repository->findOneByActionAndId($this->idOf($other), $translationId);
        self::assertNull($crossAction, 'A translation must not be reachable through a different action id.');
    }

    public function testFindKeysByActionAndLanguageReturnsOrderedKeys(): void
    {
        $action = $this->action('qa_action_tr_keys');
        $this->addTranslation($action, 'subject', $this->de, 'qa Betreff');
        $this->addTranslation($action, 'body', $this->de, 'qa Inhalt');
        // A different language must not bleed into the de-CH key list.
        $this->addTranslation($action, 'footer', $this->en, 'qa Footer');

        $rows = $this->repository->findKeysByActionAndLanguage($this->idOf($action), $this->idOf($this->de));

        $keys = array_map(static fn (array $row): mixed => $row['translationKey'] ?? null, $rows);
        self::assertSame(['body', 'subject'], $keys, 'Keys must be scoped to the language and ordered ASC.');
    }

    private function addTranslation(Action $action, string $key, Language $language, string $content): ActionTranslation
    {
        $translation = new ActionTranslation();
        $translation->setAction($action);
        $translation->setLanguage($language);
        $translation->setTranslationKey($key);
        $translation->setContent($content);
        $this->em->persist($translation);
        $this->em->flush();

        return $translation;
    }

    private function action(string $name): Action
    {
        $dataTable = $this->actions->createDataTable('qa_action_tr_table');

        return $this->actions->createAction(
            $dataTable,
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            [],
            $name
        );
    }

    private function language(string $locale): Language
    {
        $language = $this->em->getRepository(Language::class)->findOneBy(['locale' => $locale]);
        self::assertInstanceOf(Language::class, $language, sprintf('Seeded language "%s" is required.', $locale));

        return $language;
    }

    private function idOf(Action|ActionTranslation|Language $entity): int
    {
        $id = $entity->getId();
        self::assertIsInt($id);

        return $id;
    }
}
