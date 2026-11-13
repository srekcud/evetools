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
#[Group('issue-350')]
final class AuthenticationServiceOrdersScopeTest extends TestCase
{
    private const string ORDERS_SCOPE = 'esi-markets.read_character_orders.v1';
    private const int REQUIRED_SCOPE_COUNT = 31;

    public function testOrdersScopeConstantNamesTheEsiScope(): void
    {
        self::assertSame(self::ORDERS_SCOPE, EveConstants::CHARACTER_ORDERS_SCOPE);
    }

    public function testOrdersScopeIsRequiredAndTheListHoldsThirtyOneDistinctScopes(): void
    {
        $scopes = AuthenticationService::getRequiredScopes();

        self::assertContains(self::ORDERS_SCOPE, $scopes);
        self::assertCount(self::REQUIRED_SCOPE_COUNT, $scopes);
        self::assertSame($scopes, array_values(array_unique($scopes)));
    }

    public function testAuthorizationUrlAsksForOrdersScope(): void
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
        parse_str((string) parse_url($service->getAuthorizationUrl('state-350'), PHP_URL_QUERY), $query);

        self::assertContains(self::ORDERS_SCOPE, explode(' ', $query['scope']));
    }
}
