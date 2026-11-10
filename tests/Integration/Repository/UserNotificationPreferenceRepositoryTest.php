<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\UserNotificationPreferenceRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-537')]
final class UserNotificationPreferenceRepositoryTest extends IntegrationTestCase
{
    private UserNotificationPreferenceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(UserNotificationPreferenceRepository::class);
    }

    public function testDefaultPreferencesGiveOneDayToPlanetaryAndOneHourToEscalationAndNoThresholdToOtherCategories(): void
    {
        $user = $this->createUser();
        $this->flushAndClear();
        $user = $this->em->find($user::class, $user->getId());

        $thresholds = [];
        foreach ($this->repository->getOrCreateAll($user) as $preference) {
            $thresholds[$preference->getCategory()] = $preference->getThresholdMinutes();
        }
        ksort($thresholds);

        self::assertSame(
            [
                'escalation' => 60,
                'esi' => null,
                'industry' => null,
                'mining_operation' => null,
                'moon_chunk' => null,
                'planetary' => 1440,
                'price' => null,
            ],
            $thresholds,
        );
    }
}
