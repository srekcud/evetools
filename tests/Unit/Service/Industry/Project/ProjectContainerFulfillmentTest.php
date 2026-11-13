<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\ProjectContainerFulfillment;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\ProjectContainerStock;
use App\Service\Industry\Project\ProjectLineFulfillments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectContainerFulfillment::class)]
#[Group('issue-866')]
final class ProjectContainerFulfillmentTest extends TestCase
{
    private const int RAM_STARSHIP_TECH = 11478;

    public function testReadableContainerStockIsLaidOverTheContributionsOfTheLines(): void
    {
        $fulfillments = $this->fulfillmentsOf(new ProjectContainerStock(null, [self::RAM_STARSHIP_TECH => 2]));
        $line = $this->materialLine(55, 0);

        self::assertSame(ContainerStockState::Known, $fulfillments->stateOf($line));
        self::assertSame(2, $fulfillments->fulfilledOf($line));
        self::assertSame(53, $fulfillments->remainingOf($line));
    }

    public function testReadableContainerWithoutTheTypeIsAKnownZero(): void
    {
        $fulfillments = $this->fulfillmentsOf(new ProjectContainerStock(null, []));
        $line = $this->materialLine(55, 10);

        self::assertSame(ContainerStockState::Known, $fulfillments->stateOf($line));
        self::assertSame(0, $fulfillments->containerQuantityOf($line));
        self::assertSame(10, $fulfillments->fulfilledOf($line));
    }

    public function testProjectWithoutContainerNameHasNoContainerStock(): void
    {
        $fulfillments = $this->fulfillmentsOf(new ProjectContainerStock('no_container_name'));
        $line = $this->materialLine(55, 10);

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
        $line = $this->materialLine(55, 10);

        self::assertSame(ContainerStockState::Unknown, $fulfillments->stateOf($line));
        self::assertNull($fulfillments->containerQuantityOf($line));
        self::assertSame(10, $fulfillments->fulfilledOf($line));
    }

    private function fulfillmentsOf(ProjectContainerStock $stock): ProjectLineFulfillments
    {
        $containerService = $this->createStub(ProjectContainerService::class);
        $containerService->method('containerStockOf')->willReturn($stock);

        return (new ProjectContainerFulfillment($containerService))->of(new IndustryProject());
    }

    private function materialLine(int $required, int $contributed): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId(self::RAM_STARSHIP_TECH)
            ->setTypeName('R.A.M.- Starship Tech')
            ->setRequiredQuantity($required)
            ->setFulfilledQuantity($contributed)
            ->setIsJob(false);
    }
}
