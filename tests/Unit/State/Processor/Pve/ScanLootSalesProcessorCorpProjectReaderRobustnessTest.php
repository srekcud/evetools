<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\State\Processor\Pve\ScanLootSalesProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(ScanLootSalesProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-243')]
final class ScanLootSalesProcessorCorpProjectReaderRobustnessTest extends TestCase
{
    use CorpProjectsEsiSimulation;

    private const VELDSPAR_PROJECT_ID = 'e5f6a7b8-project';
    private const VELDSPAR_PROJECT_NAME = 'Veldspar Haul';
    private const VELDSPAR_REWARD_PER_CONTRIBUTION = 7.25;

    public function testReaderRefusedWhenAskedForTheContributorsSkipsTheProjectAndKeepsTheOtherCorporationSale(): void
    {
        $readerCache = new ArrayAdapter();
        $this->cacheTritaniumDriveProjectList($readerCache);
        $processor = $this->createProcessor(
            $this->secondCorporationProjectResponses(self::VELDSPAR_PROJECT_ID, self::VELDSPAR_PROJECT_NAME, self::VELDSPAR, self::VELDSPAR_REWARD_PER_CONTRIBUTION, self::PILOT_THREE_ID, 240, new \DateTimeImmutable('-3 days')),
            characters: $this->pilotThreeOfTheSecondCorporationThenPilotOne(),
            readerCache: $readerCache,
            lootTypeIds: [],
            acceptedAccessTokenCallsByCharacterId: [self::EVE_CHARACTER_ID => 1],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([[self::VELDSPAR_PROJECT_NAME, 'Pilot Three', 240, 1740.0]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testReaderRefusedWhenAskedForTheProjectDetailSkipsTheProjectAndKeepsTheOtherCorporationSale(): void
    {
        $readerCache = new ArrayAdapter();
        $this->cacheTritaniumDriveProjectList($readerCache);
        $cachedContributors = $readerCache->getItem('corp_project_contributors_' . self::CORPORATION_ID . '_' . self::PROJECT_ID);
        $cachedContributors->set([self::EVE_CHARACTER_ID]);
        $readerCache->save($cachedContributors);
        $processor = $this->createProcessor(
            $this->secondCorporationProjectResponses(self::VELDSPAR_PROJECT_ID, self::VELDSPAR_PROJECT_NAME, self::VELDSPAR, self::VELDSPAR_REWARD_PER_CONTRIBUTION, self::PILOT_THREE_ID, 160, new \DateTimeImmutable('-3 days')),
            characters: $this->pilotThreeOfTheSecondCorporationThenPilotOne(),
            readerCache: $readerCache,
            lootTypeIds: [],
            acceptedAccessTokenCallsByCharacterId: [self::EVE_CHARACTER_ID => 1],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame([[self::VELDSPAR_PROJECT_NAME, 'Pilot Three', 160, 1160.0]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testListedProjectWithoutIdIsAnUnexpectedResponseOfTheProjectAndTheNextProjectIsStillRead(): void
    {
        $processor = $this->createProcessor($this->tritaniumDriveRecordThenVeldsparHaul(
            [
                'name' => self::PROJECT_NAME,
                'state' => 'Active',
                'last_modified' => $this->esiDate(new \DateTimeImmutable('-1 day')),
            ],
            600,
        ));

        $result = $processor->process(null, new Post());

        $this->assertSame(2, $result->scannedProjects);
        $this->assertSame([[self::VELDSPAR_PROJECT_NAME, 'Pilot One', 600, 4350.0]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, null, 'unexpected_response', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testListedProjectWithoutStateIsAnUnexpectedResponseOfTheProjectAndTheNextProjectIsStillRead(): void
    {
        $processor = $this->createProcessor($this->tritaniumDriveRecordThenVeldsparHaul(
            [
                'id' => self::PROJECT_ID,
                'name' => self::PROJECT_NAME,
                'last_modified' => $this->esiDate(new \DateTimeImmutable('-1 day')),
            ],
            380,
        ));

        $result = $processor->process(null, new Post());

        $this->assertNotContains($this->contributorsPageKey(self::PROJECT_ID, null), $this->requestedPaths());
        $this->assertSame(2, $result->scannedProjects);
        $this->assertSame([[self::VELDSPAR_PROJECT_NAME, 'Pilot One', 380, 2755.0]], $this->contributionSummaries($result->detectedSales));
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, null, 'unexpected_response', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    public function testContributorWithoutIdIsAnUnexpectedResponseOfTheProject(): void
    {
        $processor = $this->createProcessor([
            ...$this->projectListPages([
                $this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day')),
            ]),
            $this->contributorsPageKey(self::PROJECT_ID, null) => $this->jsonResponse([
                'contributors' => [['name' => 'Pilot One', 'contributed' => 730]],
                'cursor' => ['after' => 'contributors-after-1'],
            ]),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertSame(1, $result->scannedProjects);
        $this->assertSame([], $result->detectedSales);
        $this->assertSame([[self::CORPORATION_NAME, self::PROJECT_NAME, null, 'unexpected_response', null]], $this->errorSummaries($result->corporationProjectErrors));
    }

    private function cacheTritaniumDriveProjectList(ArrayAdapter $readerCache): void
    {
        $cachedProjectList = $readerCache->getItem('corp_projects_' . self::CORPORATION_ID);
        $cachedProjectList->set([$this->projectRecord(self::PROJECT_ID, self::PROJECT_NAME, 'Active', new \DateTimeImmutable('-1 day'))]);
        $readerCache->save($cachedProjectList);
    }

    private function pilotThreeOfTheSecondCorporationThenPilotOne(): array
    {
        return [
            $this->character(self::PILOT_THREE_ID, 'Pilot Three', self::SECOND_CORPORATION_ID, self::SECOND_CORPORATION_NAME),
            $this->character(self::EVE_CHARACTER_ID, 'Pilot One', self::CORPORATION_ID, self::CORPORATION_NAME),
        ];
    }

    private function tritaniumDriveRecordThenVeldsparHaul(array $tritaniumDriveRecord, int $veldsparContributedQuantity): array
    {
        return [
            ...$this->projectListPages([
                $tritaniumDriveRecord,
                $this->projectRecord(self::VELDSPAR_PROJECT_ID, self::VELDSPAR_PROJECT_NAME, 'Active', new \DateTimeImmutable('-3 days')),
            ]),
            ...$this->contributorPages(self::VELDSPAR_PROJECT_ID, [$this->contributorRecord(self::EVE_CHARACTER_ID, 'Pilot One', $veldsparContributedQuantity)]),
            ...$this->projectDetails(self::VELDSPAR_PROJECT_ID, self::VELDSPAR, self::VELDSPAR_REWARD_PER_CONTRIBUTION),
            ...$this->contribution(self::VELDSPAR_PROJECT_ID, self::EVE_CHARACTER_ID, $veldsparContributedQuantity, new \DateTimeImmutable('-3 days')),
        ];
    }

    private function esiDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
