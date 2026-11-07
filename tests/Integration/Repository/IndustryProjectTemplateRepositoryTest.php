<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProjectTemplate;
use App\Entity\User;
use App\Repository\IndustryProjectTemplateRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-355')]
final class IndustryProjectTemplateRepositoryTest extends IntegrationTestCase
{
    private const int ALICE_CORPORATION_ID = 98_355_001;
    private const int CAROL_CORPORATION_ID = 98_355_002;

    private IndustryProjectTemplateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectTemplateRepository::class);
    }

    public function testCorpmateSeesTheOwnTemplatesAndTheSharedOnesOfTheSameCorporationOnly(): void
    {
        $alice = $this->createPilotWithMainCharacter('Alice Ferrand', self::ALICE_CORPORATION_ID);
        $bob = $this->createPilotWithMainCharacter('Bob Lenoir', self::ALICE_CORPORATION_ID);
        $carol = $this->createPilotWithMainCharacter('Carol Dumas', self::CAROL_CORPORATION_ID);
        $aliceShared = $this->createTemplate($alice, 'Hammerhead line', true, '2026-11-02T09:00:00+00:00');
        $this->createTemplate($alice, 'Private Warrior line', false, '2026-11-02T10:00:00+00:00');
        $carolShared = $this->createTemplate($carol, 'Carol Sabre line', true, '2026-11-02T11:00:00+00:00');
        $bobOwn = $this->createTemplate($bob, 'Bob Drake line', false, '2026-11-02T12:00:00+00:00');
        $this->flushAndClear();

        $seenByBob = $this->idsOf($this->repository->findVisibleTo($this->em->getReference(User::class, $bob->getId())));
        $seenByCarol = $this->idsOf($this->repository->findVisibleTo($this->em->getReference(User::class, $carol->getId())));

        self::assertSame([(string) $bobOwn->getId(), (string) $aliceShared->getId()], $seenByBob);
        self::assertSame([(string) $carolShared->getId()], $seenByCarol);
    }

    public function testUserWithoutMainCharacterSeesOnlyTheOwnTemplates(): void
    {
        $alice = $this->createPilotWithMainCharacter('Alice Ferrand', self::ALICE_CORPORATION_ID);
        $bob = $this->createUser();
        $this->createTemplate($alice, 'Hammerhead line', true, '2026-11-02T09:00:00+00:00');
        $bobOwn = $this->createTemplate($bob, 'Bob Drake line', false, '2026-11-02T12:00:00+00:00');
        $this->flushAndClear();

        $seenByBob = $this->idsOf($this->repository->findVisibleTo($this->em->getReference(User::class, $bob->getId())));

        self::assertSame([(string) $bobOwn->getId()], $seenByBob);
    }

    public function testVisibleTemplatesAreSortedByUpdateDateMostRecentFirst(): void
    {
        $alice = $this->createPilotWithMainCharacter('Alice Ferrand', self::ALICE_CORPORATION_ID);
        $oldest = $this->createTemplate($alice, 'Hammerhead line', false, '2026-11-01T08:00:00+00:00');
        $newest = $this->createTemplate($alice, 'Warrior line', false, '2026-11-05T08:00:00+00:00');
        $middle = $this->createTemplate($alice, 'Sabre line', false, '2026-11-03T08:00:00+00:00');
        $this->flushAndClear();

        $seenByAlice = $this->idsOf($this->repository->findVisibleTo($this->em->getReference(User::class, $alice->getId())));

        self::assertSame([(string) $newest->getId(), (string) $middle->getId(), (string) $oldest->getId()], $seenByAlice);
    }

    private function createPilotWithMainCharacter(string $name, int $corporationId): User
    {
        $user = $this->createUser();
        $character = $this->createCharacter($user, $name)
            ->setCorporationId($corporationId)
            ->setCorporationName(sprintf('Corporation %d', $corporationId));
        $user->setMainCharacter($character);

        return $user;
    }

    private function createTemplate(User $owner, string $name, bool $sharedWithCorporation, string $updatedAt): IndustryProjectTemplate
    {
        $template = (new IndustryProjectTemplate())
            ->setOwner($owner)
            ->setName($name)
            ->setItems([['typeId' => 2446, 'typeName' => 'Warrior II', 'meLevel' => 5, 'teLevel' => 10, 'runs' => 20]])
            ->setSharedWithCorporation($sharedWithCorporation)
            ->setUpdatedAt(new \DateTimeImmutable($updatedAt));
        $this->em->persist($template);

        return $template;
    }

    /**
     * @param list<IndustryProjectTemplate> $templates
     *
     * @return list<string>
     */
    private function idsOf(array $templates): array
    {
        return array_map(static fn (IndustryProjectTemplate $template): string => (string) $template->getId(), $templates);
    }
}
