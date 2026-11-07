<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\PlannedProduction;
use App\Industry\Application\StructureChoice;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Plan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlannedProduction::class)]
final class PlannedProductionTest extends TestCase
{
    public function testKeysAChoiceByTheActivityNameThenTheProductTypeId(): void
    {
        self::assertSame('Manufacturing:12005', PlannedProduction::choiceKey(12005, ActivityKind::Manufacturing));
    }

    public function testKeysTheSameProductDifferentlyForAReaction(): void
    {
        self::assertSame('Reaction:12005', PlannedProduction::choiceKey(12005, ActivityKind::Reaction));
    }

    public function testFindsTheChoiceOfTheProductAndTheActivityAskedAndNoOtherOne(): void
    {
        $manufacturingChoice = StructureChoice::notConfigured();
        $reactionChoice = StructureChoice::notConfigured();
        $planned = new PlannedProduction(new Plan([], [], []), [
            'Manufacturing:12005' => $manufacturingChoice,
            'Reaction:16670' => $reactionChoice,
        ]);

        self::assertSame(
            [$manufacturingChoice, $reactionChoice, null, null],
            [
                $planned->structureChoiceFor(12005, ActivityKind::Manufacturing),
                $planned->structureChoiceFor(16670, ActivityKind::Reaction),
                $planned->structureChoiceFor(12005, ActivityKind::Reaction),
                $planned->structureChoiceFor(16670, ActivityKind::Manufacturing),
            ],
        );
    }
}
