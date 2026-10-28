<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\GroupIndustry;

use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\Entity\GroupIndustryProject;
use App\Service\GroupIndustry\CreateProjectData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateProjectData::class)]
#[CoversClass(GroupIndustryProject::class)]
#[CoversClass(GroupIndustryProjectResource::class)]
#[Group('issue-170')]
final class GroupProjectDefaultFeesTest extends TestCase
{
    public function testProjectDataWithoutFeesDefaultsToExactlyThreePointSixPercent(): void
    {
        $projectData = new CreateProjectData('Sabre batch', []);

        self::assertSame(3.6, $projectData->brokerFeePercent);
        self::assertSame(3.6, $projectData->salesTaxPercent);
    }

    public function testNewProjectDefaultsToExactlyThreePointSixPercent(): void
    {
        $project = new GroupIndustryProject();

        self::assertSame(3.6, $project->getBrokerFeePercent());
        self::assertSame(3.6, $project->getSalesTaxPercent());
    }

    public function testNewProjectResourceDefaultsToExactlyThreePointSixPercent(): void
    {
        $resource = new GroupIndustryProjectResource();

        self::assertSame(3.6, $resource->brokerFeePercent);
        self::assertSame(3.6, $resource->salesTaxPercent);
    }
}
