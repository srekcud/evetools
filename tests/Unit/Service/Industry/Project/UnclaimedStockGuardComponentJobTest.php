<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\ProjectContainerFulfillment;
use App\Service\Industry\Project\ProjectLineFulfillments;
use App\Service\Industry\Project\UnclaimedStockGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnclaimedStockGuard::class)]
#[Group('issue-1013')]
final class UnclaimedStockGuardComponentJobTest extends TestCase
{
    private const int HYDROGEN_FUEL_BLOCK = 4246;

    public function testQuantityWithinTheContainerStockOfAComponentJobLineLeftByTheClaimsPasses(): void
    {
        [$guard, $line] = $this->guardFor('component', 30);

        $guard->assertWithin($line, 50);

        $this->addToAssertionCount(1);
    }

    public function testQuantityAboveTheContainerStockOfAComponentJobLineLeftByTheClaimsIsRefused(): void
    {
        [$guard, $line] = $this->guardFor('component', 30);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('exceeds the unclaimed stock');

        $guard->assertWithin($line, 51);
    }

    public function testFinalJobLineHasNoUnclaimedStockToDraw(): void
    {
        [$guard, $line] = $this->guardFor('final', 0);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('container stock');

        $guard->assertWithin($line, 1);
    }

    /**
     * @return array{UnclaimedStockGuard, IndustryProjectBomLine}
     */
    private function guardFor(string $jobGroup, int $claimedQuantity): array
    {
        $project = (new IndustryProject())->setOwner(new User())->setStatus(ProjectStatus::InProgress);
        $line = (new IndustryProjectBomLine())
            ->setTypeId(self::HYDROGEN_FUEL_BLOCK)
            ->setTypeName('Hydrogen Fuel Block')
            ->setRequiredQuantity(120)
            ->setIsJob(true)
            ->setJobGroup($jobGroup)
            ->setActivityType('manufacturing');
        $project->addBomItem($line);

        $containerFulfillment = $this->createStub(ProjectContainerFulfillment::class);
        $containerFulfillment->method('of')->willReturn(new ProjectLineFulfillments(ContainerStockState::Known, [self::HYDROGEN_FUEL_BLOCK => 80]));
        $contributions = $this->createStub(IndustryProjectContributionRepository::class);
        $contributions->method('sumClaimedMaterialQuantityByLine')->willReturn([$line->getId()->toRfc4122() => $claimedQuantity]);

        return [new UnclaimedStockGuard($containerFulfillment, $contributions), $line];
    }
}
