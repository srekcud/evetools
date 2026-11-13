<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Constant\EveConstants;
use App\Repository\CharacterRepository;
use App\Service\ESI\AuthenticationService;
use App\Service\ESI\CharacterService;
use App\Service\ESI\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(AuthenticationService::class)]
#[Group('issue-892')]
final class AuthenticationServiceWaypointScopeTest extends TestCase
{
    private const string WAYPOINT_SCOPE = 'esi-ui.write_waypoint.v1';
    private const string OPEN_WINDOW_SCOPE = 'esi-ui.open_window.v1';

    public function testWaypointScopeConstantNamesTheEsiScope(): void
    {
        self::assertSame(self::WAYPOINT_SCOPE, EveConstants::UI_WAYPOINT_SCOPE);
    }

    public function testWaypointScopeIsRequiredNextToTheOpenWindowScope(): void
    {
        $scopes = AuthenticationService::getRequiredScopes();

        self::assertContains(self::WAYPOINT_SCOPE, $scopes);
        self::assertContains(self::OPEN_WINDOW_SCOPE, $scopes);
        self::assertSame($scopes, array_values(array_unique($scopes)));
    }

    public function testAuthorizationUrlAsksForTheWaypointScope(): void
    {
        $service = new AuthenticationService(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(TokenManager::class),
            $this->createStub(CharacterService::class),
            $this->createStub(CharacterRepository::class),
            $this->createStub(EntityManagerInterface::class),
            'test_client_id',
            'test_client_secret',
            'https://evetools.local/api/auth/eve/callback',
        );
        $query = [];
        parse_str((string) parse_url($service->getAuthorizationUrl('state-892'), PHP_URL_QUERY), $query);

        self::assertContains(self::WAYPOINT_SCOPE, explode(' ', $query['scope']));
    }
}
