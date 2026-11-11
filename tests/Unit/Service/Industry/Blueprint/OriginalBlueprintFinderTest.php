<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Blueprint;

use App\Entity\Character;
use App\Entity\User;
use App\Service\Industry\Blueprint\OriginalBlueprintFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriginalBlueprintFinder::class)]
#[Group('issue-357')]
#[Group('corp-blueprints-hidden-divisions')]
final class OriginalBlueprintFinderTest extends TestCase
{
    use BuildsBlueprintLibrary;

    private Character $main;
    private Character $alt;
    private User $user;

    protected function setUp(): void
    {
        $this->main = $this->pilot('Aria Solberg', self::MAIN_CORPORATION_ID, self::MAIN_CORPORATION_NAME, [self::PERSONAL_SCOPE, self::CORPORATION_SCOPE]);
        $this->alt = $this->pilot('Brann Holt', self::ALT_CORPORATION_ID, 'Holt Haulage', [self::PERSONAL_SCOPE]);
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->main, $this->alt);
    }

    public function testListsTheTypeIdsOfTheOriginalsOfAllTheCharactersOfTheUser(): void
    {
        $finder = new OriginalBlueprintFinder(
            $this->repositoryHolding([
                $this->personalBlueprint($this->main, 8_001, self::DRAKE_BLUEPRINT, -1, -1, 10, 20),
                $this->personalBlueprint($this->alt, 8_002, self::VEXOR_BLUEPRINT, 2, -1, 4, 8),
                $this->personalBlueprint($this->main, 8_003, self::RIFTER_BLUEPRINT, -2, 12, 8, 16),
            ]),
            $this->visibilityOf([]),
        );

        $typeIds = $finder->typeIdsOwnedBy($this->user);
        sort($typeIds);

        self::assertSame([self::DRAKE_BLUEPRINT, self::VEXOR_BLUEPRINT], $typeIds);
    }

    public function testListsTheOriginalsOfTheCorporationInAVisibleDivisionOnly(): void
    {
        $finder = new OriginalBlueprintFinder(
            $this->repositoryHolding([
                $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 8_011, self::ATRON_BLUEPRINT, -1, -1, 9, 18, 3),
                $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 8_012, self::HURRICANE_BLUEPRINT, -1, -1, 6, 12, 5),
                $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 8_013, self::HAMMERHEAD_II_BLUEPRINT, -1, -1, 2, 4, null),
                $this->corporationBlueprint(self::OTHER_CORPORATION_ID, 8_014, self::DRAKE_BLUEPRINT, -1, -1, 1, 2, 3),
            ]),
            $this->visibilityOf([self::MAIN_CORPORATION_ID => [3], self::OTHER_CORPORATION_ID => [3]]),
        );

        self::assertSame([self::ATRON_BLUEPRINT], $finder->typeIdsOwnedBy($this->user));
    }

    public function testCopiesAloneRemoveNothing(): void
    {
        $finder = new OriginalBlueprintFinder(
            $this->repositoryHolding([
                $this->personalBlueprint($this->main, 8_021, self::RIFTER_BLUEPRINT, -2, 12, 8, 16),
                $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 8_022, self::HAMMERHEAD_II_BLUEPRINT, -2, 50, 2, 4, 3),
            ]),
            $this->visibilityOf([self::MAIN_CORPORATION_ID => [3]]),
        );

        self::assertSame([], $finder->typeIdsOwnedBy($this->user));
    }

    public function testListsTheOriginalsOfTheCorporationInEveryDivisionWhenBlueprintsOfHiddenDivisionsAreShared(): void
    {
        $finder = new OriginalBlueprintFinder(
            $this->repositoryHolding([
                $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 8_031, self::ATRON_BLUEPRINT, -1, -1, 9, 18, 3),
                $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 8_032, self::HURRICANE_BLUEPRINT, -1, -1, 6, 12, 5),
                $this->corporationBlueprint(self::OTHER_CORPORATION_ID, 8_033, self::DRAKE_BLUEPRINT, -1, -1, 1, 2, 5),
            ]),
            $this->visibilityOf([self::MAIN_CORPORATION_ID => [3]], sharesBlueprintsOfHiddenDivisions: true),
        );

        $typeIds = $finder->typeIdsOwnedBy($this->user);
        sort($typeIds);

        self::assertSame([self::ATRON_BLUEPRINT, self::HURRICANE_BLUEPRINT], $typeIds);
    }

    public function testListsTheOriginalsOfTheCorporationInEveryDivisionWithoutVisibilitySetting(): void
    {
        $finder = new OriginalBlueprintFinder(
            $this->repositoryHolding([
                $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 8_041, self::ATRON_BLUEPRINT, -1, -1, 9, 18, 3),
                $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 8_042, self::HURRICANE_BLUEPRINT, -1, -1, 6, 12, 5),
            ]),
            $this->visibilityOf([]),
        );

        $typeIds = $finder->typeIdsOwnedBy($this->user);
        sort($typeIds);

        self::assertSame([self::ATRON_BLUEPRINT, self::HURRICANE_BLUEPRINT], $typeIds);
    }
}
