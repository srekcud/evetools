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
#[Group('issue-1050')]
final class UnclaimedStockGuardTest extends TestCase
{
    private const int TRITANIUM_TYPE_ID = 34;
    private const int REQUIRED_QUANTITY = 100;
    private const int CONTAINER_QUANTITY = 80;

    public function testQuantityWithinTheUnclaimedStockPasses(): void
    {
        [$guard, $line] = $this->guardFor(ProjectStatus::InProgress, new ProjectLineFulfillments(ContainerStockState::Known, [self::TRITANIUM_TYPE_ID => self::CONTAINER_QUANTITY]), 30);

        $guard->assertWithin($line, 50);

        $this->addToAssertionCount(1);
    }

    public function testQuantityAboveTheUnclaimedStockIsRefused(): void
    {
        [$guard, $line] = $this->guardFor(ProjectStatus::InProgress, new ProjectLineFulfillments(ContainerStockState::Known, [self::TRITANIUM_TYPE_ID => self::CONTAINER_QUANTITY]), 30);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('exceeds the unclaimed stock');

        $guard->assertWithin($line, 51);
    }

    public function testOwnQuantityOfTheEditedContributionIsGivenBack(): void
    {
        [$guard, $line] = $this->guardFor(ProjectStatus::InProgress, new ProjectLineFulfillments(ContainerStockState::Known, [self::TRITANIUM_TYPE_ID => self::CONTAINER_QUANTITY]), 30);

        $guard->assertWithin($line, 60, 10);

        $this->addToAssertionCount(1);
    }

    public function testUnknownContainerStockIsRefusedInsteadOfBeingGuessed(): void
    {
        [$guard, $line] = $this->guardFor(ProjectStatus::InProgress, new ProjectLineFulfillments(ContainerStockState::Unknown), 0);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('container stock');

        $guard->assertWithin($line, 1);
    }

    public function testProjectWithoutAContainerIsRefusedInsteadOfBeingGuessed(): void
    {
        [$guard, $line] = $this->guardFor(ProjectStatus::InProgress, ProjectLineFulfillments::none(), 0);

        $this->expectException(\DomainException::class);

        $guard->assertWithin($line, 1);
    }

    public function testProjectNotYetInProgressIsRefused(): void
    {
        [$guard, $line] = $this->guardFor(ProjectStatus::Published, new ProjectLineFulfillments(ContainerStockState::Known, [self::TRITANIUM_TYPE_ID => self::CONTAINER_QUANTITY]), 0);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('in progress');

        $guard->assertWithin($line, 1);
    }

    public function testSellingProjectStillAllowsTheClaim(): void
    {
        [$guard, $line] = $this->guardFor(ProjectStatus::Selling, new ProjectLineFulfillments(ContainerStockState::Known, [self::TRITANIUM_TYPE_ID => self::CONTAINER_QUANTITY]), 0);

        $guard->assertWithin($line, 80);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array{UnclaimedStockGuard, IndustryProjectBomLine}
     */
    private function guardFor(ProjectStatus $status, ProjectLineFulfillments $fulfillments, int $claimedQuantity): array
    {
        $project = (new IndustryProject())->setOwner(new User())->setStatus($status);
        $line = (new IndustryProjectBomLine())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setRequiredQuantity(self::REQUIRED_QUANTITY)->setIsJob(false);
        $project->addBomItem($line);

        $containerFulfillment = $this->createStub(ProjectContainerFulfillment::class);
        $containerFulfillment->method('of')->willReturn($fulfillments);
        $contributions = $this->createStub(IndustryProjectContributionRepository::class);
        $contributions->method('sumClaimedMaterialQuantityByLine')->willReturn([$line->getId()->toRfc4122() => $claimedQuantity]);

        return [new UnclaimedStockGuard($containerFulfillment, $contributions), $line];
    }
}
