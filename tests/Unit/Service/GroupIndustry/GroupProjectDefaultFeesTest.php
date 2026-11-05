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
#[Group('issue-347')]
final class GroupProjectDefaultFeesTest extends TestCase
{
    public function testProjectDataWithoutFeesHasNoFee(): void
    {
        $projectData = new CreateProjectData('Sabre batch', []);

        self::assertNull($projectData->brokerFeePercent);
        self::assertNull($projectData->salesTaxPercent);
    }

    public function testNewProjectHasNoFee(): void
    {
        $project = new GroupIndustryProject();

        self::assertNull($project->getBrokerFeePercent());
        self::assertNull($project->getSalesTaxPercent());
    }

    public function testNewProjectResourceHasNoFee(): void
    {
        $resource = new GroupIndustryProjectResource();

        self::assertNull($resource->brokerFeePercent);
        self::assertNull($resource->salesTaxPercent);
    }
}
