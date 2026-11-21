<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Service\Industry\Project\ChainManufacturingLevels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChainManufacturingLevels::class)]
#[Group('issue-449')]
final class ChainManufacturingLevelsTest extends TestCase
{
    use BuildsChainBomLines;

    private const int REACTION = 449_001;
    private const int COMPONENT = 449_002;
    private const int CAPITAL_COMPONENT = 449_003;
    private const int PRODUCT = 449_004;
    private const int SECOND_REACTION = 449_005;
    private const int CRYSTALLINE_CARBONIDE = 1_153_001;
    private const int PHOTONIC_METAMATERIALS = 1_153_002;
    private const int CRYSTALLITE_ALLOY = 1_153_003;
    private const int CARBON_POLYMERS = 1_153_004;

    public function testReactionIsLevelOneAndEachConsumerIsOneAboveItsHighestChild(): void
    {
        $levels = ChainManufacturingLevels::byTypeId(
            [
                $this->jobLine(self::PRODUCT, 'Product', 'final', null),
                $this->jobLine(self::CAPITAL_COMPONENT, 'Capital component', 'component', self::PRODUCT),
                $this->jobLine(self::COMPONENT, 'Component', 'component', self::CAPITAL_COMPONENT),
                $this->jobLine(self::REACTION, 'Reaction', 'component', self::COMPONENT, activityType: 'reaction'),
            ],
            [self::PRODUCT => [self::CAPITAL_COMPONENT], self::CAPITAL_COMPONENT => [self::COMPONENT], self::COMPONENT => [self::REACTION], self::REACTION => []],
        );

        self::assertSame(
            [self::REACTION => 1, self::COMPONENT => 2, self::CAPITAL_COMPONENT => 3, self::PRODUCT => 4],
            $this->sortedByTypeId($levels),
        );
    }

    public function testProductTakesTheHighestLevelAmongItsDirectChildren(): void
    {
        $levels = ChainManufacturingLevels::byTypeId(
            [
                $this->jobLine(self::PRODUCT, 'Product', 'final', null),
                $this->jobLine(self::SECOND_REACTION, 'Direct reaction', 'component', self::PRODUCT, activityType: 'reaction'),
                $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT),
                $this->jobLine(self::REACTION, 'Reaction', 'component', self::COMPONENT, activityType: 'reaction'),
            ],
            [self::PRODUCT => [self::SECOND_REACTION, self::COMPONENT], self::COMPONENT => [self::REACTION], self::REACTION => [], self::SECOND_REACTION => []],
        );

        self::assertSame(3, $levels[self::PRODUCT]);
        self::assertSame(1, $levels[self::SECOND_REACTION]);
    }

    public function testJobsSplitOverSeveralLinesOfTheSameTypeShareItsLevel(): void
    {
        $levels = ChainManufacturingLevels::byTypeId(
            [
                $this->jobLine(self::PRODUCT, 'Product', 'final', null, runs: 10),
                $this->jobLine(self::PRODUCT, 'Product', 'final', null, runs: 5, meLevel: 8),
                $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT),
                $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT, runs: 3, meLevel: 8),
            ],
            [self::PRODUCT => [self::COMPONENT], self::COMPONENT => []],
        );

        self::assertSame([self::COMPONENT => 1, self::PRODUCT => 2], $this->sortedByTypeId($levels));
    }

    public function testCopyAndInventionLinesHaveNoLevel(): void
    {
        $levels = ChainManufacturingLevels::byTypeId(
            [
                $this->jobLine(self::PRODUCT, 'Product', 'final', null),
                $this->jobLine(self::COMPONENT, 'Component', 'blueprint', self::PRODUCT, activityType: 'copy'),
                $this->jobLine(self::REACTION, 'Reaction', 'blueprint', null, activityType: 'invention'),
            ],
            [self::PRODUCT => [], self::COMPONENT => [], self::REACTION => []],
        );

        self::assertSame([self::PRODUCT => 1], $levels);
    }

    public function testMaterialLinesAreLeftOut(): void
    {
        $levels = ChainManufacturingLevels::byTypeId(
            [
                $this->jobLine(self::PRODUCT, 'Product', 'final', null),
                $this->materialLine(34, 'Tritanium', 1_000, 0),
            ],
            [self::PRODUCT => [], 34 => []],
        );

        self::assertSame([self::PRODUCT => 1], $levels);
    }

    public function testComponentThatIsItsOwnIngredientIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ChainManufacturingLevels::byTypeId(
            [$this->jobLine(self::COMPONENT, 'Component', 'component', self::COMPONENT)],
            [self::COMPONENT => [self::COMPONENT]],
        );
    }

    #[Group('issue-1153')]
    public function testManufacturingCycleBetweenTwoJobTypesIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ChainManufacturingLevels::byTypeId(
            [
                $this->jobLine(self::COMPONENT, 'Component', 'component', null),
                $this->jobLine(self::REACTION, 'Reaction', 'component', null, activityType: 'reaction'),
            ],
            [self::COMPONENT => [self::REACTION], self::REACTION => [self::COMPONENT]],
        );
    }

    #[Group('issue-1153')]
    public function testTwoCompositesConsumingBuiltIntermediatesAreBothLevelTwoWhateverTheLineOrder(): void
    {
        $producedIngredients = [
            self::CRYSTALLINE_CARBONIDE => [self::CRYSTALLITE_ALLOY, self::CARBON_POLYMERS],
            self::PHOTONIC_METAMATERIALS => [self::CRYSTALLITE_ALLOY, self::CARBON_POLYMERS],
            self::CRYSTALLITE_ALLOY => [],
            self::CARBON_POLYMERS => [],
        ];
        $lines = [
            $this->jobLine(self::CRYSTALLINE_CARBONIDE, 'Crystalline Carbonide', 'component', null),
            $this->jobLine(self::PHOTONIC_METAMATERIALS, 'Photonic Metamaterials', 'component', null),
            $this->jobLine(self::CRYSTALLITE_ALLOY, 'Crystallite Alloy', 'component', self::PHOTONIC_METAMATERIALS),
            $this->jobLine(self::CARBON_POLYMERS, 'Carbon Polymers', 'component', self::PHOTONIC_METAMATERIALS),
        ];

        $expected = [
            self::CRYSTALLINE_CARBONIDE => 2,
            self::PHOTONIC_METAMATERIALS => 2,
            self::CRYSTALLITE_ALLOY => 1,
            self::CARBON_POLYMERS => 1,
        ];
        self::assertSame($this->sortedByTypeId($expected), $this->sortedByTypeId(ChainManufacturingLevels::byTypeId($lines, $producedIngredients)));
        self::assertSame($this->sortedByTypeId($expected), $this->sortedByTypeId(ChainManufacturingLevels::byTypeId(array_reverse($lines), $producedIngredients)));
    }

    #[Group('issue-1153')]
    public function testCompositeWhoseIntermediatesAreBoughtIsLevelTwo(): void
    {
        $levels = ChainManufacturingLevels::byTypeId(
            [
                $this->jobLine(self::CRYSTALLINE_CARBONIDE, 'Crystalline Carbonide', 'component', null),
                $this->materialLine(self::CRYSTALLITE_ALLOY, 'Crystallite Alloy', 300, 0),
                $this->materialLine(self::CARBON_POLYMERS, 'Carbon Polymers', 120, 0),
            ],
            [self::CRYSTALLINE_CARBONIDE => [self::CRYSTALLITE_ALLOY, self::CARBON_POLYMERS], self::CRYSTALLITE_ALLOY => [], self::CARBON_POLYMERS => []],
        );

        self::assertSame([self::CRYSTALLINE_CARBONIDE => 2], $levels);
    }

    #[Group('issue-1153')]
    public function testLevelFollowsTheRecipeThroughABoughtIntermediateWithoutJobLine(): void
    {
        $levels = ChainManufacturingLevels::byTypeId(
            [$this->jobLine(self::PRODUCT, 'Product', 'final', null)],
            [self::PRODUCT => [self::COMPONENT], self::COMPONENT => [self::REACTION], self::REACTION => []],
        );

        self::assertSame([self::PRODUCT => 3], $levels);
    }

    #[Group('issue-1153')]
    public function testJobTypeAbsentFromTheRecipeMapIsRejectedInsteadOfBeingLevelOne(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ChainManufacturingLevels::byTypeId(
            [$this->jobLine(self::PRODUCT, 'Product', 'final', null)],
            [self::COMPONENT => []],
        );
    }

    private function sortedByTypeId(array $levels): array
    {
        ksort($levels);

        return $levels;
    }
}
