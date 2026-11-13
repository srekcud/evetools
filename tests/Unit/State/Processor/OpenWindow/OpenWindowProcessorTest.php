<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\OpenWindow;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\OpenWindow\OpenWindowInfoInput;
use App\ApiResource\Input\OpenWindow\OpenWindowWaypointInput;
use App\Constant\EveConstants;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Service\ESI\OpenWindowService;
use App\State\Processor\OpenWindow\OpenWindowProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(OpenWindowProcessor::class)]
#[Group('issue-892')]
final class OpenWindowProcessorTest extends TestCase
{
    private const int STATION_ID = 60003760;
    private const int CONTAINER_ID = 1050000000101;

    public function testWaypointWithTheScopeSetsTheDestination(): void
    {
        $token = $this->tokenWithScopes([EveConstants::UI_WAYPOINT_SCOPE]);
        $service = $this->createMock(OpenWindowService::class);
        $service->expects(self::once())->method('setWaypoint')->with($token, self::STATION_ID);

        $result = $this->processor($service, $token)->process($this->waypoint(self::STATION_ID), new Post());

        self::assertTrue($result->success);
        self::assertNull($result->error);
    }

    public function testWaypointWithoutTheScopeAsksToLogInAgainAndCallsNothing(): void
    {
        $token = $this->tokenWithScopes(['esi-ui.open_window.v1']);
        $service = $this->createMock(OpenWindowService::class);
        $service->expects(self::never())->method('setWaypoint');

        $result = $this->processor($service, $token)->process($this->waypoint(self::STATION_ID), new Post());

        self::assertFalse($result->success);
        self::assertNotNull($result->error);
        self::assertStringContainsString(EveConstants::UI_WAYPOINT_SCOPE, $result->error);
    }

    public function testWaypointEsiFailureIsReportedWithItsMessage(): void
    {
        $token = $this->tokenWithScopes([EveConstants::UI_WAYPOINT_SCOPE]);
        $service = $this->createStub(OpenWindowService::class);
        $service->method('setWaypoint')->willThrowException(EsiApiException::fromResponse(403, 'Access forbidden', '/ui/autopilot/waypoint/'));

        $result = $this->processor($service, $token)->process($this->waypoint(self::STATION_ID), new Post());

        self::assertFalse($result->success);
        self::assertSame('Access forbidden', $result->error);
    }

    public function testInformationStillOpensWithoutTheWaypointScope(): void
    {
        $token = $this->tokenWithScopes(['esi-ui.open_window.v1']);
        $service = $this->createMock(OpenWindowService::class);
        $service->expects(self::once())->method('openInformation')->with($token, self::CONTAINER_ID);
        $input = new OpenWindowInfoInput();
        $input->targetId = self::CONTAINER_ID;

        $result = $this->processor($service, $token)->process($input, new Post());

        self::assertTrue($result->success);
    }

    private function waypoint(int $destinationId): OpenWindowWaypointInput
    {
        $input = new OpenWindowWaypointInput();
        $input->destinationId = $destinationId;

        return $input;
    }

    /**
     * @param list<string> $scopes
     */
    private function tokenWithScopes(array $scopes): EveToken
    {
        return (new EveToken())->setScopes($scopes);
    }

    private function processor(OpenWindowService $service, EveToken $token): OpenWindowProcessor
    {
        $character = (new Character())
            ->setEveCharacterId(90000001)
            ->setName('Mira')
            ->setEveToken($token);
        $user = (new User())->setMainCharacter($character);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new OpenWindowProcessor($security, $service, new NullLogger());
    }
}
