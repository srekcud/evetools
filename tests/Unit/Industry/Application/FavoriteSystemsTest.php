<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\SecurityClass;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FavoriteSystems::class)]
final class FavoriteSystemsTest extends TestCase
{
    private const int FAVORITE_MANUFACTURING_SYSTEM_ID = 30002187;
    private const int FAVORITE_REACTION_SYSTEM_ID = 30000142;
    private const int OTHER_SYSTEM_ID = 30004759;

    #[DataProvider('favoriteSystemByActivityProvider')]
    public function testForActivityGivesTheReactionFavoriteForReactionsAndTheManufacturingFavoriteOtherwise(ActivityKind $activity, int $expectedFavoriteSystemId): void
    {
        $favoriteSystems = new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID);

        $this->assertSame($expectedFavoriteSystemId, $favoriteSystems->forActivity($activity));
    }

    public static function favoriteSystemByActivityProvider(): iterable
    {
        yield 'manufacturing' => [ActivityKind::Manufacturing, self::FAVORITE_MANUFACTURING_SYSTEM_ID];
        yield 'reaction' => [ActivityKind::Reaction, self::FAVORITE_REACTION_SYSTEM_ID];
        yield 'copying' => [ActivityKind::Copying, self::FAVORITE_MANUFACTURING_SYSTEM_ID];
        yield 'invention' => [ActivityKind::Invention, self::FAVORITE_MANUFACTURING_SYSTEM_ID];
    }

    #[DataProvider('everyActivityProvider')]
    public function testNoneHasNoFavoriteSystemForAnyActivity(ActivityKind $activity): void
    {
        $favoriteSystems = FavoriteSystems::none();

        $this->assertNull($favoriteSystems->manufacturingSystemId);
        $this->assertNull($favoriteSystems->reactionSystemId);
        $this->assertNull($favoriteSystems->forActivity($activity));
    }

    public function testOnlyTheReactionFavoriteLeavesManufacturingWithoutFavoriteSystem(): void
    {
        $favoriteSystems = new FavoriteSystems(null, self::FAVORITE_REACTION_SYSTEM_ID);

        $this->assertNull($favoriteSystems->forActivity(ActivityKind::Manufacturing));
        $this->assertSame(self::FAVORITE_REACTION_SYSTEM_ID, $favoriteSystems->forActivity(ActivityKind::Reaction));
    }

    public function testStructureInTheFavoriteSystemOfTheActivityIsContained(): void
    {
        $favoriteSystems = new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID);

        $this->assertTrue($favoriteSystems->contains(ProductionStructures::raitaru('raitaru-favorite', SecurityClass::NullSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID), ActivityKind::Manufacturing));
        $this->assertTrue($favoriteSystems->contains(ProductionStructures::tatara('tatara-favorite', SecurityClass::LowSec, self::FAVORITE_REACTION_SYSTEM_ID), ActivityKind::Reaction));
    }

    public function testStructureInAnotherSystemIsNotContained(): void
    {
        $favoriteSystems = new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID);

        $this->assertFalse($favoriteSystems->contains(ProductionStructures::raitaru('raitaru-elsewhere', SecurityClass::NullSec, self::OTHER_SYSTEM_ID), ActivityKind::Manufacturing));
    }

    public function testStructureInTheFavoriteSystemOfAnotherActivityIsNotContained(): void
    {
        $favoriteSystems = new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID);

        $this->assertFalse($favoriteSystems->contains(ProductionStructures::tatara('tatara-in-manufacturing-favorite', SecurityClass::LowSec, self::FAVORITE_MANUFACTURING_SYSTEM_ID), ActivityKind::Reaction));
    }

    public function testStructureWithUnknownSystemIsNotContainedWhenTheActivityHasAFavoriteSystem(): void
    {
        $favoriteSystems = new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID);

        $this->assertFalse($favoriteSystems->contains(ProductionStructures::raitaru('raitaru-system-unknown'), ActivityKind::Manufacturing));
    }

    public function testStructureWithUnknownSystemIsNotContainedWhenTheActivityHasNoFavoriteSystem(): void
    {
        $favoriteSystems = new FavoriteSystems(null, self::FAVORITE_REACTION_SYSTEM_ID);

        $this->assertFalse($favoriteSystems->contains(ProductionStructures::raitaru('raitaru-system-unknown'), ActivityKind::Manufacturing));
    }

    public function testSystemOfAStructureWithKnownSystemWinsOverTheFavoriteSystem(): void
    {
        $favoriteSystems = new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID);

        $solarSystemId = $favoriteSystems->systemOrFavorite(ProductionStructures::raitaru('raitaru-elsewhere', SecurityClass::NullSec, self::OTHER_SYSTEM_ID), ActivityKind::Manufacturing);

        $this->assertSame(self::OTHER_SYSTEM_ID, $solarSystemId);
    }

    public function testStructureWithUnknownSystemTakesTheFavoriteSystemOfTheActivity(): void
    {
        $favoriteSystems = new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID);

        $solarSystemId = $favoriteSystems->systemOrFavorite(ProductionStructures::tatara('tatara-system-unknown'), ActivityKind::Reaction);

        $this->assertSame(self::FAVORITE_REACTION_SYSTEM_ID, $solarSystemId);
    }

    public function testJobWithoutStructureTakesTheFavoriteSystemOfTheActivity(): void
    {
        $favoriteSystems = new FavoriteSystems(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID);

        $this->assertSame(self::FAVORITE_MANUFACTURING_SYSTEM_ID, $favoriteSystems->systemOrFavorite(null, ActivityKind::Invention));
    }

    public function testJobWithoutStructureNorFavoriteSystemHasAnUnknownSystem(): void
    {
        $this->assertNull(FavoriteSystems::none()->systemOrFavorite(null, ActivityKind::Copying));
    }

    public static function everyActivityProvider(): iterable
    {
        yield 'manufacturing' => [ActivityKind::Manufacturing];
        yield 'reaction' => [ActivityKind::Reaction];
        yield 'copying' => [ActivityKind::Copying];
        yield 'invention' => [ActivityKind::Invention];
    }
}
