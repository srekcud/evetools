<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Blueprint;

use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\EveToken;
use App\Entity\OwnedBlueprint;
use App\Entity\User;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\OwnedBlueprintRepository;
use Symfony\Component\Uid\Uuid;

trait BuildsBlueprintLibrary
{
    private const string PERSONAL_SCOPE = 'esi-characters.read_blueprints.v1';
    private const string CORPORATION_SCOPE = 'esi-corporations.read_blueprints.v1';
    private const string MAIN_USER_ID = '0199a1f0-0000-7000-8000-000000357001';
    private const string OTHER_USER_ID = '0199a1f0-0000-7000-8000-000000357002';
    private const int MAIN_CORPORATION_ID = 98_357_001;
    private const string MAIN_CORPORATION_NAME = 'Solberg Industries';
    private const int ALT_CORPORATION_ID = 98_357_002;
    private const int OTHER_CORPORATION_ID = 98_357_003;

    private const int DRAKE_BLUEPRINT = 16_230;
    private const int HAMMERHEAD_II_BLUEPRINT = 17_300;
    private const int VEXOR_BLUEPRINT = 18_400;
    private const int RIFTER_BLUEPRINT = 19_500;
    private const int ATRON_BLUEPRINT = 20_600;
    private const int HURRICANE_BLUEPRINT = 21_700;
    private const int UNKNOWN_BLUEPRINT = 29_900;

    private const array BLUEPRINT_NAMES = [
        self::DRAKE_BLUEPRINT => 'Drake Blueprint',
        self::HAMMERHEAD_II_BLUEPRINT => 'Hammerhead II Blueprint',
        self::VEXOR_BLUEPRINT => 'Vexor Blueprint',
        self::RIFTER_BLUEPRINT => 'Rifter Blueprint',
        self::ATRON_BLUEPRINT => 'Atron Blueprint',
        self::HURRICANE_BLUEPRINT => 'Hurricane Blueprint',
    ];

    private static int $nextEveCharacterId = 2_113_357_001;

    private function pilot(string $name, int $corporationId, string $corporationName, array $scopes): Character
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId($corporationId)
            ->setCorporationName($corporationName);
        $character->setEveToken((new EveToken())->setScopes($scopes));

        return $character;
    }

    private function userOf(string $userId, Character ...$characters): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString($userId));
        foreach ($characters as $character) {
            $user->addCharacter($character);
        }
        $user->setMainCharacter($characters[0]);

        return $user;
    }

    private function personalBlueprint(
        Character $character,
        int $itemId,
        int $typeId,
        int $quantity,
        int $runs,
        int $materialEfficiency,
        int $timeEfficiency,
        int $locationId = 60_003_760,
    ): OwnedBlueprint {
        return (new OwnedBlueprint())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setLocationId($locationId)
            ->setLocationFlag('Hangar')
            ->setQuantity($quantity)
            ->setRuns($runs)
            ->setMaterialEfficiency($materialEfficiency)
            ->setTimeEfficiency($timeEfficiency)
            ->setCharacter($character)
            ->setSyncedAt(new \DateTimeImmutable('2026-11-07 02:00:00'));
    }

    private function corporationBlueprint(
        int $corporationId,
        int $itemId,
        int $typeId,
        int $quantity,
        int $runs,
        int $materialEfficiency,
        int $timeEfficiency,
        ?int $divisionNumber,
        int $locationId = 1_040_000_000_001,
    ): OwnedBlueprint {
        return (new OwnedBlueprint())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setLocationId($locationId)
            ->setLocationFlag($divisionNumber === null ? 'Unlocked' : 'CorpSAG' . $divisionNumber)
            ->setQuantity($quantity)
            ->setRuns($runs)
            ->setMaterialEfficiency($materialEfficiency)
            ->setTimeEfficiency($timeEfficiency)
            ->setCorporationId($corporationId)
            ->setDivisionNumber($divisionNumber)
            ->setSyncedAt(new \DateTimeImmutable('2026-11-07 02:00:00'));
    }

    private function repositoryHolding(array $blueprints): OwnedBlueprintRepository
    {
        $repository = $this->createStub(OwnedBlueprintRepository::class);
        $repository->method('findByCharacters')->willReturnCallback(
            static fn (array $characters): array => array_values(array_filter(
                $blueprints,
                static fn (OwnedBlueprint $blueprint): bool => $blueprint->getCharacter() !== null && in_array($blueprint->getCharacter(), $characters, true),
            )),
        );
        $repository->method('findByCorporationId')->willReturnCallback(
            static fn (int $corporationId): array => array_values(array_filter(
                $blueprints,
                static fn (OwnedBlueprint $blueprint): bool => $blueprint->getCorporationId() === $corporationId,
            )),
        );

        return $repository;
    }

    private function visibilityOf(array $visibleDivisionsByCorporationId): CorpAssetVisibilityRepository
    {
        $repository = $this->createStub(CorpAssetVisibilityRepository::class);
        $repository->method('findByCorporationId')->willReturnCallback(
            static fn (int $corporationId): ?CorpAssetVisibility => isset($visibleDivisionsByCorporationId[$corporationId])
                ? (new CorpAssetVisibility())->setCorporationId($corporationId)->setVisibleDivisions($visibleDivisionsByCorporationId[$corporationId])
                : null,
        );

        return $repository;
    }
}
