<?php

declare(strict_types=1);

namespace App\Tests\Integration\Entity;

use App\Entity\User;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1394')]
final class UserPlanetarySettingsTest extends IntegrationTestCase
{
    private const int HOME_LOCATION_ID = 1_030_000_000_123;
    private const string HOME_LOCATION_NAME = 'Ashab - Market Fortizar';

    public function testTheHomeLocationItsNameAndTheCoverageWeeksSurviveAReload(): void
    {
        $user = $this->createUser();
        $user->setPlanetaryHomeLocation(self::HOME_LOCATION_ID, self::HOME_LOCATION_NAME)->setPlanetaryCoverageWeeks(52);
        $userId = $user->getId();
        $this->flushAndClear();

        $reloaded = $this->em->find(User::class, $userId);

        $this->assertSame(self::HOME_LOCATION_ID, $reloaded->getPlanetaryHomeLocationId());
        $this->assertSame(self::HOME_LOCATION_NAME, $reloaded->getPlanetaryHomeLocationName());
        $this->assertSame(52, $reloaded->getPlanetaryCoverageWeeks());
    }

    public function testANewUserHasNoHomeLocationAndThreeWeeksOfCoverage(): void
    {
        $userId = $this->createUser()->getId();
        $this->flushAndClear();

        $reloaded = $this->em->find(User::class, $userId);

        $this->assertNull($reloaded->getPlanetaryHomeLocationId());
        $this->assertNull($reloaded->getPlanetaryHomeLocationName());
        $this->assertSame(3, $reloaded->getPlanetaryCoverageWeeks());
    }

    public function testTheColumnsCarryTheDatabaseDefaultAndTheNullability(): void
    {
        $columns = $this->em->getConnection()->fetchAllAssociative(
            "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = 'users' AND column_name LIKE 'planetary_%' ORDER BY column_name",
        );

        $coverageDefault = $this->em->getConnection()->fetchOne(
            "SELECT column_default FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'planetary_coverage_weeks'",
        );

        $this->assertSame('3', $coverageDefault);
        $this->assertSame([
            ['column_name' => 'planetary_coverage_weeks', 'data_type' => 'smallint', 'is_nullable' => 'NO'],
            ['column_name' => 'planetary_home_location_id', 'data_type' => 'bigint', 'is_nullable' => 'YES'],
            ['column_name' => 'planetary_home_location_name', 'data_type' => 'character varying', 'is_nullable' => 'YES'],
        ], $columns);
    }
}
