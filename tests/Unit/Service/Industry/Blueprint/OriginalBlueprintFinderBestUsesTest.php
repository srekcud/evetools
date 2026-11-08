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
#[Group('issue-411')]
final class OriginalBlueprintFinderBestUsesTest extends TestCase
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

    public function testKeepsTheBestBlueprintOfEachTypeAmongThePersonalOnesOfAllTheCharacters(): void
    {
        $finder = new OriginalBlueprintFinder(
            $this->repositoryHolding([
                $this->personalBlueprint($this->main, 8_101, self::DRAKE_BLUEPRINT, -1, -1, 5, 10),
                $this->personalBlueprint($this->alt, 8_102, self::DRAKE_BLUEPRINT, -2, 12, 8, 16),
                $this->personalBlueprint($this->main, 8_103, self::VEXOR_BLUEPRINT, -1, -1, 2, 4),
            ]),
            $this->visibilityOf([]),
        );

        $uses = $finder->bestUsesOwnedBy($this->user);

        self::assertSame([self::DRAKE_BLUEPRINT, self::VEXOR_BLUEPRINT], array_keys($uses));
        self::assertSame([8, 16, false], [$uses[self::DRAKE_BLUEPRINT]->efficiency->materialEfficiency->value, $uses[self::DRAKE_BLUEPRINT]->efficiency->timeEfficiency->value, $uses[self::DRAKE_BLUEPRINT]->original]);
        self::assertSame([2, 4, true], [$uses[self::VEXOR_BLUEPRINT]->efficiency->materialEfficiency->value, $uses[self::VEXOR_BLUEPRINT]->efficiency->timeEfficiency->value, $uses[self::VEXOR_BLUEPRINT]->original]);
    }

    public function testOriginalWinsAtEqualEfficiencies(): void
    {
        $finder = new OriginalBlueprintFinder(
            $this->repositoryHolding([
                $this->personalBlueprint($this->main, 8_111, self::DRAKE_BLUEPRINT, -2, 12, 8, 16),
                $this->personalBlueprint($this->alt, 8_112, self::DRAKE_BLUEPRINT, -1, -1, 8, 16),
            ]),
            $this->visibilityOf([]),
        );

        self::assertTrue($finder->bestUsesOwnedBy($this->user)[self::DRAKE_BLUEPRINT]->original);
    }

    public function testCorporationBlueprintsCountOnlyInAVisibleDivision(): void
    {
        $finder = new OriginalBlueprintFinder(
            $this->repositoryHolding([
                $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 8_121, self::ATRON_BLUEPRINT, -1, -1, 9, 18, 3),
                $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 8_122, self::HURRICANE_BLUEPRINT, -1, -1, 6, 12, 5),
                $this->corporationBlueprint(self::OTHER_CORPORATION_ID, 8_123, self::DRAKE_BLUEPRINT, -1, -1, 1, 2, 3),
            ]),
            $this->visibilityOf([self::MAIN_CORPORATION_ID => [3], self::OTHER_CORPORATION_ID => [3]]),
        );

        $uses = $finder->bestUsesOwnedBy($this->user);

        self::assertSame([self::ATRON_BLUEPRINT], array_keys($uses));
        self::assertSame(9, $uses[self::ATRON_BLUEPRINT]->efficiency->materialEfficiency->value);
    }

    public function testOwnsNothingWhenThereIsNoBlueprint(): void
    {
        $finder = new OriginalBlueprintFinder($this->repositoryHolding([]), $this->visibilityOf([]));

        self::assertSame([], $finder->bestUsesOwnedBy($this->user));
    }
}
