<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Pve\DetectedSaleResource;
use App\ApiResource\Pve\ScanLootSalesResultResource;
use App\Entity\UserPveSettings;
use App\Service\Pve\CorpProjectContributionReader;
use App\State\Processor\Pve\ScanLootSalesProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScanLootSalesProcessor::class)]
#[CoversClass(CorpProjectContributionReader::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-243')]
final class ScanLootSalesProcessorCorpProjectDeltaTest extends TestCase
{
    use CorpProjectsEsiSimulation;

    private const VELDSPAR_PROJECT_ID = 'e5f6a7b8-project';
    private const VELDSPAR_PROJECT_NAME = 'Veldspar Haul';
    private const VELDSPAR_REWARD_PER_CONTRIBUTION = 7.25;

    public function testContributionWithNothingImportedIsProposedWholeWithZeroAccountedQuantityAndTheCumulInItsIdentifier(): void
    {
        $result = $this->scanPilotOneContributed(1000);

        $this->assertSame(
            [[-abs(crc32('a1b2c3d4-project_2112000001_1000')), 2112000001, 'Pilot One', 1000, 0, 12500.0, '1000x Tritanium']],
            $this->deltaSummaries($result->detectedSales),
        );
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testContributionGrownSinceTheImportProposesOnlyTheDelta(): void
    {
        $result = $this->scanPilotOneContributed(1600, corpProjectImports: [$this->pairedImport(self::EVE_CHARACTER_ID, 1000, 1000)]);

        $this->assertSame(
            [[-abs(crc32('a1b2c3d4-project_2112000001_1600')), 2112000001, 'Pilot One', 600, 1000, 7500.0, '600x Tritanium']],
            $this->deltaSummaries($result->detectedSales),
        );
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testContributionFullyCoveredByTwoImportsIsNeitherProposedNorAnError(): void
    {
        $result = $this->scanPilotOneContributed(1600, corpProjectImports: [
            $this->pairedImport(self::EVE_CHARACTER_ID, 1000, 1000),
            $this->pairedImport(self::EVE_CHARACTER_ID, 600, 1600),
        ]);

        $this->assertSame([], $this->deltaSummaries($result->detectedSales));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testDeclinedQuantityAddsUpWithTheImportedQuantity(): void
    {
        $settings = new UserPveSettings();
        $settings->addDeclinedCorpProjectQuantity(self::PROJECT_ID, self::EVE_CHARACTER_ID, 600);

        $result = $this->scanPilotOneContributed(1900, corpProjectImports: [$this->pairedImport(self::EVE_CHARACTER_ID, 1000, 1000)], settings: $settings);

        $this->assertSame(
            [[-abs(crc32('a1b2c3d4-project_2112000001_1900')), 2112000001, 'Pilot One', 300, 1600, 3750.0, '300x Tritanium']],
            $this->deltaSummaries($result->detectedSales),
        );
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testDeclinedDeltaIsNotProposedAgainAtTheSameCumul(): void
    {
        $settings = new UserPveSettings();
        $settings->addDeclinedCorpProjectQuantity(self::PROJECT_ID, self::EVE_CHARACTER_ID, 600);

        $result = $this->scanPilotOneContributed(1600, corpProjectImports: [$this->pairedImport(self::EVE_CHARACTER_ID, 1000, 1000)], settings: $settings);

        $this->assertSame([], $this->deltaSummaries($result->detectedSales));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testDeclinedQuantityAloneIsAccountedWithoutAnyImport(): void
    {
        $settings = new UserPveSettings();
        $settings->addDeclinedCorpProjectQuantity(self::PROJECT_ID, self::EVE_CHARACTER_ID, 250);

        $result = $this->scanPilotOneContributed(330, settings: $settings);

        $this->assertSame(
            [[-abs(crc32('a1b2c3d4-project_2112000001_330')), 2112000001, 'Pilot One', 80, 250, 1000.0, '80x Tritanium']],
            $this->deltaSummaries($result->detectedSales),
        );
    }

    public function testLegacyImportCountsForItsRecoveredQuantity(): void
    {
        $result = $this->scanPilotOneContributed(1450, corpProjectImports: [$this->legacyImport(self::EVE_CHARACTER_ID, 1000)]);

        $this->assertSame(
            [[-abs(crc32('a1b2c3d4-project_2112000001_1450')), 2112000001, 'Pilot One', 450, 1000, 5625.0, '450x Tritanium']],
            $this->deltaSummaries($result->detectedSales),
        );
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testLegacyImportAddsUpWithAPairedImportOfTheSamePair(): void
    {
        $result = $this->scanPilotOneContributed(1700, corpProjectImports: [
            $this->legacyImport(self::EVE_CHARACTER_ID, 1000),
            $this->pairedImport(self::EVE_CHARACTER_ID, 450, 1450),
        ]);

        $this->assertSame(
            [[-abs(crc32('a1b2c3d4-project_2112000001_1700')), 2112000001, 'Pilot One', 250, 1450, 3125.0, '250x Tritanium']],
            $this->deltaSummaries($result->detectedSales),
        );
    }

    public function testLegacyImportWithoutRecoveredQuantityLeavesTheAccountedQuantityUnknownAndIsReported(): void
    {
        $result = $this->scanPilotOneContributed(1450, corpProjectImports: [$this->legacyImport(self::EVE_CHARACTER_ID, null)]);

        $this->assertSame([], $this->deltaSummaries($result->detectedSales));
        $this->assertSame(
            [[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot One', 'legacy_import_quantity_unknown', null]],
            $this->errorSummaries($result->corporationProjectErrors),
        );
    }

    public function testLegacyImportWithARecoveredQuantityOfZeroIsKnownAndProposesTheWholeContribution(): void
    {
        $result = $this->scanPilotOneContributed(1450, corpProjectImports: [$this->legacyImport(self::EVE_CHARACTER_ID, 0)]);

        $this->assertSame(
            [[-abs(crc32('a1b2c3d4-project_2112000001_1450')), 2112000001, 'Pilot One', 1450, 0, 18125.0, '1450x Tritanium']],
            $this->deltaSummaries($result->detectedSales),
        );
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testLegacyImportOfAnotherCharacterDoesNotCountForThisPair(): void
    {
        $result = $this->scanPilotOneContributed(1450, corpProjectImports: [$this->legacyImport(self::PILOT_TWO_ID, 1000)]);

        $this->assertSame(
            [[-abs(crc32('a1b2c3d4-project_2112000001_1450')), 2112000001, 'Pilot One', 1450, 0, 18125.0, '1450x Tritanium']],
            $this->deltaSummaries($result->detectedSales),
        );
    }

    public function testLegacyIdentifierDeclinedBeforeLotTwoBlocksThePairUntilTheDeclinedAreReset(): void
    {
        $settings = new UserPveSettings();
        $settings->addDeclinedLootSaleTransactionId(-abs(crc32('a1b2c3d4-project_2112000001')));

        $declinedResult = $this->scanPilotOneContributed(1200, settings: $settings);

        $this->assertSame([], $this->deltaSummaries($declinedResult->detectedSales));
        $this->assertSame([], $this->errorSummaries($declinedResult->corporationProjectErrors));

        $settings->clearDeclinedExcept();
        $resetResult = $this->scanPilotOneContributed(1200, settings: $settings);

        $this->assertSame(
            [[-abs(crc32('a1b2c3d4-project_2112000001_1200')), 2112000001, 'Pilot One', 1200, 0, 15000.0, '1200x Tritanium']],
            $this->deltaSummaries($resetResult->detectedSales),
        );
    }

    public function testCumulLowerThanTheImportedQuantityIsReportedWithoutSale(): void
    {
        $result = $this->scanPilotOneContributed(800, corpProjectImports: [$this->pairedImport(self::EVE_CHARACTER_ID, 1000, 1000)]);

        $this->assertSame([], $this->deltaSummaries($result->detectedSales));
        $this->assertSame(
            [[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot One', 'contribution_below_imported', null]],
            $this->errorSummaries($result->corporationProjectErrors),
        );
    }

    public function testCumulOneBelowTheImportedQuantityIsReportedWithoutSale(): void
    {
        $result = $this->scanPilotOneContributed(999, corpProjectImports: [$this->pairedImport(self::EVE_CHARACTER_ID, 1000, 1000)]);

        $this->assertSame([], $this->deltaSummaries($result->detectedSales));
        $this->assertSame(
            [[self::CORPORATION_NAME, self::PROJECT_NAME, 'Pilot One', 'contribution_below_imported', null]],
            $this->errorSummaries($result->corporationProjectErrors),
        );
    }

    public function testCumulOneAboveTheImportedQuantityProposesADeltaOfOne(): void
    {
        $result = $this->scanPilotOneContributed(1001, corpProjectImports: [$this->pairedImport(self::EVE_CHARACTER_ID, 1000, 1000)]);

        $this->assertSame(
            [[-abs(crc32('a1b2c3d4-project_2112000001_1001')), 2112000001, 'Pilot One', 1, 1000, 12.5, '1x Tritanium']],
            $this->deltaSummaries($result->detectedSales),
        );
    }

    public function testOnlyTheCharacterWithANewContributionIsProposedWhenTheOtherIsFullyImported(): void
    {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses(
                [
                    $this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000),
                    $this->contributorRecord(self::PILOT_TWO_ID, 'Pilot Two', 400),
                ],
                [self::EVE_CHARACTER_ID => 1000, self::PILOT_TWO_ID => 400],
                new \DateTimeImmutable('-2 days'),
            ),
            characters: [
                $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
                $this->character(self::PILOT_TWO_ID, 'Pilot Two', self::CORPORATION_ID, self::CORPORATION_NAME),
            ],
            corpProjectImports: [$this->pairedImport(self::EVE_CHARACTER_ID, 1000, 1000)],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame(
            [[-abs(crc32('a1b2c3d4-project_2112000002_400')), 2112000002, 'Pilot Two', 400, 0, 5000.0, '400x Tritanium']],
            $this->deltaSummaries($result->detectedSales),
        );
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testImportOfOneProjectDoesNotCountForAnotherProjectOfTheSameCharacter(): void
    {
        $contributedAt = new \DateTimeImmutable('-2 days');
        $processor = $this->createProcessor(
            [
                ...$this->projectListPages([
                    $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
                    $this->projectRecord(self::VELDSPAR_PROJECT_ID, self::VELDSPAR_PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
                ]),
                ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)]),
                ...$this->contributorPages(self::VELDSPAR_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1000)]),
                ...$this->projectDetails(self::PROJECT_ID, self::TRITANIUM, self::REWARD_PER_CONTRIBUTION),
                ...$this->projectDetails(self::VELDSPAR_PROJECT_ID, self::VELDSPAR, self::VELDSPAR_REWARD_PER_CONTRIBUTION),
                ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 1000, $contributedAt),
                ...$this->contribution(self::VELDSPAR_PROJECT_ID, self::EVE_CHARACTER_ID, 1000, $contributedAt),
            ],
            corpProjectImports: [$this->pairedImport(self::EVE_CHARACTER_ID, 1000, 1000)],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame(
            [[-abs(crc32('e5f6a7b8-project_2112000001_1000')), 2112000001, 'Pilot One', 1000, 0, 7250.0, '1000x Veldspar']],
            $this->deltaSummaries($result->detectedSales),
        );
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testDeltaOfAContributionOfUnknownRewardHasAnUnknownPrice(): void
    {
        $contributedAt = new \DateTimeImmutable('-2 days');
        $processor = $this->createProcessor(
            [
                ...$this->projectListPages([
                    $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
                ]),
                ...$this->contributorPages(self::PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', 1600)]),
                self::PROJECTS_PATH . '/' . self::PROJECT_ID => $this->jsonResponse([
                    'configuration' => ['deliver_item' => ['items' => [['type_id' => self::TRITANIUM]]]],
                ]),
                ...$this->contribution(self::PROJECT_ID, self::EVE_CHARACTER_ID, 1600, $contributedAt),
            ],
            corpProjectImports: [$this->pairedImport(self::EVE_CHARACTER_ID, 1000, 1000)],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame(
            [[-abs(crc32('a1b2c3d4-project_2112000001_1600')), 2112000001, 'Pilot One', 600, 1000, null, '600x Tritanium']],
            $this->deltaSummaries($result->detectedSales),
        );
    }

    public function testDeltaOfAContributionOlderThanTheWindowIsNotProposed(): void
    {
        $result = $this->scanPilotOneContributed(
            1600,
            corpProjectImports: [$this->pairedImport(self::EVE_CHARACTER_ID, 1000, 1000)],
            contributedAt: new \DateTimeImmutable('-40 days'),
        );

        $this->assertSame([], $this->deltaSummaries($result->detectedSales));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testManuallyCreatedCorpProjectIncomeWithoutPairNorLegacyIdentifierCountsForNoPair(): void
    {
        $result = $this->scanPilotOneContributed(1000, corpProjectImports: [[
            'corpProjectId' => null,
            'corpProjectCharacterId' => null,
            'corpProjectQuantity' => null,
            'transactionId' => null,
        ]]);

        $this->assertSame(
            [[-abs(crc32('a1b2c3d4-project_2112000001_1000')), 2112000001, 'Pilot One', 1000, 0, 12500.0, '1000x Tritanium']],
            $this->deltaSummaries($result->detectedSales),
        );
    }

    private function scanPilotOneContributed(
        int $contributed,
        array $corpProjectImports = [],
        UserPveSettings $settings = new UserPveSettings(),
        \DateTimeImmutable $contributedAt = new \DateTimeImmutable('-2 days'),
    ): ScanLootSalesResultResource {
        $processor = $this->createProcessor(
            $this->tritaniumDriveResponses(
                [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', $contributed)],
                [self::EVE_CHARACTER_ID => $contributed],
                $contributedAt,
            ),
            corpProjectImports: $corpProjectImports,
            settings: $settings,
        );

        return $processor->process(null, new Post());
    }

    private function pairedImport(int $characterId, int $quantity, int $contributedAtImport): array
    {
        return [
            'corpProjectId' => self::PROJECT_ID,
            'corpProjectCharacterId' => $characterId,
            'corpProjectQuantity' => $quantity,
            'transactionId' => -abs(crc32(self::PROJECT_ID . '_' . $characterId . '_' . $contributedAtImport)),
        ];
    }

    private function legacyImport(int $characterId, ?int $recoveredQuantity): array
    {
        return [
            'corpProjectId' => null,
            'corpProjectCharacterId' => null,
            'corpProjectQuantity' => $recoveredQuantity,
            'transactionId' => -abs(crc32(self::PROJECT_ID . '_' . $characterId)),
        ];
    }

    private function deltaSummaries(array $detectedSales): array
    {
        return array_map(
            static fn (DetectedSaleResource $sale): array => [
                $sale->transactionId,
                $sale->characterId,
                $sale->characterName,
                $sale->quantity,
                $sale->accountedQuantity,
                $sale->price,
                $sale->typeName,
            ],
            $detectedSales,
        );
    }
}
