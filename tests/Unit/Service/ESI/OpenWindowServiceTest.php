<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Entity\EveToken;
use App\Service\ESI\EsiClient;
use App\Service\ESI\OpenWindowService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenWindowService::class)]
#[Group('issue-892')]
final class OpenWindowServiceTest extends TestCase
{
    private const int STATION_ID = 60003760;
    private const int CONTAINER_ID = 1050000000101;

    public function testSetWaypointReplacesTheRouteWithTheDestination(): void
    {
        $token = new EveToken();
        $esiClient = $this->createMock(EsiClient::class);
        $esiClient->expects(self::once())
            ->method('postEmpty')
            ->with(
                '/ui/autopilot/waypoint/?add_to_beginning=false&clear_other_waypoints=true&destination_id=' . self::STATION_ID,
                $token,
            );

        (new OpenWindowService($esiClient))->setWaypoint($token, self::STATION_ID);
    }

    public function testOpenInformationKeepsPostingTheTargetId(): void
    {
        $token = new EveToken();
        $esiClient = $this->createMock(EsiClient::class);
        $esiClient->expects(self::once())
            ->method('postEmpty')
            ->with('/ui/openwindow/information/?target_id=' . self::CONTAINER_ID, $token);

        (new OpenWindowService($esiClient))->openInformation($token, self::CONTAINER_ID);
    }
}
