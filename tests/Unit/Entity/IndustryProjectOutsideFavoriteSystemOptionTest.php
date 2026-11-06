<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProject::class)]
final class IndustryProjectOutsideFavoriteSystemOptionTest extends TestCase
{
    private const float DEFAULT_MINIMUM_OUTSIDE_SAVING_IN_ISK = 500000.0;
    private const float MINIMUM_OUTSIDE_SAVING_IN_ISK = 125000.0;

    public function testProjectRefusesAStructureOutsideTheFavoriteSystemByDefault(): void
    {
        $project = new IndustryProject();

        $this->assertFalse($project->isAcceptsOutsideFavoriteSystem());
    }

    public function testMinimumOutsideSavingDefaultsToFiveHundredThousandIsk(): void
    {
        $project = new IndustryProject();

        $this->assertSame(self::DEFAULT_MINIMUM_OUTSIDE_SAVING_IN_ISK, IndustryProject::DEFAULT_MINIMUM_OUTSIDE_SAVING);
        $this->assertSame(self::DEFAULT_MINIMUM_OUTSIDE_SAVING_IN_ISK, $project->getMinimumOutsideSaving());
    }

    public function testOutsideFavoriteSystemOptionKeepsTheValuesItIsGiven(): void
    {
        $project = (new IndustryProject())
            ->setAcceptsOutsideFavoriteSystem(true)
            ->setMinimumOutsideSaving(self::MINIMUM_OUTSIDE_SAVING_IN_ISK);

        $this->assertTrue($project->isAcceptsOutsideFavoriteSystem());
        $this->assertSame(self::MINIMUM_OUTSIDE_SAVING_IN_ISK, $project->getMinimumOutsideSaving());
    }
}
