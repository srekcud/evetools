<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\ProjectContainerFulfillment;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\ProjectContainerStock;
use App\Service\Industry\Project\ProjectLineFulfillments;
use App\Service\Industry\Project\StartedJobConsumption;
use App\Service\Industry\Project\StartedJobConsumptionReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectContainerFulfillment::class)]
#[Group('issue-866')]
final class ProjectContainerFulfillmentTest extends TestCase
{
    use BuildsChainBomLines;

    private const int RAM_STARSHIP_TECH = 11478;
    private const int CADMIUM = 16_647;
    private const int SILICON = 16_636;
    private const int REACTION_PRODUCT = 1_140_001;
    private const int COPIED_PRODUCT = 1_140_004;
    private const int CADMIUM_REQUIRED = 38_200;
    private const int CADMIUM_IN_CONTAINER = 34_987;
    private const int CADMIUM_CONSUMED = 3_213;
    private const int REACTION_RUNS = 33;

    public function testReadableContainerStockIsLaidOverTheContributionsOfTheLines(): void
    {
        $fulfillments = $this->fulfillmentsOf(new ProjectContainerStock(null, [self::RAM_STARSHIP_TECH => 2]));
        $line = $this->ramLine(55, 0);

        self::assertSame(ContainerStockState::Known, $fulfillments->stateOf($line));
        self::assertSame(2, $fulfillments->fulfilledOf($line));
        self::assertSame(53, $fulfillments->remainingOf($line));
    }

    public function testReadableContainerWithoutTheTypeIsAKnownZero(): void
    {
        $fulfillments = $this->fulfillmentsOf(new ProjectContainerStock(null, []));
        $line = $this->ramLine(55, 10);

        self::assertSame(ContainerStockState::Known, $fulfillments->stateOf($line));
        self::assertSame(0, $fulfillments->containerQuantityOf($line));
        self::assertSame(10, $fulfillments->fulfilledOf($line));
    }

    public function testProjectWithoutContainerNameHasNoContainerStock(): void
    {
        $fulfillments = $this->fulfillmentsOf(new ProjectContainerStock('no_container_name'));
        $line = $this->ramLine(55, 10);

        self::assertSame(ContainerStockState::None, $fulfillments->stateOf($line));
        self::assertNull($fulfillments->containerQuantityOf($line));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableReasons(): iterable
    {
        yield 'container not found' => ['container_not_found'];
        yield 'no director connected' => ['no_director'];
        yield 'corporation assets never synced' => ['corporation_assets_not_synced'];
        yield 'no visible division' => ['no_visible_division'];
        yield 'owner without corporation' => ['owner_without_corporation'];
    }

    #[DataProvider('unreadableReasons')]
    public function testUnreadableContainerIsUnknownNeverZero(string $reason): void
    {
        $fulfillments = $this->fulfillmentsOf(new ProjectContainerStock($reason));
        $line = $this->ramLine(55, 10);

        self::assertSame(ContainerStockState::Unknown, $fulfillments->stateOf($line));
        self::assertNull($fulfillments->containerQuantityOf($line));
        self::assertSame(10, $fulfillments->fulfilledOf($line));
    }

    #[Group('issue-1140')]
    public function testUnreadableContainerStockNeverAsksTheConsumptionOfTheStartedJobs(): void
    {
        $fulfillments = (new ProjectContainerFulfillment($this->containerService(new ProjectContainerStock('container_not_found')), $this->readerNeverAsked()))->of(new IndustryProject());
        $line = $this->materialLine(self::CADMIUM, 'Cadmium', 1_000, 0);

        self::assertSame(ContainerStockState::Unknown, $fulfillments->stateOf($line));
        self::assertSame(0, $fulfillments->fulfilledOf($line));
        self::assertSame(1_000, $fulfillments->remainingOf($line));
    }

    #[Group('issue-1140')]
    public function testProjectWithoutContainerNameNeverAsksTheConsumptionOfTheStartedJobs(): void
    {
        $fulfillments = (new ProjectContainerFulfillment($this->containerService(new ProjectContainerStock(ProjectContainerService::REASON_NO_CONTAINER_NAME)), $this->readerNeverAsked()))->of(new IndustryProject());
        $line = $this->materialLine(self::CADMIUM, 'Cadmium', 1_000, 0);

        self::assertSame(ContainerStockState::None, $fulfillments->stateOf($line));
        self::assertNull($fulfillments->containerQuantityOf($line));
    }

    #[Group('issue-1140')]
    public function testMaterialsConsumedByAStartedJobStillCoverTheLineOfTheContainer(): void
    {
        $startedLine = $this->startedReactionLine();
        $consumption = StartedJobConsumption::of([$startedLine], [self::lineId($startedLine) => [self::CADMIUM => self::CADMIUM_CONSUMED]]);
        $fulfillments = (new ProjectContainerFulfillment($this->containerService($this->cadmiumStock()), $this->readerAnswering($consumption)))->of(new IndustryProject());
        $line = $this->cadmiumLine(0);

        self::assertSame(ContainerStockState::Known, $fulfillments->stateOf($line));
        self::assertSame(self::CADMIUM_IN_CONTAINER, $fulfillments->containerQuantityOf($line));
        self::assertSame(self::CADMIUM_REQUIRED, $fulfillments->fulfilledOf($line));
        self::assertSame(0, $fulfillments->remainingOf($line));
    }

    #[Group('issue-1140')]
    public function testUnknownConsumptionOfTheStartedJobsMakesTheReadableContainerUnknownNeverAPlausibleCoverage(): void
    {
        $fulfillments = (new ProjectContainerFulfillment($this->containerService($this->cadmiumStock()), $this->readerAnswering(StartedJobConsumption::unknown())))->of(new IndustryProject());
        $line = $this->cadmiumLine(500);

        self::assertSame(ContainerStockState::Unknown, $fulfillments->state());
        self::assertSame(ContainerStockState::Unknown, $fulfillments->stateOf($line));
        self::assertNull($fulfillments->containerQuantityOf($line));
        self::assertSame(500, $fulfillments->fulfilledOf($line));
        self::assertSame(37_700, $fulfillments->remainingOf($line));
    }

    #[Group('issue-1140')]
    public function testKnownJobMaterialNeedsCoverTheLineOfTheContainerWithoutAskingTheReader(): void
    {
        $startedLine = $this->startedReactionLine();
        $line = $this->cadmiumLine(0);
        $fulfillments = (new ProjectContainerFulfillment($this->containerService($this->cadmiumStock()), $this->readerNeverAsked()))
            ->ofWithJobMaterialNeeds(new IndustryProject(), [$startedLine, $line], [self::lineId($startedLine) => [self::CADMIUM => self::CADMIUM_CONSUMED]]);

        self::assertSame(ContainerStockState::Known, $fulfillments->stateOf($line));
        self::assertSame(self::CADMIUM_IN_CONTAINER, $fulfillments->containerQuantityOf($line));
        self::assertSame(self::CADMIUM_REQUIRED, $fulfillments->fulfilledOf($line));
        self::assertSame(0, $fulfillments->remainingOf($line));
    }

    #[Group('issue-1140')]
    public function testUnavailableJobMaterialNeedsWithAStartedJobMakeTheReadableContainerUnknown(): void
    {
        $startedLine = $this->startedReactionLine();
        $line = $this->cadmiumLine(500);
        $fulfillments = (new ProjectContainerFulfillment($this->containerService($this->cadmiumStock()), $this->readerNeverAsked()))
            ->ofWithJobMaterialNeeds(new IndustryProject(), [$startedLine, $line], null);

        self::assertSame(ContainerStockState::Unknown, $fulfillments->stateOf($line));
        self::assertNull($fulfillments->containerQuantityOf($line));
        self::assertSame(500, $fulfillments->fulfilledOf($line));
    }

    #[Group('issue-1140')]
    public function testUnavailableJobMaterialNeedsWithoutAStartedJobLeaveTheReadableContainerKnown(): void
    {
        $cancelledLine = $this->startedReactionLine(CachedIndustryJob::STATUS_CANCELLED);
        $line = $this->cadmiumLine(0);
        $fulfillments = (new ProjectContainerFulfillment($this->containerService($this->cadmiumStock()), $this->readerNeverAsked()))
            ->ofWithJobMaterialNeeds(new IndustryProject(), [$cancelledLine, $line], null);

        self::assertSame(ContainerStockState::Known, $fulfillments->stateOf($line));
        self::assertSame(self::CADMIUM_IN_CONTAINER, $fulfillments->fulfilledOf($line));
        self::assertSame(self::CADMIUM_REQUIRED - self::CADMIUM_IN_CONTAINER, $fulfillments->remainingOf($line));
    }

    #[Group('issue-1140')]
    public function testStartedJobOfAnotherGroupThanComponentOrFinalIsNotReadAsAJobConsumingMaterials(): void
    {
        $startedCopy = $this->jobLine(self::COPIED_PRODUCT, 'Copied blueprint', 'copy', null, 5, 'copying');
        $startedCopy->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(1_140_302)->setStatus(CachedIndustryJob::STATUS_ACTIVE)->setRuns(5));
        $line = $this->materialLine(self::SILICON, 'Silicon', 2_000, 0);
        $fulfillments = (new ProjectContainerFulfillment($this->containerService(new ProjectContainerStock(null, [self::SILICON => 800])), $this->readerNeverAsked()))
            ->ofWithJobMaterialNeeds(new IndustryProject(), [$startedCopy, $line], null);

        self::assertSame(ContainerStockState::Known, $fulfillments->stateOf($line));
        self::assertSame(800, $fulfillments->fulfilledOf($line));
    }

    #[Group('issue-1140')]
    public function testUnreadableContainerStockStaysUnknownWhateverTheJobMaterialNeeds(): void
    {
        $startedLine = $this->startedReactionLine();
        $line = $this->cadmiumLine(500);
        $fulfillments = (new ProjectContainerFulfillment($this->containerService(new ProjectContainerStock('no_director')), $this->readerNeverAsked()))
            ->ofWithJobMaterialNeeds(new IndustryProject(), [$startedLine, $line], [self::lineId($startedLine) => [self::CADMIUM => self::CADMIUM_CONSUMED]]);

        self::assertSame(ContainerStockState::Unknown, $fulfillments->stateOf($line));
        self::assertSame(500, $fulfillments->fulfilledOf($line));
    }

    private function fulfillmentsOf(ProjectContainerStock $stock): ProjectLineFulfillments
    {
        $containerService = $this->createStub(ProjectContainerService::class);
        $containerService->method('containerStockOf')->willReturn($stock);

        return (new ProjectContainerFulfillment($containerService, $this->readerOfNoStartedJob()))->of(new IndustryProject());
    }

    private function containerService(ProjectContainerStock $stock): ProjectContainerService
    {
        $containerService = $this->createStub(ProjectContainerService::class);
        $containerService->method('containerStockOf')->willReturn($stock);

        return $containerService;
    }

    private function readerOfNoStartedJob(): StartedJobConsumptionReader
    {
        return $this->readerAnswering(StartedJobConsumption::none());
    }

    private function readerAnswering(StartedJobConsumption $consumption): StartedJobConsumptionReader
    {
        $reader = $this->createStub(StartedJobConsumptionReader::class);
        $reader->method('of')->willReturn($consumption);

        return $reader;
    }

    private function readerNeverAsked(): StartedJobConsumptionReader&MockObject
    {
        $reader = $this->createMock(StartedJobConsumptionReader::class);
        $reader->expects(self::never())->method('of');

        return $reader;
    }

    private function startedReactionLine(string $status = CachedIndustryJob::STATUS_ACTIVE): IndustryProjectBomLine
    {
        $line = $this->jobLine(self::REACTION_PRODUCT, 'Reaction product', 'final', null, self::REACTION_RUNS, 'reaction', 0, 0);
        $line->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(1_140_301)->setStatus($status)->setRuns(self::REACTION_RUNS));

        return $line;
    }

    private function cadmiumLine(int $contributed): IndustryProjectBomLine
    {
        return $this->materialLine(self::CADMIUM, 'Cadmium', self::CADMIUM_REQUIRED, $contributed);
    }

    private function cadmiumStock(): ProjectContainerStock
    {
        return new ProjectContainerStock(null, [self::CADMIUM => self::CADMIUM_IN_CONTAINER]);
    }

    private function ramLine(int $required, int $contributed): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId(self::RAM_STARSHIP_TECH)
            ->setTypeName('R.A.M.- Starship Tech')
            ->setRequiredQuantity($required)
            ->setFulfilledQuantity($contributed)
            ->setIsJob(false);
    }
}
