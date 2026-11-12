<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Constant\EveConstants;
use App\Service\ESI\CorporationDirectorAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorporationDirectorAccess::class)]
#[Group('issue-733')]
final class CorporationDirectorAccessTest extends TestCase
{
    use BuildsCorporationDirectorAccess;

    private const int CORPORATION_ID = 98_000_001;
    private const int OTHER_CORPORATION_ID = 98_000_002;

    public function testAMemberHoldingTheDirectorRoleConnectsTheCorporation(): void
    {
        $this->corporationMember(self::CORPORATION_ID, ['Director']);

        self::assertTrue($this->corporationDirectorAccess()->isConnected(self::CORPORATION_ID, [EveConstants::CORPORATION_ASSETS_SCOPE]));
    }

    public function testMembersWithTheScopeButWithoutTheDirectorRoleDoNotConnectTheCorporation(): void
    {
        $this->corporationMember(self::CORPORATION_ID, []);
        $this->corporationMember(self::CORPORATION_ID, ['Accountant']);

        self::assertFalse($this->corporationDirectorAccess()->isConnected(self::CORPORATION_ID, [EveConstants::CORPORATION_ASSETS_SCOPE]));
    }

    public function testACorporationWithoutAnyCharacterIsNotConnected(): void
    {
        $this->corporationMember(self::OTHER_CORPORATION_ID, ['Director']);

        self::assertFalse($this->corporationDirectorAccess()->isConnected(self::CORPORATION_ID, [EveConstants::CORPORATION_ASSETS_SCOPE]));
    }

    public function testADirectorWithoutTheRequestedScopeDoesNotConnectTheCorporation(): void
    {
        $this->corporationMember(self::CORPORATION_ID, ['Director'], [EveConstants::CORPORATION_ASSETS_SCOPE, EveConstants::CORPORATION_ROLES_SCOPE]);

        self::assertFalse($this->corporationDirectorAccess()->isConnected(self::CORPORATION_ID, [EveConstants::CORPORATION_BLUEPRINTS_SCOPE]));
    }

    public function testADirectorWithoutTheRolesScopeDoesNotConnectTheCorporation(): void
    {
        $this->corporationMember(self::CORPORATION_ID, ['Director'], [EveConstants::CORPORATION_ASSETS_SCOPE]);

        self::assertFalse($this->corporationDirectorAccess()->isConnected(self::CORPORATION_ID, [EveConstants::CORPORATION_ASSETS_SCOPE]));
    }

    public function testADirectorWhoseAuthIsInvalidDoesNotConnectTheCorporation(): void
    {
        $this->corporationMember(self::CORPORATION_ID, ['Director'], null, false);

        self::assertFalse($this->corporationDirectorAccess()->isConnected(self::CORPORATION_ID, [EveConstants::CORPORATION_ASSETS_SCOPE]));
    }

    public function testASecondLookupReadsNoRolesFromTheRememberedHolder(): void
    {
        $director = $this->corporationMember(self::CORPORATION_ID, ['Director']);
        $access = $this->corporationDirectorAccess();

        $access->isConnected(self::CORPORATION_ID, [EveConstants::CORPORATION_ASSETS_SCOPE]);
        $access->isConnected(self::CORPORATION_ID, [EveConstants::CORPORATION_ASSETS_SCOPE]);

        self::assertSame([$director->getEveCharacterId()], $this->rolesReadFor);
    }
}
