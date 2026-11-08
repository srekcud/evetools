<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonChunk;

use App\Entity\Character;
use App\Entity\MoonRefineryFollow;
use App\Entity\User;
use App\Repository\MoonRefineryFollowRepository;
use Symfony\Component\Uid\Uuid;

trait StoresMoonRefineryFollows
{
    private static int $nextFollowerEveCharacterId = 2_112_274_701;

    private array $storedFollows = [];

    private function followRepository(): MoonRefineryFollowRepository
    {
        $repository = $this->createStub(MoonRefineryFollowRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?MoonRefineryFollow => $this->followsMatching($criteria)[0] ?? null,
        );
        $repository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => $this->followsMatching($criteria),
        );
        $repository->method('findAll')->willReturnCallback(fn (): array => $this->storedFollows);

        return $repository;
    }

    private function followerInCorporation(?int $corporationId, string $name): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::v4());
        $character = (new Character())
            ->setEveCharacterId(self::$nextFollowerEveCharacterId++)
            ->setName($name)
            ->setCorporationId($corporationId ?? 98_274_999);
        $user->addCharacter($character);
        if ($corporationId !== null) {
            $user->setMainCharacter($character);
        }

        return $user;
    }

    private function storeFollow(User $user, int $corporationId, int $structureId, string $createdAt): MoonRefineryFollow
    {
        $follow = (new MoonRefineryFollow())
            ->setUser($user)
            ->setCorporationId($corporationId)
            ->setStructureId($structureId)
            ->setCreatedAt(new \DateTimeImmutable($createdAt));
        $this->storedFollows[] = $follow;

        return $follow;
    }

    private function followsMatching(array $criteria): array
    {
        return array_values(array_filter(
            $this->storedFollows,
            static function (MoonRefineryFollow $follow) use ($criteria): bool {
                foreach ($criteria as $field => $expected) {
                    if ($follow->{'get' . ucfirst($field)}() !== $expected) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }
}
