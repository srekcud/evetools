<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\WorkType;
use App\Industry\Application\WorkTypeClassifier;
use App\Industry\Domain\ActivityKind;
use App\Tests\Unit\Industry\Application\Double\InMemoryShipProducts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkTypeClassifier::class)]
#[Group('issue-1285')]
final class WorkTypeClassifierTest extends TestCase
{
    private const int ISHTAR = 12005;
    private const int HECATE = 35683;
    private const int RAM_STARSHIP_TECH = 11478;
    private const int FERNITE_COMPOSITE = 16670;

    public function testReactionActivityIsAReactionEvenForATargetProduct(): void
    {
        $workType = $this->classifier()->classify(self::FERNITE_COMPOSITE, ActivityKind::Reaction, [self::FERNITE_COMPOSITE, self::ISHTAR]);

        $this->assertSame(WorkType::Reaction, $workType);
    }

    public function testManufacturingOfATargetProductIsAFinalProductEvenWhenItIsAShip(): void
    {
        $workType = $this->classifier()->classify(self::ISHTAR, ActivityKind::Manufacturing, [self::RAM_STARSHIP_TECH, self::ISHTAR]);

        $this->assertSame(WorkType::FinalProducts, $workType);
    }

    public function testManufacturingOfAShipThatIsNotATargetIsAHull(): void
    {
        $workType = $this->classifier()->classify(self::HECATE, ActivityKind::Manufacturing, [self::ISHTAR]);

        $this->assertSame(WorkType::Hulls, $workType);
    }

    public function testManufacturingOfAnythingElseIsAComponent(): void
    {
        $workType = $this->classifier()->classify(self::RAM_STARSHIP_TECH, ActivityKind::Manufacturing, [self::ISHTAR]);

        $this->assertSame(WorkType::Components, $workType);
    }

    public function testManufacturingWithoutTargetProductsClassifiesByTheShipCategoryAlone(): void
    {
        $classifier = $this->classifier();

        $this->assertSame(
            [WorkType::Hulls, WorkType::Components],
            [
                $classifier->classify(self::ISHTAR, ActivityKind::Manufacturing, []),
                $classifier->classify(self::RAM_STARSHIP_TECH, ActivityKind::Manufacturing, []),
            ],
        );
    }

    private function classifier(): WorkTypeClassifier
    {
        return new WorkTypeClassifier(new InMemoryShipProducts([self::ISHTAR, self::HECATE]));
    }
}
