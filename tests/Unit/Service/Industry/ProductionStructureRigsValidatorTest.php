<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Service\Industry\ProductionStructureRigsValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionStructureRigsValidator::class)]
#[Group('issue-523')]
final class ProductionStructureRigsValidatorTest extends TestCase
{
    private const string M_SET_BLUEPRINT_COPY_ACCELERATOR_II = 'Standup M-Set Blueprint Copy Accelerator II';
    private const string L_SET_BLUEPRINT_COPY_OPTIMIZATION_II = 'Standup L-Set Blueprint Copy Optimization II';
    private const string REMOVED_M_SET_LABORATORY_OPTIMIZATION_II = 'Standup M-Set Laboratory Optimization II';

    public function testRaitaruAcceptsTheMSetBlueprintCopyAcceleratorII(): void
    {
        $this->assertSame([], self::violationsOf('raitaru', [self::M_SET_BLUEPRINT_COPY_ACCELERATOR_II]));
    }

    public function testAzbelAcceptsTheLSetBlueprintCopyOptimizationII(): void
    {
        $this->assertSame([], self::violationsOf('azbel', [self::L_SET_BLUEPRINT_COPY_OPTIMIZATION_II]));
    }

    public function testRaitaruRefusesTheLSetBlueprintCopyOptimizationIIAsTooLarge(): void
    {
        $this->assertSame(
            ['The rig "Standup L-Set Blueprint Copy Optimization II" does not fit a "raitaru" structure.'],
            self::violationsOf('raitaru', [self::L_SET_BLUEPRINT_COPY_OPTIMIZATION_II]),
        );
    }

    public function testRaitaruRefusesTheRemovedMSetLaboratoryOptimizationII(): void
    {
        $this->assertSame(
            ['The rig "Standup M-Set Laboratory Optimization II" is not in the rig catalogue.'],
            self::violationsOf('raitaru', [self::REMOVED_M_SET_LABORATORY_OPTIMIZATION_II]),
        );
    }

    #[DataProvider('laboratoryRigsOfTheGezRigList')]
    public function testTheLaboratoryRigsOfTheGezRigListAreAcceptedOnTheStructureTheyFit(string $structureType, string $rig): void
    {
        $this->assertSame([], self::violationsOf($structureType, [$rig]));
    }

    public static function laboratoryRigsOfTheGezRigList(): iterable
    {
        yield 'L-Set ME Research Optimization I on an azbel' => ['azbel', 'Standup L-Set ME Research Optimization I'];
        yield 'L-Set TE Research Optimization I on an azbel' => ['azbel', 'Standup L-Set TE Research Optimization I'];
        yield 'L-Set Blueprint Copy Optimization I on an azbel' => ['azbel', 'Standup L-Set Blueprint Copy Optimization I'];
        yield 'L-Set Invention Optimization I on an azbel' => ['azbel', 'Standup L-Set Invention Optimization I'];
        yield 'M-Set Invention Accelerator I on a raitaru' => ['raitaru', 'Standup M-Set Invention Accelerator I'];
        yield 'M-Set Invention Accelerator II on a raitaru' => ['raitaru', 'Standup M-Set Invention Accelerator II'];
        yield 'M-Set Invention Cost Optimization I on a raitaru' => ['raitaru', 'Standup M-Set Invention Cost Optimization I'];
        yield 'M-Set Invention Cost Optimization II on a raitaru' => ['raitaru', 'Standup M-Set Invention Cost Optimization II'];
        yield 'M-Set Blueprint Copy Accelerator I on a raitaru' => ['raitaru', 'Standup M-Set Blueprint Copy Accelerator I'];
        yield 'XL-Set Laboratory Optimization I on a sotiyo' => ['sotiyo', 'Standup XL-Set Laboratory Optimization I'];
        yield 'XL-Set Laboratory Optimization II on a sotiyo' => ['sotiyo', 'Standup XL-Set Laboratory Optimization II'];
    }

    public function testRaitaruRefusesTheLSetInventionOptimizationIAsTooLarge(): void
    {
        $this->assertSame(
            ['The rig "Standup L-Set Invention Optimization I" does not fit a "raitaru" structure.'],
            self::violationsOf('raitaru', ['Standup L-Set Invention Optimization I']),
        );
    }

    public function testAzbelRefusesTheMSetMEResearchAcceleratorIAsTooSmall(): void
    {
        $this->assertSame(
            ['The rig "Standup M-Set ME Research Accelerator I" does not fit a "azbel" structure.'],
            self::violationsOf('azbel', ['Standup M-Set ME Research Accelerator I']),
        );
    }

    public function testAthanorRefusesALaboratoryRigAsNotAReactionRig(): void
    {
        $this->assertSame(
            ['The rig "Standup M-Set Invention Accelerator I" does not fit a "athanor" structure.'],
            self::violationsOf('athanor', ['Standup M-Set Invention Accelerator I']),
        );
    }

    private static function violationsOf(string $structureType, array $rigs): array
    {
        try {
            (new ProductionStructureRigsValidator())->validate($structureType, $rigs);
        } catch (ValidationException $refusal) {
            $messages = [];
            foreach ($refusal->getConstraintViolationList() as $violation) {
                $messages[] = (string) $violation->getMessage();
            }

            return $messages;
        }

        return [];
    }
}
