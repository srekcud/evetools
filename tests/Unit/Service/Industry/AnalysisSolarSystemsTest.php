<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Constant\EveConstants;
use App\Entity\IndustryUserSettings;
use App\Service\Industry\AnalysisSolarSystems;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-128')]
#[CoversClass(AnalysisSolarSystems::class)]
final class AnalysisSolarSystemsTest extends TestCase
{
    private const int FAVORITE_MANUFACTURING_SYSTEM_ID = 30000142;
    private const int FAVORITE_REACTION_SYSTEM_ID = 30000144;
    private const int REQUESTED_SYSTEM_ID = 30002187;

    public function testWithoutRequestedSystemManufacturingTakesTheFavoriteManufacturingSystemAndReactionTheFavoriteReactionSystem(): void
    {
        $systems = AnalysisSolarSystems::resolve(null, $this->settings(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID));

        $this->assertSame(30000142, $systems->manufacturingSolarSystemId);
        $this->assertSame(30000144, $systems->reactionSolarSystemId);
    }

    public function testRequestedSystemAppliesToManufacturingAndReaction(): void
    {
        $systems = AnalysisSolarSystems::resolve(self::REQUESTED_SYSTEM_ID, $this->settings(self::FAVORITE_MANUFACTURING_SYSTEM_ID, self::FAVORITE_REACTION_SYSTEM_ID));

        $this->assertSame(30002187, $systems->manufacturingSolarSystemId);
        $this->assertSame(30002187, $systems->reactionSolarSystemId);
    }

    public function testWithoutFavoriteReactionSystemReactionFollowsManufacturing(): void
    {
        $systems = AnalysisSolarSystems::resolve(null, $this->settings(30002510, null));

        $this->assertSame(30002510, $systems->manufacturingSolarSystemId);
        $this->assertSame(30002510, $systems->reactionSolarSystemId);
    }

    public function testWithoutFavoriteManufacturingSystemManufacturingFallsBackToJitaAndReactionTakesTheFavoriteReactionSystem(): void
    {
        $systems = AnalysisSolarSystems::resolve(null, $this->settings(null, self::FAVORITE_REACTION_SYSTEM_ID));

        $this->assertSame(EveConstants::JITA_SOLAR_SYSTEM_ID, $systems->manufacturingSolarSystemId);
        $this->assertSame(30000144, $systems->reactionSolarSystemId);
    }

    public function testWithoutSettingsBothFallBackToJita(): void
    {
        $systems = AnalysisSolarSystems::resolve(null, null);

        $this->assertSame(EveConstants::JITA_SOLAR_SYSTEM_ID, $systems->manufacturingSolarSystemId);
        $this->assertSame(EveConstants::JITA_SOLAR_SYSTEM_ID, $systems->reactionSolarSystemId);
    }

    public function testRequestedSystemWithoutSettingsAppliesToBoth(): void
    {
        $systems = AnalysisSolarSystems::resolve(self::REQUESTED_SYSTEM_ID, null);

        $this->assertSame(30002187, $systems->manufacturingSolarSystemId);
        $this->assertSame(30002187, $systems->reactionSolarSystemId);
    }

    private function settings(?int $favoriteManufacturingSystemId, ?int $favoriteReactionSystemId): IndustryUserSettings
    {
        return (new IndustryUserSettings())
            ->setFavoriteManufacturingSystemId($favoriteManufacturingSystemId)
            ->setFavoriteReactionSystemId($favoriteReactionSystemId);
    }
}
