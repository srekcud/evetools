<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Admin;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Service\Admin\AdminService;
use App\Service\Admin\SyncTracker;
use App\Tests\Integration\IntegrationTestCase;

final class AdminServiceUserAndCharacterStatsTest extends IntegrationTestCase
{
    private AdminService $adminService;

    protected function setUp(): void
    {
        parent::setUp();
        $syncTracker = $this->createStub(SyncTracker::class);
        $syncTracker->method('getAll')->willReturn([]);
        $this->adminService = new AdminService($this->em->getConnection(), $syncTracker);
    }

    public function testAUserWithARevokedAltCountsAsInvalidWhileItsValidMainStillCountsWithValidTokens(): void
    {
        $statsBefore = $this->adminService->getStats();

        $user = $this->createUser()->setLastLoginAt(new \DateTimeImmutable('-2 days'));
        $user->setMainCharacter($this->createCharacterWithToken($user, 'Main Pilot'));
        $this->createCharacterWithToken($user, 'Revoked Alt Pilot')->markAuthInvalid();
        $this->flushAndClear();

        $statsAfter = $this->adminService->getStats();

        self::assertSame(
            [
                'users.total' => 1,
                'users.valid' => 0,
                'users.invalid' => 1,
                'characters.total' => 2,
                'characters.withValidTokens' => 1,
                'characters.activeSyncScope' => 1,
            ],
            [
                'users.total' => $statsAfter['users']['total'] - $statsBefore['users']['total'],
                'users.valid' => $statsAfter['users']['valid'] - $statsBefore['users']['valid'],
                'users.invalid' => $statsAfter['users']['invalid'] - $statsBefore['users']['invalid'],
                'characters.total' => $statsAfter['characters']['total'] - $statsBefore['characters']['total'],
                'characters.withValidTokens' => $statsAfter['characters']['withValidTokens'] - $statsBefore['characters']['withValidTokens'],
                'characters.activeSyncScope' => $statsAfter['characters']['activeSyncScope'] - $statsBefore['characters']['activeSyncScope'],
            ],
        );
    }

    public function testAUserWhoseCharactersAreAllValidCountsAsValid(): void
    {
        $statsBefore = $this->adminService->getStats();

        $user = $this->createUser()->setLastLoginAt(new \DateTimeImmutable('-3 days'));
        $user->setMainCharacter($this->createCharacterWithToken($user, 'Main Pilot'));
        $this->createCharacterWithToken($user, 'Alt Pilot');
        $this->createCharacterWithToken($user, 'Second Alt Pilot');
        $this->flushAndClear();

        $statsAfter = $this->adminService->getStats();

        self::assertSame(
            ['users.valid' => 1, 'users.invalid' => 0, 'characters.withValidTokens' => 3, 'characters.activeSyncScope' => 3],
            [
                'users.valid' => $statsAfter['users']['valid'] - $statsBefore['users']['valid'],
                'users.invalid' => $statsAfter['users']['invalid'] - $statsBefore['users']['invalid'],
                'characters.withValidTokens' => $statsAfter['characters']['withValidTokens'] - $statsBefore['characters']['withValidTokens'],
                'characters.activeSyncScope' => $statsAfter['characters']['activeSyncScope'] - $statsBefore['characters']['activeSyncScope'],
            ],
        );
    }

    private function createCharacterWithToken(User $user, string $name): Character
    {
        $character = $this->createCharacter($user, $name);
        $token = (new EveToken())
            ->setAccessToken('access-' . $name)
            ->setRefreshTokenEncrypted('refresh-' . $name)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'));
        $character->setEveToken($token);
        $this->em->persist($token);

        return $character;
    }
}
