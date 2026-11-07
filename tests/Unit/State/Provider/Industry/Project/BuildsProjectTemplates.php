<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProjectTemplate;
use App\Entity\User;
use PHPUnit\Framework\MockObject\Stub;

trait BuildsProjectTemplates
{
    private const int HAMMERHEAD_II_TYPE_ID = 2185;
    private const int WARRIOR_II_TYPE_ID = 2446;

    private function pilot(string $mainCharacterName, ?int $corporationId): User&Stub
    {
        $mainCharacter = $this->createStub(Character::class);
        $mainCharacter->method('getName')->willReturn($mainCharacterName);

        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($mainCharacter);
        $user->method('getCorporationId')->willReturn($corporationId);

        return $user;
    }

    private function templateOf(User $owner, string $name, bool $sharedWithCorporation): IndustryProjectTemplate
    {
        return (new IndustryProjectTemplate())
            ->setOwner($owner)
            ->setName($name)
            ->setItems([
                ['typeId' => self::HAMMERHEAD_II_TYPE_ID, 'typeName' => 'Hammerhead II', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 50],
                ['typeId' => self::WARRIOR_II_TYPE_ID, 'typeName' => 'Warrior II', 'meLevel' => 5, 'teLevel' => 10, 'runs' => 20],
            ])
            ->setBlacklistGroupIds([18])
            ->setBlacklistTypeIds([34])
            ->setAcceptsOutsideFavoriteSystem(true)
            ->setMinimumOutsideSaving(5_000_000.0)
            ->setSharedWithCorporation($sharedWithCorporation);
    }
}
