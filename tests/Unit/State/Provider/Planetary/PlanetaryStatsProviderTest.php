<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Planetary\PlanetaryStatsResource;
use App\Entity\Character;
use App\Entity\Notification;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\User;
use App\Entity\UserNotificationPreference;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\UserNotificationPreferenceRepository;
use App\Service\Planetary\PlanetaryExpiryThresholdResolver;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\State\Provider\Planetary\PlanetaryStatsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(PlanetaryStatsProvider::class)]
#[Group('issue-537')]
final class PlanetaryStatsProviderTest extends TestCase
{
    private const int BARREN_EXTRACTOR_CONTROL_UNIT = 2848;
    private const int AQUEOUS_LIQUIDS = 2268;
    private const int BASE_METALS = 2267;
    private const int HEAVY_METALS = 2272;

    private int $nextPinId = 1;

    public function testExtractorsExpiringWithinTheUserThresholdAreCountedExpiringAndTheNearestExpiryIsTheSoonest(): void
    {
        $soonest = $this->extractor(self::AQUEOUS_LIQUIDS, '+300 minutes');
        $beyondThreshold = $this->extractor(self::BASE_METALS, '+420 minutes');
        $expired = $this->extractor(self::HEAVY_METALS, '-1 hour');

        $stats = $this->statsOf(360, [$beyondThreshold, $soonest, $expired]);

        $this->assertSame(
            ['total' => 3, 'active' => 2, 'expiring' => 1, 'expired' => 1],
            ['total' => $stats->totalExtractors, 'active' => $stats->activeExtractors, 'expiring' => $stats->expiringExtractors, 'expired' => $stats->expiredExtractors],
        );
        $this->assertSame($soonest->getExpiryTime()?->format('c'), $stats->nearestExpiry);
    }

    public function testAnExtractorBeyondAShortUserThresholdIsActiveEvenWhenItExpiresWithinOneDay(): void
    {
        $stats = $this->statsOf(60, [$this->extractor(self::BASE_METALS, '+300 minutes')]);

        $this->assertSame([1, 0, 0], [$stats->activeExtractors, $stats->expiringExtractors, $stats->expiredExtractors]);
    }

    public function testWithoutPlanetaryPreferenceTheThresholdIsOneDay(): void
    {
        $within = $this->extractor(self::AQUEOUS_LIQUIDS, '+23 hours');
        $beyond = $this->extractor(self::BASE_METALS, '+25 hours');

        $stats = $this->statsOf(null, [$within, $beyond]);

        $this->assertSame([2, 1, 0], [$stats->activeExtractors, $stats->expiringExtractors, $stats->expiredExtractors]);
        $this->assertSame($within->getExpiryTime()?->format('c'), $stats->nearestExpiry);
    }

    public function testExposesTheUserThresholdOfTheExpiryWindow(): void
    {
        $stats = $this->statsOf(360, []);

        $this->assertSame(360, $stats->expiryThresholdMinutes);
    }

    public function testExposesOneDayAsTheThresholdWithoutPlanetaryPreference(): void
    {
        $stats = $this->statsOf(null, []);

        $this->assertSame(1440, $stats->expiryThresholdMinutes);
    }

    public function testOnlyTheThresholdOfTheAuthenticatedUserIsUsed(): void
    {
        $viewer = new User();
        $otherUser = new User();
        $thresholdsByUser = new \SplObjectStorage();
        $thresholdsByUser[$viewer] = 360;
        $thresholdsByUser[$otherUser] = 1440;

        $stats = $this->statsOf(null, [$this->extractor(self::BASE_METALS, '+420 minutes')], $viewer, $thresholdsByUser);

        $this->assertSame(360, $stats->expiryThresholdMinutes);
        $this->assertSame([1, 0, 0], [$stats->activeExtractors, $stats->expiringExtractors, $stats->expiredExtractors]);
    }

    private function statsOf(?int $thresholdMinutes, array $pins, ?User $authenticatedUser = null, ?\SplObjectStorage $thresholdsByUser = null): PlanetaryStatsResource
    {
        $user = $authenticatedUser ?? new User();
        $colony = (new PlanetaryColony())
            ->setCharacter((new Character())->setEveCharacterId(2112537001)->setName('Planetary Pilot'))
            ->setPlanetId(40009077)
            ->setPlanetType('barren')
            ->setSolarSystemId(30000142)
            ->setUpgradeLevel(4)
            ->setNumPins(count($pins))
            ->setLastUpdate(new \DateTimeImmutable('2026-11-09T09:00:00Z'));
        foreach ($pins as $pin) {
            $colony->addPin($pin);
        }

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByUser')->willReturn([$colony]);
        $preferenceRepository = $this->createStub(UserNotificationPreferenceRepository::class);
        $preferenceRepository->method('findByUserAndCategory')->willReturnCallback(
            static function (User $owner) use ($thresholdMinutes, $thresholdsByUser): ?UserNotificationPreference {
                $minutes = $thresholdsByUser !== null && $thresholdsByUser->offsetExists($owner) ? $thresholdsByUser[$owner] : $thresholdMinutes;

                return $minutes === null
                    ? null
                    : (new UserNotificationPreference())->setCategory(Notification::CATEGORY_PLANETARY)->setThresholdMinutes($minutes);
            },
        );
        $productionCalculator = $this->createStub(PlanetaryProductionCalculator::class);
        $productionCalculator->method('calculateTotalDailyIsk')->willReturn(0.0);

        $provider = new PlanetaryStatsProvider(
            $security,
            $colonyRepository,
            $productionCalculator,
            new PlanetaryExpiryThresholdResolver($preferenceRepository),
        );

        return $provider->provide(new Get());
    }

    private function extractor(int $productTypeId, string $expiresIn): PlanetaryPin
    {
        return (new PlanetaryPin())
            ->setPinId($this->nextPinId++)
            ->setTypeId(self::BARREN_EXTRACTOR_CONTROL_UNIT)
            ->setExtractorProductTypeId($productTypeId)
            ->setExpiryTime(new \DateTimeImmutable($expiresIn));
    }
}
