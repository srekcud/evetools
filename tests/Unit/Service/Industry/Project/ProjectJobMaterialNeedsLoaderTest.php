<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Industry\Application\PlannedProduction;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\ProjectJobMaterialNeedsLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectJobMaterialNeedsLoader::class)]
#[Group('issue-449')]
final class ProjectJobMaterialNeedsLoaderTest extends TestCase
{
    use BuildsChainBomLines;

    private const int TRITANIUM = 34;
    private const int PRODUCT = 449_301;

    public function testNeedsAreReadFromThePlanOfTheProjectOwner(): void
    {
        $owner = new User();
        $project = (new IndustryProject())->setOwner($owner);
        $line = $this->jobLine(self::PRODUCT, 'Product', 'final', null, runs: 10);
        $job = new Job(self::PRODUCT, ActivityKind::Manufacturing, new Runs(10), new MaterialEfficiency(10), new TimeEfficiency(20), [self::TRITANIUM => new Quantity(500)]);
        $service = $this->createMock(IndustryProjectService::class);
        $service->expects(self::once())->method('planOf')->with($project, $owner)->willReturn(new PlannedProduction(new Plan([$job], [], []), []));

        $needs = (new ProjectJobMaterialNeedsLoader($service))->load($project, [$line]);

        self::assertSame([self::lineId($line) => [self::TRITANIUM => 500]], $needs);
    }

    public function testNeedsAreUnknownWhenThePlanCannotBeComputed(): void
    {
        $project = (new IndustryProject())->setOwner(new User());
        $service = $this->createStub(IndustryProjectService::class);
        $service->method('planOf')->willThrowException(new \RuntimeException('No blueprint or reaction found for product type ID 449301'));

        $needs = (new ProjectJobMaterialNeedsLoader($service))->load($project, [$this->jobLine(self::PRODUCT, 'Product', 'final', null)]);

        self::assertNull($needs);
    }
}
