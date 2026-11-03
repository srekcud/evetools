<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningFrozenUnitPrice;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Enum\MarketSide;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningSplitMode;
use App\Service\MiningOperation\MiningShareFreezer;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[CoversClass(MiningShareFreezer::class)]
#[CoversClass(MiningFrozenUnitPrice::class)]
#[CoversClass(MiningFrozenMinedValue::class)]
#[Group('issue-264')]
#[AllowMockObjectsWithoutExpectations]
final class MiningShareFreezerTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private EntityManagerInterface&MockObject $entityManager;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->clock = new MockClock(self::FROZEN_AT);
    }

    public function testFreezingE1CopiesTheCurrentPricesAndTheMinedValueOfEachParticipantInLedgerOrder(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation());
        $this->entityManager->expects($this->once())->method('flush');

        $this->freezerOn($this->jitaSellPrices())->freeze($operation);

        self::assertSame(
            [
                'status' => 'shares',
                'sharesFrozenAt' => self::FROZEN_AT,
                'pricesSyncedAt' => self::JITA_SYNCED_AT,
                'unitPrices' => [self::VELDSPAR => 19.61, self::BITUMENS => 221.45],
                'minedValues' => [
                    [0, 'Aria Solberg', true, 2_699_093.0],
                    [1, 'Brann Holt', true, 2_868_460.0],
                    [2, 'Cyra Lune', true, 176.0],
                ],
            ],
            $this->frozenStateOf($operation),
        );
    }

    public function testOnlyAClosingOperationCanHaveItsSharesFrozen(): void
    {
        $operation = $this->startedSpodumainOperation();
        $this->entityManager->expects($this->never())->method('flush');

        self::assertSame(
            'Only a closing operation can have its shares frozen',
            $this->refusalOf($operation),
        );
    }

    public function testFrozenSharesCannotBeFrozenAgain(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation());
        $freezer = $this->freezerOn($this->jitaSellPrices());
        $freezer->freeze($operation);

        self::assertSame('Only a closing operation can have its shares frozen', $this->refusalOf($operation, $freezer));
    }

    public function testEachForThemselvesOperationHasNoShareToFreeze(): void
    {
        $operation = $this->e1Operation(splitMode: MiningSplitMode::EachForThemselves);
        $operation->updateValuationSettings(valuationSide: MarketSide::Sell);

        self::assertSame('An each-for-themselves operation has no shares', $this->refusalOf($operation));
    }

    public function testUnknownQuantityOfBrannIsRefusedAndNothingIsFrozen(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation(unreadStarts: ['Brann II']));
        $this->entityManager->expects($this->never())->method('flush');

        self::assertSame(
            [
                'refusal' => 'Every mined quantity must be known before freezing the shares: Brann Holt',
                'state' => ['status' => 'closing', 'sharesFrozenAt' => null, 'pricesSyncedAt' => null, 'unitPrices' => [], 'minedValues' => []],
            ],
            ['refusal' => $this->refusalOf($operation), 'state' => $this->frozenStateOf($operation)],
        );
    }

    public function testEveryParticipantWithAnUnknownOrMeasuredQuantityIsNamedInLedgerOrder(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation(unreadStarts: ['Brann II'], waitingEnds: ['Cyra Lune']));

        self::assertSame(
            'Every mined quantity must be known before freezing the shares: Brann Holt, Cyra Lune',
            $this->refusalOf($operation),
        );
    }

    public function testOreWithoutPriceOnTheBasisIsRefusedWithItsName(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation());
        $jitaSellPrices = $this->jitaSellPrices();
        unset($jitaSellPrices[self::COMPRESSED_BITUMENS]);

        self::assertSame(
            'Some ores have no price on the valuation basis: Bitumens',
            $this->refusalOf($operation, $this->freezerOn($jitaSellPrices)),
        );
    }

    public function testOresWithoutPriceAreNamedByTypeId(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation());

        self::assertSame(
            'Some ores have no price on the valuation basis: Veldspar, Bitumens',
            $this->refusalOf($operation, $this->freezerOn($this->jitaSellPricesWithoutCompressedOre())),
        );
    }

    public function testBoosterCutWithoutBoosterIsRefused(): void
    {
        $operation = $this->e1Operation();
        $operation->updateValuationSettings(valuationSide: MarketSide::Sell, boosterCutPercent: 10);

        self::assertSame('Choose the booster who receives the booster cut', $this->refusalOf($operation));
    }

    public function testBoosterRemovedSinceHeWasChosenIsRefused(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation());
        $this->membershipOf($operation, $this->cyra)->remove();

        self::assertSame('Choose the booster who receives the booster cut', $this->refusalOf($operation));
    }

    public function testOperationWithoutBoosterCutNeedsNoBooster(): void
    {
        $operation = $this->e1Operation();
        $operation->updateValuationSettings(valuationSide: MarketSide::Sell);

        $this->freezerOn($this->jitaSellPrices())->freeze($operation);

        self::assertSame(MiningOperationStatus::Shares, $operation->getStatus());
    }

    private function freezerOn(array $jitaSellPrices): MiningShareFreezer
    {
        return new MiningShareFreezer(
            $this->valuatorOn($this->jitaMarketSyncedAt($jitaSellPrices), $this->clock),
            $this->typeNameResolverOfSdeOres(),
            $this->entityManager,
            $this->clock,
        );
    }

    private function refusalOf(MiningOperation $operation, ?MiningShareFreezer $freezer = null): ?string
    {
        try {
            ($freezer ?? $this->freezerOn($this->jitaSellPrices()))->freeze($operation);
        } catch (BadRequestHttpException $refusal) {
            return $refusal->getMessage();
        }

        return null;
    }

    private function frozenStateOf(MiningOperation $operation): array
    {
        $unitPrices = [];
        foreach ($operation->getFrozenUnitPrices() as $frozenUnitPrice) {
            $unitPrices[$frozenUnitPrice->getTypeId()] = $frozenUnitPrice->getUnitPrice();
        }
        ksort($unitPrices);
        $minedValues = array_map(
            fn (MiningFrozenMinedValue $frozenMinedValue): array => [
                $frozenMinedValue->getPosition(),
                $frozenMinedValue->getParticipantName(),
                $frozenMinedValue->getMember() === $this->memberNamed($operation, $frozenMinedValue->getParticipantName()),
                $frozenMinedValue->getMinedValue(),
            ],
            array_values($operation->getFrozenMinedValues()->toArray()),
        );
        usort($minedValues, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return [
            'status' => $operation->getStatus()->value,
            'sharesFrozenAt' => $operation->getSharesFrozenAt()?->format(\DateTimeInterface::ATOM),
            'pricesSyncedAt' => $operation->getPricesSyncedAt()?->format(\DateTimeInterface::ATOM),
            'unitPrices' => $unitPrices,
            'minedValues' => $minedValues,
        ];
    }

    private function memberNamed(MiningOperation $operation, ?string $mainCharacterName): ?MiningOperationMember
    {
        foreach ($operation->getMembers() as $member) {
            if ($member->getUser()->getMainCharacter()?->getName() === $mainCharacterName) {
                return $member;
            }
        }

        return null;
    }
}
