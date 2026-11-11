<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-647')]
final class UserRepositorySearchByCharacterTest extends IntegrationTestCase
{
    private const int MIRA_EVE_CHARACTER_ID = 2_113_647_001;
    private const int DAX_EVE_CHARACTER_ID = 2_113_647_002;

    private UserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(UserRepository::class);
    }

    public function testANameFragmentMatchesWithoutCaseSensitivity(): void
    {
        $mira = $this->userWithCharacter('Mira Tolland', self::MIRA_EVE_CHARACTER_ID);
        $this->userWithCharacter('Dax Orrin', self::DAX_EVE_CHARACTER_ID);
        $this->flushAndClear();

        $found = $this->repository->searchByCharacter('tOLL', 20);

        self::assertSame([(string) $mira->getId()], array_map(static fn (User $user): string => (string) $user->getId(), $found));
    }

    public function testADigitsOnlyTermMatchesTheExactEveCharacterId(): void
    {
        $mira = $this->userWithCharacter('Mira Tolland', self::MIRA_EVE_CHARACTER_ID);
        $this->userWithCharacter('Dax Orrin', self::DAX_EVE_CHARACTER_ID);
        $this->flushAndClear();

        $found = $this->repository->searchByCharacter((string) self::MIRA_EVE_CHARACTER_ID, 20);

        self::assertSame([(string) $mira->getId()], array_map(static fn (User $user): string => (string) $user->getId(), $found));
    }

    public function testADigitsOnlyTermAlsoMatchesANameContainingIt(): void
    {
        $this->userWithCharacter('Pilot 4242', self::MIRA_EVE_CHARACTER_ID);
        $this->flushAndClear();

        self::assertCount(1, $this->repository->searchByCharacter('4242', 20));
    }

    public function testAPartialEveCharacterIdDoesNotMatch(): void
    {
        $this->userWithCharacter('Mira Tolland', self::MIRA_EVE_CHARACTER_ID);
        $this->flushAndClear();

        self::assertSame([], $this->repository->searchByCharacter('2113647', 20));
    }

    public function testAUserWithSeveralMatchingCharactersIsReturnedOnce(): void
    {
        $user = $this->userWithCharacter('Mira One', self::MIRA_EVE_CHARACTER_ID);
        $second = (new Character())
            ->setEveCharacterId(self::DAX_EVE_CHARACTER_ID)
            ->setName('Mira Two')
            ->setCorporationId(98_000_001)
            ->setCorporationName('Test Corp');
        $user->addCharacter($second);
        $this->em->persist($second);
        $this->flushAndClear();

        self::assertCount(1, $this->repository->searchByCharacter('mira', 20));
    }

    public function testTheResultsAreCappedAtTheLimitAndOrderedByMainCharacterName(): void
    {
        foreach (['Cara', 'Abel', 'Bryn'] as $offset => $name) {
            $this->userWithCharacter($name . ' Searchable', self::MIRA_EVE_CHARACTER_ID + 10 + $offset);
        }
        $this->flushAndClear();

        $found = $this->repository->searchByCharacter('searchable', 2);

        self::assertSame(['Abel Searchable', 'Bryn Searchable'], array_map(static fn (User $user): string => $user->getMainCharacter()?->getName() ?? '', $found));
    }

    private function userWithCharacter(string $name, int $eveCharacterId): User
    {
        $user = $this->createUser();
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_000_001)
            ->setCorporationName('Test Corp');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);

        return $user;
    }
}
