<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProject::class)]
#[Group('issue-1285')]
final class IndustryProjectWorkStationsTest extends TestCase
{
    private const string AZBEL_ID = '01970000-0000-7000-8000-0000000000a1';
    private const string SOTIYO_ID = '01970000-0000-7000-8000-0000000000a2';
    private const string TATARA_ID = '01970000-0000-7000-8000-0000000000a3';

    public function testNewProjectHasNoWorkStationChoice(): void
    {
        $this->assertSame([], (new IndustryProject())->getWorkStations());
    }

    public function testWorkStationChoicesAreKeptWithTheirRecommendedStructureAtTheTimeOfTheChoice(): void
    {
        $project = (new IndustryProject())->setWorkStations([
            'components' => ['structureId' => self::AZBEL_ID, 'recommendedStructureId' => self::SOTIYO_ID],
            'reaction' => ['structureId' => self::TATARA_ID, 'recommendedStructureId' => null],
        ]);

        $this->assertSame(
            [
                'components' => ['structureId' => self::AZBEL_ID, 'recommendedStructureId' => self::SOTIYO_ID],
                'reaction' => ['structureId' => self::TATARA_ID, 'recommendedStructureId' => null],
            ],
            $project->getWorkStations(),
        );
    }

    public function testRemovingEveryChoiceLeavesNoWorkStationChoice(): void
    {
        $project = (new IndustryProject())->setWorkStations(['components' => ['structureId' => self::AZBEL_ID, 'recommendedStructureId' => null]]);

        $project->setWorkStations([]);

        $this->assertSame([], $project->getWorkStations());
    }
}
