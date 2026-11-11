<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Blueprint;

use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\OwnedBlueprint;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Service\Industry\Blueprint\BlueprintLibrary;
use App\Service\Industry\Blueprint\BlueprintLibraryReader;
use App\Service\Industry\Blueprint\BlueprintLibraryRow;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlueprintLibraryReader::class)]
#[Group('issue-357')]
#[Group('issue-358')]
#[Group('corp-blueprints-hidden-divisions')]
final class BlueprintLibraryReaderTest extends TestCase
{
    use BuildsBlueprintLibrary;
    use ResolvesTypeNamesFromSde;

    private const int DRAKE_LOCATION_ID = 60_003_760;
    private const int ALT_LOCATION_ID = 60_008_494;
    private const int CORPORATION_HANGAR_ID = 1_040_000_000_001;

    private Character $main;
    private Character $alt;
    private User $user;

    protected function setUp(): void
    {
        $this->main = $this->pilot('Aria Solberg', self::MAIN_CORPORATION_ID, self::MAIN_CORPORATION_NAME, [self::PERSONAL_SCOPE, self::CORPORATION_SCOPE]);
        $this->alt = $this->pilot('Brann Holt', self::ALT_CORPORATION_ID, 'Holt Haulage', [self::PERSONAL_SCOPE]);
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->main, $this->alt);
    }

    public function testReadsThePersonalOriginalOfTheMainTheCopyOfAnAltAndTheCopyOfTheCorporationInAVisibleDivision(): void
    {
        $reader = $this->reader([
            $this->personalBlueprint($this->main, 7_001, self::DRAKE_BLUEPRINT, -1, -1, 10, 20, self::DRAKE_LOCATION_ID),
            $this->personalBlueprint($this->alt, 7_002, self::VEXOR_BLUEPRINT, -2, 25, 6, 12, self::ALT_LOCATION_ID),
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_003, self::HAMMERHEAD_II_BLUEPRINT, -2, 50, 2, 4, 3, self::CORPORATION_HANGAR_ID),
        ], visibleDivisions: [3], corporationReader: $this->main);

        $library = $reader->readFor($this->user);

        self::assertSame(
            [
                [self::DRAKE_BLUEPRINT, 'Drake Blueprint', 'bpo', 10, 20, null, 'personal', 'Aria Solberg', self::DRAKE_LOCATION_ID, null, 1],
                [self::HAMMERHEAD_II_BLUEPRINT, 'Hammerhead II Blueprint', 'bpc', 2, 4, 50, 'corporation', self::MAIN_CORPORATION_NAME, self::CORPORATION_HANGAR_ID, 3, 1],
                [self::VEXOR_BLUEPRINT, 'Vexor Blueprint', 'bpc', 6, 12, 25, 'personal', 'Brann Holt', self::ALT_LOCATION_ID, null, 1],
            ],
            $this->rowsOf($library),
        );
    }

    public function testCorporationBlueprintsOutsideTheVisibleDivisionsOrWithoutDivisionAreAbsent(): void
    {
        $reader = $this->reader([
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_011, self::HAMMERHEAD_II_BLUEPRINT, -2, 50, 2, 4, 3),
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_012, self::HURRICANE_BLUEPRINT, -2, 30, 4, 8, 5),
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_013, self::ATRON_BLUEPRINT, -1, -1, 9, 18, null),
        ], visibleDivisions: [3], corporationReader: $this->main);

        $library = $reader->readFor($this->user);

        self::assertSame([self::HAMMERHEAD_II_BLUEPRINT], array_map(
            static fn (BlueprintLibraryRow $row): int => $row->typeId,
            $library->rows,
        ));
    }

    public function testCorporationStateIsNoDirectorWithoutAnyCharacterWithTheScopeNoVisibilityWhenNoDivisionIsVisibleAndBlueprintsOfHiddenDivisionsAreNotSharedAndKnownOtherwise(): void
    {
        $corporationBlueprint = $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_021, self::HAMMERHEAD_II_BLUEPRINT, -2, 50, 2, 4, 3);
        $personalBlueprint = $this->personalBlueprint($this->main, 7_022, self::DRAKE_BLUEPRINT, -1, -1, 10, 20);
        $blueprints = [$corporationBlueprint, $personalBlueprint];

        $known = $this->reader($blueprints, visibleDivisions: [3], corporationReader: $this->main)->readFor($this->user);
        $noDirector = $this->reader($blueprints, visibleDivisions: [3], corporationReader: null)->readFor($this->user);
        $noVisibilitySetting = $this->reader($blueprints, visibleDivisions: null, corporationReader: $this->main)->readFor($this->user);
        $noVisibleDivision = $this->reader($blueprints, visibleDivisions: [], corporationReader: $this->main)->readFor($this->user);

        self::assertSame(
            [
                ['known', [self::DRAKE_BLUEPRINT, self::HAMMERHEAD_II_BLUEPRINT]],
                ['no_director', [self::DRAKE_BLUEPRINT]],
                ['known', [self::DRAKE_BLUEPRINT, self::HAMMERHEAD_II_BLUEPRINT]],
                ['no_visibility', [self::DRAKE_BLUEPRINT]],
            ],
            array_map(
                fn (BlueprintLibrary $library): array => [
                    $library->corporationState,
                    array_map(static fn (BlueprintLibraryRow $row): int => $row->typeId, $library->rows),
                ],
                [$known, $noDirector, $noVisibilitySetting, $noVisibleDivision],
            ),
        );
    }

    public function testRowsAreSortedByNameThenOriginalBeforeCopyThenMaterialEfficiencyDescendingAndAStackOfNewOriginalsCountsItsQuantity(): void
    {
        $reader = $this->reader([
            $this->personalBlueprint($this->main, 7_031, self::RIFTER_BLUEPRINT, -2, 12, 8, 16),
            $this->personalBlueprint($this->main, 7_032, self::RIFTER_BLUEPRINT, -1, -1, 5, 10),
            $this->personalBlueprint($this->main, 7_033, self::ATRON_BLUEPRINT, -2, 7, 3, 6),
            $this->personalBlueprint($this->main, 7_034, self::RIFTER_BLUEPRINT, -1, -1, 9, 18),
            $this->personalBlueprint($this->main, 7_035, self::ATRON_BLUEPRINT, 3, -1, 0, 0),
        ], visibleDivisions: null, corporationReader: null);

        $library = $reader->readFor($this->user);

        self::assertSame(
            [
                ['Atron Blueprint', 'bpo', 0, 3],
                ['Atron Blueprint', 'bpc', 3, 1],
                ['Rifter Blueprint', 'bpo', 9, 1],
                ['Rifter Blueprint', 'bpo', 5, 1],
                ['Rifter Blueprint', 'bpc', 8, 1],
            ],
            array_map(
                static fn (BlueprintLibraryRow $row): array => [$row->name, $row->kind, $row->me, $row->count],
                $library->rows,
            ),
        );
    }

    public function testNothingOfAnotherUserNorOfAnotherCorporationIsRead(): void
    {
        $otherPilot = $this->pilot('Corin Vale', self::MAIN_CORPORATION_ID, self::MAIN_CORPORATION_NAME, [self::PERSONAL_SCOPE]);
        $this->userOf(self::OTHER_USER_ID, $otherPilot);
        $reader = $this->reader([
            $this->personalBlueprint($this->main, 7_041, self::DRAKE_BLUEPRINT, -1, -1, 10, 20),
            $this->personalBlueprint($otherPilot, 7_042, self::VEXOR_BLUEPRINT, -1, -1, 4, 8),
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_043, self::HAMMERHEAD_II_BLUEPRINT, -2, 50, 2, 4, 3),
            $this->corporationBlueprint(self::OTHER_CORPORATION_ID, 7_044, self::HURRICANE_BLUEPRINT, -2, 30, 6, 12, 3),
        ], visibleDivisions: [3], corporationReader: $this->main, visibleDivisionsOfOtherCorporation: [3]);

        $library = $reader->readFor($this->user);

        self::assertSame(
            [self::DRAKE_BLUEPRINT, self::HAMMERHEAD_II_BLUEPRINT],
            array_map(static fn (BlueprintLibraryRow $row): int => $row->typeId, $library->rows),
        );
    }

    public function testBlueprintTypeMissingFromTheSdeIsNamedUnknownType(): void
    {
        $reader = $this->reader([
            $this->personalBlueprint($this->main, 7_051, self::UNKNOWN_BLUEPRINT, -1, -1, 1, 2),
        ], visibleDivisions: null, corporationReader: null);

        $library = $reader->readFor($this->user);

        self::assertSame([[self::UNKNOWN_BLUEPRINT, 'unknown_type']], array_map(
            static fn (BlueprintLibraryRow $row): array => [$row->typeId, $row->name],
            $library->rows,
        ));
    }

    public function testCorporationBlueprintOfADivisionRestrictedToAListIsReadOnlyByTheListedUser(): void
    {
        $otherPilot = $this->pilot('Corin Vale', self::MAIN_CORPORATION_ID, self::MAIN_CORPORATION_NAME, [self::PERSONAL_SCOPE]);
        $otherUser = $this->userOf(self::OTHER_USER_ID, $otherPilot);
        $blueprints = [
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_061, self::HURRICANE_BLUEPRINT, -2, 30, 4, 8, 4),
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_062, self::HAMMERHEAD_II_BLUEPRINT, -2, 50, 2, 4, 3),
        ];
        $reader = $this->readerWithRestrictedDivision($blueprints, visibleDivisions: [3], restrictedDivision: 4, listedUserIds: [self::MAIN_USER_ID]);

        $listedLibrary = $reader->readFor($this->user);
        $otherLibrary = $reader->readFor($otherUser);

        self::assertSame(
            [self::HAMMERHEAD_II_BLUEPRINT, self::HURRICANE_BLUEPRINT],
            array_map(static fn (BlueprintLibraryRow $row): int => $row->typeId, $listedLibrary->rows),
        );
        self::assertSame(
            [self::HAMMERHEAD_II_BLUEPRINT],
            array_map(static fn (BlueprintLibraryRow $row): int => $row->typeId, $otherLibrary->rows),
        );
    }

    public function testCorporationBlueprintsOfEveryDivisionAreReadWhenBlueprintsOfHiddenDivisionsAreShared(): void
    {
        $reader = $this->reader([
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_071, self::HAMMERHEAD_II_BLUEPRINT, -2, 50, 2, 4, 3),
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_072, self::HURRICANE_BLUEPRINT, -2, 30, 4, 8, 5),
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_073, self::ATRON_BLUEPRINT, -1, -1, 9, 18, null),
        ], visibleDivisions: [], corporationReader: $this->main, sharesBlueprintsOfHiddenDivisions: true);

        $library = $reader->readFor($this->user);

        self::assertSame(
            ['known', [self::ATRON_BLUEPRINT, self::HAMMERHEAD_II_BLUEPRINT, self::HURRICANE_BLUEPRINT]],
            [$library->corporationState, array_map(static fn (BlueprintLibraryRow $row): int => $row->typeId, $library->rows)],
        );
    }

    public function testCorporationBlueprintsOfEveryDivisionAreReadWithoutVisibilitySetting(): void
    {
        $reader = $this->reader([
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_081, self::HAMMERHEAD_II_BLUEPRINT, -2, 50, 2, 4, 3),
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_082, self::HURRICANE_BLUEPRINT, -2, 30, 4, 8, 5),
        ], visibleDivisions: null, corporationReader: $this->main);

        $library = $reader->readFor($this->user);

        self::assertSame(
            [self::HAMMERHEAD_II_BLUEPRINT, self::HURRICANE_BLUEPRINT],
            array_map(static fn (BlueprintLibraryRow $row): int => $row->typeId, $library->rows),
        );
    }

    public function testReactionFormulasOfAPilotAndOfTheCorporationAreReadAsReactionBlueprintsWithoutRuns(): void
    {
        $reader = $this->reader([
            $this->personalBlueprint($this->main, 7_101, self::COMPOSITE_REACTION_FORMULA, -1, -1, 0, 0),
            $this->corporationBlueprint(self::MAIN_CORPORATION_ID, 7_102, self::POLYMER_REACTION_FORMULA, -1, -1, 0, 0, 3),
            $this->personalBlueprint($this->main, 7_103, self::DRAKE_BLUEPRINT, -1, -1, 10, 20),
        ], visibleDivisions: null, corporationReader: $this->main, reactionFormulaTypeIds: [self::COMPOSITE_REACTION_FORMULA, self::POLYMER_REACTION_FORMULA]);

        $library = $reader->readFor($this->user);

        self::assertSame(
            [
                [self::COMPOSITE_REACTION_FORMULA, 'bpr', null, 'personal', 1],
                [self::DRAKE_BLUEPRINT, 'bpo', null, 'personal', 1],
                [self::POLYMER_REACTION_FORMULA, 'bpr', null, 'corporation', 1],
            ],
            array_map(static fn (BlueprintLibraryRow $row): array => [$row->typeId, $row->kind, $row->runs, $row->scope, $row->count], $library->rows),
        );
    }

    public function testBlueprintsOfTheSameNameAreSortedOriginalThenReactionThenCopy(): void
    {
        $reader = $this->reader([
            $this->personalBlueprint($this->main, 7_111, self::VEXOR_BLUEPRINT, -2, 25, 6, 12),
            $this->personalBlueprint($this->main, 7_112, self::VEXOR_NAMESAKE_REACTION_FORMULA, -1, -1, 0, 0),
            $this->personalBlueprint($this->main, 7_113, self::VEXOR_BLUEPRINT, -1, -1, 10, 20),
        ], visibleDivisions: null, corporationReader: $this->main, reactionFormulaTypeIds: [self::VEXOR_NAMESAKE_REACTION_FORMULA]);

        $library = $reader->readFor($this->user);

        self::assertSame(
            ['bpo', 'bpr', 'bpc'],
            array_map(static fn (BlueprintLibraryRow $row): string => $row->kind, $library->rows),
        );
    }

    public function testAnOriginalAndACopyWithoutReactionActivityKeepTheirKind(): void
    {
        $reader = $this->reader([
            $this->personalBlueprint($this->main, 7_121, self::DRAKE_BLUEPRINT, -1, -1, 10, 20),
            $this->personalBlueprint($this->main, 7_122, self::VEXOR_BLUEPRINT, -2, 25, 6, 12),
        ], visibleDivisions: null, corporationReader: $this->main, reactionFormulaTypeIds: [self::COMPOSITE_REACTION_FORMULA]);

        $library = $reader->readFor($this->user);

        self::assertSame(
            [[self::DRAKE_BLUEPRINT, 'bpo'], [self::VEXOR_BLUEPRINT, 'bpc']],
            array_map(static fn (BlueprintLibraryRow $row): array => [$row->typeId, $row->kind], $library->rows),
        );
    }

    private function reader(
        array $blueprints,
        ?array $visibleDivisions,
        ?Character $corporationReader,
        ?array $visibleDivisionsOfOtherCorporation = null,
        bool $sharesBlueprintsOfHiddenDivisions = false,
        array $reactionFormulaTypeIds = [],
    ): BlueprintLibraryReader {
        $visibleDivisionsByCorporationId = [];
        if ($visibleDivisions !== null) {
            $visibleDivisionsByCorporationId[self::MAIN_CORPORATION_ID] = $visibleDivisions;
        }
        if ($visibleDivisionsOfOtherCorporation !== null) {
            $visibleDivisionsByCorporationId[self::OTHER_CORPORATION_ID] = $visibleDivisionsOfOtherCorporation;
        }

        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findWithCorpBlueprintsAccess')->willReturnCallback(
            static fn (int $corporationId): ?Character => $corporationId === self::MAIN_CORPORATION_ID ? $corporationReader : null,
        );

        return new BlueprintLibraryReader(
            $this->repositoryHolding($blueprints),
            $this->visibilityOf($visibleDivisionsByCorporationId, $sharesBlueprintsOfHiddenDivisions),
            $characterRepository,
            new TypeNameResolver($this->sdeTypeRepositoryKnowing(self::BLUEPRINT_NAMES)),
            $this->jobsInProgressOn([]),
            $this->reactionFormulas($reactionFormulaTypeIds),
        );
    }

    private function readerWithRestrictedDivision(array $blueprints, array $visibleDivisions, int $restrictedDivision, array $listedUserIds): BlueprintLibraryReader
    {
        $visibility = (new CorpAssetVisibility())
            ->setCorporationId(self::MAIN_CORPORATION_ID)
            ->setVisibleDivisions($visibleDivisions)
            ->setRestrictedDivisions([$restrictedDivision => $listedUserIds])
            ->setShareBlueprintsOfHiddenDivisions(false);
        $visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $visibilityRepository->method('findByCorporationId')->willReturn($visibility);
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findWithCorpBlueprintsAccess')->willReturn($this->main);

        return new BlueprintLibraryReader(
            $this->repositoryHolding($blueprints),
            $visibilityRepository,
            $characterRepository,
            new TypeNameResolver($this->sdeTypeRepositoryKnowing(self::BLUEPRINT_NAMES)),
            $this->jobsInProgressOn([]),
            $this->reactionFormulas(),
        );
    }

    private function rowsOf(BlueprintLibrary $library): array
    {
        return array_map(
            static fn (BlueprintLibraryRow $row): array => [
                $row->typeId,
                $row->name,
                $row->kind,
                $row->me,
                $row->te,
                $row->runs,
                $row->scope,
                $row->ownerName,
                $row->locationId,
                $row->divisionNumber,
                $row->count,
            ],
            $library->rows,
        );
    }
}
