<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Character;
use App\Entity\OwnedBlueprint;
use App\Repository\OwnedBlueprintRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-357')]
final class OwnedBlueprintRepositoryTest extends IntegrationTestCase
{
    private const int CORPORATION_ID = 98_357_201;
    private const int OTHER_CORPORATION_ID = 98_357_202;

    private OwnedBlueprintRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(OwnedBlueprintRepository::class);
    }

    public function testDeletingTheBlueprintsOfACharacterLeavesTheOnesOfAnotherCharacterAndOfCorporationsUntouched(): void
    {
        $aria = $this->createCharacter(name: 'Aria Solberg');
        $brann = $this->createCharacter(name: 'Brann Holt');
        $this->persistBlueprint(1_001, 16_230, $aria, null);
        $this->persistBlueprint(1_002, 17_300, $aria, null);
        $this->persistBlueprint(1_003, 18_400, $brann, null);
        $this->persistBlueprint(1_004, 19_500, null, self::CORPORATION_ID);
        $this->flushAndClear();

        $deleted = $this->repository->deleteByCharacter($this->em->find(Character::class, $aria->getId()));
        $this->em->clear();

        self::assertSame(2, $deleted);
        self::assertSame([1_003, 1_004], $this->remainingItemIds());
    }

    public function testDeletingTheBlueprintsOfACorporationLeavesTheOnesOfAnotherCorporationAndOfCharactersUntouched(): void
    {
        $aria = $this->createCharacter(name: 'Aria Solberg');
        $this->persistBlueprint(2_001, 16_230, $aria, null);
        $this->persistBlueprint(2_002, 17_300, null, self::CORPORATION_ID);
        $this->persistBlueprint(2_003, 18_400, null, self::CORPORATION_ID);
        $this->persistBlueprint(2_004, 19_500, null, self::OTHER_CORPORATION_ID);
        $this->flushAndClear();

        $deleted = $this->repository->deleteByCorporationId(self::CORPORATION_ID);
        $this->em->clear();

        self::assertSame(2, $deleted);
        self::assertSame([2_001, 2_004], $this->remainingItemIds());
    }

    private function persistBlueprint(int $itemId, int $typeId, ?Character $character, ?int $corporationId): void
    {
        $this->em->persist(
            (new OwnedBlueprint())
                ->setItemId($itemId)
                ->setTypeId($typeId)
                ->setLocationId(60_003_760)
                ->setLocationFlag($corporationId === null ? 'Hangar' : 'CorpSAG1')
                ->setQuantity(-1)
                ->setRuns(-1)
                ->setMaterialEfficiency(10)
                ->setTimeEfficiency(20)
                ->setCharacter($character)
                ->setCorporationId($corporationId)
                ->setDivisionNumber($corporationId === null ? null : 1)
                ->setSyncedAt(new \DateTimeImmutable('2026-11-07 02:00:00')),
        );
    }

    private function remainingItemIds(): array
    {
        $itemIds = array_map(
            static fn (OwnedBlueprint $blueprint): int => $blueprint->getItemId(),
            $this->repository->findAll(),
        );
        sort($itemIds);

        return $itemIds;
    }
}
