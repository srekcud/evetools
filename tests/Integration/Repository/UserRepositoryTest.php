<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

#[Group('issue-358')]
final class UserRepositoryTest extends IntegrationTestCase
{
    private UserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(UserRepository::class);
    }

    public function testFindActiveWithCharactersKeepsAUserWhoseAltIsRevokedWithAllItsCharacters(): void
    {
        $user = $this->createUser()->setLastLoginAt(new \DateTimeImmutable('-2 days'));
        $user->setMainCharacter($this->createCharacterWithToken($user, 'Main Pilot'));
        $this->createCharacterWithToken($user, 'Revoked Alt Pilot')->markAuthInvalid();
        $userId = $user->getId();
        $this->flushAndClear();

        $foundUser = $this->activeUserWithId($userId);

        self::assertNotNull($foundUser, 'a user with one valid character must stay in the scheduled syncs');
        self::assertSame(['Main Pilot', 'Revoked Alt Pilot'], $this->characterNamesOf($foundUser));
    }

    public function testFindActiveWithCharactersSkipsAUserWhoseCharactersAreAllRevoked(): void
    {
        $user = $this->createUser()->setLastLoginAt(new \DateTimeImmutable('-2 days'));
        $user->setMainCharacter($this->createCharacterWithToken($user, 'Revoked Main Pilot')->markAuthInvalid());
        $this->createCharacterWithToken($user, 'Revoked Alt Pilot')->markAuthInvalid();
        $userId = $user->getId();
        $this->flushAndClear();

        self::assertNotContains($userId->toRfc4122(), $this->activeUserIds());
    }

    public function testFindActiveWithCharactersSkipsAUserWhoDidNotLogInForEightDays(): void
    {
        $user = $this->createUser()->setLastLoginAt(new \DateTimeImmutable('-8 days'));
        $user->setMainCharacter($this->createCharacterWithToken($user, 'Inactive Main Pilot'));
        $userId = $user->getId();
        $this->flushAndClear();

        self::assertNotContains($userId->toRfc4122(), $this->activeUserIds());
    }

    public function testFindPlayersOfCorporationReturnsTheUsersWhoseMainCharacterIsInTheCorporationSortedByMainCharacterName(): void
    {
        $zeno = $this->createPlayerOfCorporation('Zeno Aldric', 98_358_001);
        $alice = $this->createPlayerOfCorporation('Alice Voss', 98_358_001);
        $this->createPlayerOfCorporation('Bob Tamm', 98_358_002);
        $this->createCharacter(name: 'Pilot Without Main')->setCorporationId(98_358_001);
        $mainElsewhere = $this->createPlayerOfCorporation('Mira Sol', 98_358_002);
        $mainElsewhere->addCharacter($this->createCharacter($mainElsewhere, 'Mira Alt')->setCorporationId(98_358_001));
        $this->flushAndClear();

        $players = $this->repository->findPlayersOfCorporation(98_358_001);

        self::assertSame(
            [$alice->getId()->toRfc4122(), $zeno->getId()->toRfc4122()],
            array_map(static fn (User $player): string => $player->getId()->toRfc4122(), $players),
        );
        self::assertSame(['Alice Voss', 'Zeno Aldric'], array_map(static fn (User $player): string => $player->getMainCharacter()->getName(), $players));
    }

    private function createPlayerOfCorporation(string $mainCharacterName, int $corporationId): User
    {
        $user = $this->createUser();
        $user->setMainCharacter($this->createCharacter($user, $mainCharacterName)->setCorporationId($corporationId));

        return $user;
    }

    private function activeUserWithId(?Uuid $userId): ?User
    {
        foreach ($this->repository->findActiveWithCharacters() as $user) {
            if ($user->getId()?->equals($userId) === true) {
                return $user;
            }
        }

        return null;
    }

    private function activeUserIds(): array
    {
        return array_map(
            static fn (User $user): string => $user->getId()->toRfc4122(),
            $this->repository->findActiveWithCharacters(),
        );
    }

    private function characterNamesOf(User $user): array
    {
        $names = $user->getCharacters()->map(static fn (Character $character): string => $character->getName())->toArray();
        sort($names);

        return $names;
    }

    private function createCharacterWithToken(User $user, string $name): Character
    {
        $character = $this->createCharacter($user, $name);
        $token = (new EveToken())
            ->setAccessToken('access-' . $name)
            ->setRefreshTokenEncrypted('refresh-' . $name)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'));
        $character->setEveToken($token);
        $this->em->persist($token);

        return $character;
    }
}
