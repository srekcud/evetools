<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Blueprint;

use App\Entity\Character;
use App\Entity\OwnedBlueprint;
use App\Entity\User;
use App\Service\Industry\Blueprint\BlueprintLibraryReader;
use App\Service\Industry\Blueprint\BlueprintLibraryRow;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Service\ESI\BuildsCorporationDirectorAccess;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlueprintLibraryReader::class)]
#[Group('issue-411')]
final class BlueprintLibraryReaderRunningJobTest extends TestCase
{
    use BuildsBlueprintLibrary;
    use BuildsCorporationDirectorAccess;
    use ResolvesTypeNamesFromSde;

    private const int DRAKE_ITEM_ID = 7_101;
    private const int SECOND_DRAKE_ITEM_ID = 7_102;
    private const int VEXOR_ITEM_ID = 7_103;
    private const int HAMMERHEAD_ITEM_ID = 7_104;

    private Character $main;
    private User $user;

    protected function setUp(): void
    {
        $this->main = $this->pilot('Aria Solberg', self::MAIN_CORPORATION_ID, self::MAIN_CORPORATION_NAME, [self::PERSONAL_SCOPE, self::CORPORATION_SCOPE]);
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->main);
    }

    public function testOnlyTheBlueprintItemHeldByAJobInProgressIsFlaggedNotTheOtherCopyOfTheSameType(): void
    {
        $reader = $this->readerWithJobsInProgressOn([self::DRAKE_ITEM_ID], [
            $this->personalBlueprint($this->main, self::DRAKE_ITEM_ID, self::DRAKE_BLUEPRINT, -2, 10, 8, 16),
            $this->personalBlueprint($this->main, self::SECOND_DRAKE_ITEM_ID, self::DRAKE_BLUEPRINT, -2, 10, 6, 12),
            $this->personalBlueprint($this->main, self::VEXOR_ITEM_ID, self::VEXOR_BLUEPRINT, -1, -1, 4, 8),
        ]);

        self::assertSame(
            [
                [self::DRAKE_BLUEPRINT, 8, true],
                [self::DRAKE_BLUEPRINT, 6, false],
                [self::VEXOR_BLUEPRINT, 4, false],
            ],
            $this->flagsOf($reader),
        );
    }

    public function testACorporationBlueprintInAVisibleDivisionHeldByAJobInProgressIsFlagged(): void
    {
        $reader = $this->readerWithJobsInProgressOn([self::HAMMERHEAD_ITEM_ID], [
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, self::HAMMERHEAD_ITEM_ID, self::HAMMERHEAD_II_BLUEPRINT, -2, 50, 2, 4, 3),
        ]);

        self::assertSame([[self::HAMMERHEAD_II_BLUEPRINT, 2, true]], $this->flagsOf($reader));
    }

    public function testNoBlueprintIsFlaggedWhenNoJobIsInProgress(): void
    {
        $reader = $this->readerWithJobsInProgressOn([], [
            $this->personalBlueprint($this->main, self::DRAKE_ITEM_ID, self::DRAKE_BLUEPRINT, -1, -1, 10, 20),
        ]);

        self::assertSame([[self::DRAKE_BLUEPRINT, 10, false]], $this->flagsOf($reader));
    }

    /**
     * @param list<int> $itemIdsWithJobInProgress
     * @param list<OwnedBlueprint> $blueprints
     */
    private function readerWithJobsInProgressOn(array $itemIdsWithJobInProgress, array $blueprints): BlueprintLibraryReader
    {
        $this->corporationMember(self::MAIN_CORPORATION_ID, ['Director']);

        return new BlueprintLibraryReader(
            $this->repositoryHolding($blueprints),
            $this->visibilityOf([self::MAIN_CORPORATION_ID => [3]]),
            $this->corporationDirectorAccess(),
            new TypeNameResolver($this->sdeTypeRepositoryKnowing(self::BLUEPRINT_NAMES)),
            $this->jobsInProgressOn($itemIdsWithJobInProgress),
            $this->divisionsNamed(),
            $this->reactionFormulas(),
            $this->productsOf(),
        );
    }

    /** @return list<array{int, int, bool}> */
    private function flagsOf(BlueprintLibraryReader $reader): array
    {
        return array_map(
            static fn (BlueprintLibraryRow $row): array => [$row->typeId, $row->me, $row->usedByRunningJob],
            $reader->readFor($this->user)->rows,
        );
    }
}
