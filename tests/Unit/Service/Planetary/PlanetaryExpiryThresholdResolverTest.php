<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserNotificationPreference;
use App\Repository\UserNotificationPreferenceRepository;
use App\Service\Planetary\PlanetaryExpiryThresholdResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryExpiryThresholdResolver::class)]
#[Group('issue-537')]
final class PlanetaryExpiryThresholdResolverTest extends TestCase
{
    public function testThresholdIsTheThresholdMinutesOfThePlanetaryPreferenceOfTheUser(): void
    {
        $user = new User();
        $repository = $this->createStub(UserNotificationPreferenceRepository::class);
        $repository->method('findByUserAndCategory')->willReturnCallback(
            static fn (User $candidate, string $category): ?UserNotificationPreference => $candidate === $user && $category === Notification::CATEGORY_PLANETARY
                ? (new UserNotificationPreference())->setCategory($category)->setThresholdMinutes(360)
                : (new UserNotificationPreference())->setCategory($category)->setThresholdMinutes(45),
        );

        $this->assertSame(360, (new PlanetaryExpiryThresholdResolver($repository))->minutesFor($user));
    }

    public function testThresholdIsOneDayWhenThePlanetaryPreferenceHasNoThresholdMinutes(): void
    {
        $repository = $this->createStub(UserNotificationPreferenceRepository::class);
        $repository->method('findByUserAndCategory')->willReturn(
            (new UserNotificationPreference())->setCategory(Notification::CATEGORY_PLANETARY)->setThresholdMinutes(null),
        );

        $this->assertSame(1440, (new PlanetaryExpiryThresholdResolver($repository))->minutesFor(new User()));
    }

    public function testThresholdIsOneDayWithoutPlanetaryPreferenceAndTheReadCreatesNoPreference(): void
    {
        $repository = $this->createMock(UserNotificationPreferenceRepository::class);
        $repository->method('findByUserAndCategory')->willReturn(null);
        $repository->expects($this->never())->method('getOrCreateAll');

        $this->assertSame(1440, (new PlanetaryExpiryThresholdResolver($repository))->minutesFor(new User()));
    }
}
