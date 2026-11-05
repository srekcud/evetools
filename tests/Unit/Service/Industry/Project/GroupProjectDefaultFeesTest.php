<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\Entity\IndustryProject;
use App\Service\Industry\Project\CreateProjectData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateProjectData::class)]
#[CoversClass(IndustryProject::class)]
#[CoversClass(IndustryProjectResource::class)]
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
        $project = new IndustryProject();

        self::assertNull($project->getBrokerFeePercent());
        self::assertNull($project->getSalesTaxPercent());
    }

    public function testNewProjectResourceHasNoFee(): void
    {
        $resource = new IndustryProjectResource();

        self::assertNull($resource->brokerFeePercent);
        self::assertNull($resource->salesTaxPercent);
    }
}
