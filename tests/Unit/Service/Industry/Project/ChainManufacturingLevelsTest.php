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

    public function testReactionIsLevelOneAndEachConsumerIsOneAboveItsHighestChild(): void
    {
        $levels = ChainManufacturingLevels::byTypeId([
            $this->jobLine(self::PRODUCT, 'Product', 'final', null),
            $this->jobLine(self::CAPITAL_COMPONENT, 'Capital component', 'component', self::PRODUCT),
            $this->jobLine(self::COMPONENT, 'Component', 'component', self::CAPITAL_COMPONENT),
            $this->jobLine(self::REACTION, 'Reaction', 'component', self::COMPONENT, activityType: 'reaction'),
        ]);

        self::assertSame(
            [self::REACTION => 1, self::COMPONENT => 2, self::CAPITAL_COMPONENT => 3, self::PRODUCT => 4],
            $this->sortedByTypeId($levels),
        );
    }

    public function testProductTakesTheHighestLevelAmongItsDirectChildren(): void
    {
        $levels = ChainManufacturingLevels::byTypeId([
            $this->jobLine(self::PRODUCT, 'Product', 'final', null),
            $this->jobLine(self::SECOND_REACTION, 'Direct reaction', 'component', self::PRODUCT, activityType: 'reaction'),
            $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT),
            $this->jobLine(self::REACTION, 'Reaction', 'component', self::COMPONENT, activityType: 'reaction'),
        ]);

        self::assertSame(3, $levels[self::PRODUCT]);
        self::assertSame(1, $levels[self::SECOND_REACTION]);
    }

    public function testJobsSplitOverSeveralLinesOfTheSameTypeShareItsLevel(): void
    {
        $levels = ChainManufacturingLevels::byTypeId([
            $this->jobLine(self::PRODUCT, 'Product', 'final', null, runs: 10),
            $this->jobLine(self::PRODUCT, 'Product', 'final', null, runs: 5, meLevel: 8),
            $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT),
            $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT, runs: 3, meLevel: 8),
        ]);

        self::assertSame([self::COMPONENT => 1, self::PRODUCT => 2], $this->sortedByTypeId($levels));
    }

    public function testCopyAndInventionLinesHaveNoLevel(): void
    {
        $levels = ChainManufacturingLevels::byTypeId([
            $this->jobLine(self::PRODUCT, 'Product', 'final', null),
            $this->jobLine(self::COMPONENT, 'Component', 'blueprint', self::PRODUCT, activityType: 'copy'),
            $this->jobLine(self::REACTION, 'Reaction', 'blueprint', null, activityType: 'invention'),
        ]);

        self::assertSame([self::PRODUCT => 1], $levels);
    }

    public function testMaterialLinesAreLeftOut(): void
    {
        $levels = ChainManufacturingLevels::byTypeId([
            $this->jobLine(self::PRODUCT, 'Product', 'final', null),
            $this->materialLine(34, 'Tritanium', 1_000, 0),
        ]);

        self::assertSame([self::PRODUCT => 1], $levels);
    }

    public function testComponentThatIsItsOwnConsumerIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ChainManufacturingLevels::byTypeId([
            $this->jobLine(self::COMPONENT, 'Component', 'component', self::COMPONENT),
        ]);
    }

    private function sortedByTypeId(array $levels): array
    {
        ksort($levels);

        return $levels;
    }
}
