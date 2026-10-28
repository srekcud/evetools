<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\User;
use App\Service\Industry\IndustryShoppingListBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryShoppingListBuilder::class)]
final class IndustryShoppingListBuilderSplitJobWithoutRunsTest extends TestCase
{
    use BuildsShoppingListOnGoldenSde;

    private const int NITROGEN_FUEL_BLOCK = 4051;

    public function testAJobWithoutRunsAtTheHeadOfASplitGroupDoesNotStopTheShoppingListOfTheFollowingJob(): void
    {
        $project = self::projectOf(new User(), self::NITROGEN_FUEL_BLOCK, 25, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 0, 0, 'manufacturing')
            ->setSplitGroupId('manual-split')->setSplitIndex(0)->setTotalGroupRuns(25);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 25, 0, 'manufacturing')
            ->setSplitGroupId('manual-split')->setSplitIndex(1)->setTotalGroupRuns(25);

        self::assertSame([
            9832 => 203,
            44 => 90,
            16272 => 3825,
            16273 => 7875,
            3689 => 90,
            17888 => 10125,
            3683 => 495,
            9848 => 25,
            16275 => 450,
        ], array_column($this->shoppingListBuilderOnGoldenSde()->getShoppingList($project)['materials'], 'quantity', 'typeId'));
    }
}
