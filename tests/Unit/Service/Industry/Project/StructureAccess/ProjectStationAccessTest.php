<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\StructureAccess;

use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Enum\StructureAccessSource;
use App\Enum\StructureAccessStatus;
use App\Service\Industry\Project\Action\ActionPlace;
use App\Service\Industry\Project\StructureAccess\MemberStructureAccess;
use App\Service\Industry\Project\StructureAccess\PlanStations;
use App\Service\Industry\Project\StructureAccess\ProjectStationAccess;
use App\Service\Industry\Project\StructureAccess\StationAccessCount;
use App\Tests\Unit\Service\Industry\Project\Action\BuildsActionPlaces;
use App\Tests\Unit\State\Provider\Industry\Project\BuildsWorkshopProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectStationAccess::class)]
#[Group('issue-1299')]
final class ProjectStationAccessTest extends TestCase
{
    use BuildsActionPlaces;
    use BuildsStructureAccess;
    use BuildsWorkshopProjects;

    private const int COMPONENT_TYPE_ID = 11_540;
    private const int RAM_TYPE_ID = 11_478;
    private const string JITA_FORTIZAR_NAME = 'Jita IV - Caldari Navy Assembly Plant';

    public function testEachStationOfThePlanCountsTheMembersWhoCanLaunchAreDeniedOrUnknown(): void
    {
        $ownerCharacter = $this->characterOf();
        $grantedCharacter = $this->characterOf();
        $deniedCharacter = $this->characterOf();
        $unprovedCharacter = $this->characterOf();
        $members = [
            $this->pilotOf($ownerCharacter),
            $this->pilotOf($grantedCharacter),
            $this->pilotOf($deniedCharacter),
            $this->pilotOf($unprovedCharacter),
        ];
        $azbel = $this->azbelConfig();
        $fortizar = $this->azbelConfig(self::JITA_FORTIZAR_STATION_ID)->setName(self::JITA_FORTIZAR_NAME);
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $members[0]);
        $this->jobOf($project, self::COMPONENT_TYPE_ID, 'Composant X', 'component', 3, 3, 0)->setPlannedStructureId($azbel->getId()->toRfc4122());
        $this->jobOf($project, self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', 3, 3, 0)->setPlannedStructureId($fortizar->getId()->toRfc4122());
        $rows = [
            $this->accessRowOf($grantedCharacter, self::AZBEL_LOCATION_ID, StructureAccessStatus::Granted, StructureAccessSource::Esi, '2026-11-21T10:00:00+00:00'),
            $this->accessRowOf($deniedCharacter, self::AZBEL_LOCATION_ID, StructureAccessStatus::Denied, StructureAccessSource::Esi, '2026-11-21T10:00:00+00:00'),
        ];
        foreach (array_slice($members, 1) as $member) {
            $this->membershipOf($project, $member, ParticipantRole::Member, ParticipantStatus::Accepted);
        }

        $counts = (new ProjectStationAccess(
            new PlanStations($this->placeResolver([$azbel, $fortizar])),
            new MemberStructureAccess($this->readerHolding($rows)),
        ))->of($project);

        self::assertEquals(
            [
                new StationAccessCount(ActionPlace::station(self::AZBEL_LOCATION_ID, self::AZBEL_NAME, self::PERIMETER_SYSTEM_ID, self::PERIMETER_SYSTEM_NAME), 1, 1, 2),
                new StationAccessCount(ActionPlace::station(self::JITA_FORTIZAR_STATION_ID, self::JITA_FORTIZAR_NAME, self::PERIMETER_SYSTEM_ID, self::PERIMETER_SYSTEM_NAME), 4, 0, 0),
            ],
            $counts,
        );
    }
}
