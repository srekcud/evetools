<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Planetary\PlanetarySettingsResource;
use App\Entity\User;
use App\State\Provider\Planetary\PlanetarySettingsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(PlanetarySettingsProvider::class)]
#[Group('issue-1394')]
final class PlanetarySettingsProviderTest extends TestCase
{
    private const int DEFAULT_STRUCTURE_ID = 1_049_588_174_021;
    private const string DEFAULT_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)';
    private const int PREFERRED_STRUCTURE_ID = 1_028_858_195_007;
    private const string PREFERRED_STRUCTURE_NAME = 'Ashab - Preferred Fortizar';
    private const int FAVORITE_STRUCTURE_ID = 1_022_734_985_009;
    private const string FAVORITE_STRUCTURE_NAME = 'Amarr - Favorite Raitaru';
    private const int SECOND_FAVORITE_STRUCTURE_ID = 1_022_734_985_011;
    private const string SECOND_FAVORITE_STRUCTURE_NAME = 'Rens - Second Athanor';
    private const int HOME_LOCATION_ID = 60_008_494;
    private const string HOME_LOCATION_NAME = 'Amarr VIII (Oris) - Emperor Family Academy';

    public function testANewUserGetsNoHomeLocationThreeWeeksOfCoverageAndTheConfiguredDefaultHub(): void
    {
        $resource = $this->provide(new User());

        $this->assertSame('settings', $resource->id);
        $this->assertNull($resource->homeLocationId);
        $this->assertNull($resource->homeLocationName);
        $this->assertSame(3, $resource->coverageWeeks);
        $this->assertSame(self::DEFAULT_STRUCTURE_ID, $resource->hubStructureId);
        $this->assertSame(self::DEFAULT_STRUCTURE_NAME, $resource->hubStructureName);
        $this->assertSame([], $resource->excludedTypeIds);
    }

    #[Group('issue-1458')]
    public function testTheExcludedTypeIdsOfTheUserAreReturnedSortedAndWithoutDuplicates(): void
    {
        $user = (new User())->setPlanetaryExcludedTypeIds([9832, 2393, 9832]);

        $resource = $this->provide($user);

        $this->assertSame([2393, 9832], $resource->excludedTypeIds);
    }

    public function testTheStoredHomeLocationAndCoverageWeeksAreReturned(): void
    {
        $user = (new User())
            ->setPlanetaryHomeLocation(self::HOME_LOCATION_ID, self::HOME_LOCATION_NAME)
            ->setPlanetaryCoverageWeeks(7);

        $resource = $this->provide($user);

        $this->assertSame(self::HOME_LOCATION_ID, $resource->homeLocationId);
        $this->assertSame(self::HOME_LOCATION_NAME, $resource->homeLocationName);
        $this->assertSame(7, $resource->coverageWeeks);
    }

    public function testThePreferredMarketStructureIsTheHubEvenWhenFavoritesExist(): void
    {
        $user = (new User())
            ->setPreferredMarketStructureId(self::PREFERRED_STRUCTURE_ID)
            ->setPreferredMarketStructureName(self::PREFERRED_STRUCTURE_NAME)
            ->setMarketStructures([['id' => self::FAVORITE_STRUCTURE_ID, 'name' => self::FAVORITE_STRUCTURE_NAME]]);

        $resource = $this->provide($user);

        $this->assertSame(self::PREFERRED_STRUCTURE_ID, $resource->hubStructureId);
        $this->assertSame(self::PREFERRED_STRUCTURE_NAME, $resource->hubStructureName);
    }

    public function testTheFirstFavoriteIsTheHubWhenNoPreferredStructureIsSet(): void
    {
        $user = (new User())->setMarketStructures([
            ['id' => self::FAVORITE_STRUCTURE_ID, 'name' => self::FAVORITE_STRUCTURE_NAME],
            ['id' => self::SECOND_FAVORITE_STRUCTURE_ID, 'name' => self::SECOND_FAVORITE_STRUCTURE_NAME],
        ]);

        $resource = $this->provide($user);

        $this->assertSame(self::FAVORITE_STRUCTURE_ID, $resource->hubStructureId);
        $this->assertSame(self::FAVORITE_STRUCTURE_NAME, $resource->hubStructureName);
    }

    public function testAnAnonymousVisitorIsRejected(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provide(null);
    }

    private function provide(?User $user): PlanetarySettingsResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = new PlanetarySettingsProvider($security, self::DEFAULT_STRUCTURE_ID, self::DEFAULT_STRUCTURE_NAME);

        return $provider->provide(new Get());
    }
}
