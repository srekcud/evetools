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
#[Group('issue-273')]
final class AuthenticationServiceMoonTaxScopeTest extends TestCase
{
    private const string CORPORATION_WALLETS_SCOPE = 'esi-wallet.read_corporation_wallets.v1';

    public function testCorporationWalletsScopeIsRequiredAmongTheOtherWalletScope(): void
    {
        $scopes = AuthenticationService::getRequiredScopes();

        self::assertSame(
            [true, true],
            [in_array(self::CORPORATION_WALLETS_SCOPE, $scopes, true), in_array('esi-wallet.read_character_wallet.v1', $scopes, true)],
        );
    }

    public function testAuthorizationUrlAsksForCorporationWalletsScope(): void
    {
        $query = [];
        parse_str((string) parse_url($this->authenticationService()->getAuthorizationUrl('state-273'), PHP_URL_QUERY), $query);

        self::assertSame(AuthenticationService::getRequiredScopes(), explode(' ', $query['scope']));
        self::assertContains(self::CORPORATION_WALLETS_SCOPE, explode(' ', $query['scope']));
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
