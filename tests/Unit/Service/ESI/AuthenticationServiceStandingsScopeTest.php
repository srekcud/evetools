<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

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
#[Group('issue-347')]
final class AuthenticationServiceStandingsScopeTest extends TestCase
{
    private const string STANDINGS_SCOPE = 'esi-characters.read_standings.v1';

    public function testStandingsScopeIsRequired(): void
    {
        self::assertContains(self::STANDINGS_SCOPE, AuthenticationService::getRequiredScopes());
    }

    public function testAuthorizationUrlAsksForStandingsScope(): void
    {
        $query = [];
        parse_str((string) parse_url($this->authenticationService()->getAuthorizationUrl('state-347'), PHP_URL_QUERY), $query);

        self::assertContains(self::STANDINGS_SCOPE, explode(' ', $query['scope']));
    }

    private function authenticationService(): AuthenticationService
    {
        return new AuthenticationService(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(TokenManager::class),
            $this->createStub(CharacterService::class),
            $this->createStub(CharacterRepository::class),
            $this->createStub(EntityManagerInterface::class),
            'test_client_id',
            'test_client_secret',
            'https://evetools.local/api/auth/eve/callback',
        );
    }
}
