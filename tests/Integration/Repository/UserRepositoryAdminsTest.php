<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Character;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-848')]
final class UserRepositoryAdminsTest extends IntegrationTestCase
{
    private const FIRST_ADMIN_EVE_CHARACTER_ID = 2_113_848_101;
    private const SECOND_ADMIN_EVE_CHARACTER_ID = 2_113_848_102;
    private const PLAYER_EVE_CHARACTER_ID = 2_113_848_103;
    private const ADMIN_ALT_EVE_CHARACTER_ID = 2_113_848_104;

    private UserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(UserRepository::class);
    }

    public function testReturnsTheUsersWhoseMainCharacterIsListed(): void
    {
        $firstAdmin = $this->userWithMain(self::FIRST_ADMIN_EVE_CHARACTER_ID);
        $secondAdmin = $this->userWithMain(self::SECOND_ADMIN_EVE_CHARACTER_ID);
        $this->userWithMain(self::PLAYER_EVE_CHARACTER_ID);
        $this->flushAndClear();

        $admins = $this->repository->findAdminsByMainCharacterEveIds([self::FIRST_ADMIN_EVE_CHARACTER_ID, self::SECOND_ADMIN_EVE_CHARACTER_ID]);

        $ids = array_map(static fn (User $user): string => (string) $user->getId(), $admins);
        sort($ids);
        $expected = [(string) $firstAdmin->getId(), (string) $secondAdmin->getId()];
        sort($expected);
        self::assertSame($expected, $ids);
    }

    public function testIgnoresAListedCharacterThatIsNotTheMainOfItsUser(): void
    {
        $user = $this->userWithMain(self::PLAYER_EVE_CHARACTER_ID);
        $alt = $this->character(self::ADMIN_ALT_EVE_CHARACTER_ID);
        $user->addCharacter($alt);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findAdminsByMainCharacterEveIds([self::ADMIN_ALT_EVE_CHARACTER_ID]));
    }

    public function testIgnoresAUserWithoutMainCharacter(): void
    {
        $this->createUser();
        $this->flushAndClear();

        self::assertSame([], $this->repository->findAdminsByMainCharacterEveIds([self::FIRST_ADMIN_EVE_CHARACTER_ID]));
    }

    public function testReturnsNobodyWhenNoCharacterIsListed(): void
    {
        $this->userWithMain(self::FIRST_ADMIN_EVE_CHARACTER_ID);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findAdminsByMainCharacterEveIds([]));
    }

    private function userWithMain(int $eveCharacterId): User
    {
        $user = $this->createUser();
        $character = $this->character($eveCharacterId);
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        return $user;
    }

    private function character(int $eveCharacterId): Character
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName('Pilot ' . $eveCharacterId)
            ->setCorporationId(98_000_001)
            ->setCorporationName('Test Corp');
        $this->em->persist($character);

        return $character;
    }
}
