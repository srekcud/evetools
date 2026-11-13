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
#[Group('issue-903')]
final class BlueprintLibraryReaderProductTest extends TestCase
{
    use BuildsBlueprintLibrary;
    use BuildsCorporationDirectorAccess;
    use ResolvesTypeNamesFromSde;

    private const int MANUFACTURING_ACTIVITY_ID = 1;
    private const int DRAKE_TYPE_ID = 24_698;
    private const int RIFTER_TYPE_ID = 587;
    private const int COMPOSITE_PRODUCT_TYPE_ID = 16_670;
    private const int UNNAMED_PRODUCT_TYPE_ID = 88_001;
    private const int FIRST_ITEM_ID = 9_101;
    private const int SECOND_ITEM_ID = 9_102;
    private const int THIRD_ITEM_ID = 9_103;
    private const int FOURTH_ITEM_ID = 9_104;

    private const array PRODUCT_NAMES = [
        self::DRAKE_TYPE_ID => 'Drake',
        self::RIFTER_TYPE_ID => 'Rifter',
        self::COMPOSITE_PRODUCT_TYPE_ID => 'Crystalline Carbonide',
    ];

    private Character $main;
    private User $user;

    protected function setUp(): void
    {
        $this->main = $this->pilot('Aria Solberg', self::MAIN_CORPORATION_ID, self::MAIN_CORPORATION_NAME, [self::PERSONAL_SCOPE, self::CORPORATION_SCOPE]);
        $this->user = $this->userOf(self::MAIN_USER_ID, $this->main);
    }

    public function testAnOriginalAndACopyExposeTheManufacturedProduct(): void
    {
        $reader = $this->readerOver([
            $this->personalBlueprint($this->main, self::FIRST_ITEM_ID, self::DRAKE_BLUEPRINT, -1, -1, 10, 20),
            $this->personalBlueprint($this->main, self::SECOND_ITEM_ID, self::RIFTER_BLUEPRINT, -2, 5, 4, 8),
        ]);

        self::assertSame(
            [
                [self::DRAKE_BLUEPRINT, self::DRAKE_TYPE_ID, 'Drake'],
                [self::RIFTER_BLUEPRINT, self::RIFTER_TYPE_ID, 'Rifter'],
            ],
            $this->productsOfRows($reader),
        );
    }

    public function testAReactionFormulaExposesTheReactionProductNotAManufacturingOne(): void
    {
        $reader = $this->readerOver([
            $this->personalBlueprint($this->main, self::THIRD_ITEM_ID, self::COMPOSITE_REACTION_FORMULA, -1, -1, 0, 0),
        ], [self::COMPOSITE_REACTION_FORMULA]);

        self::assertSame(
            [[self::COMPOSITE_REACTION_FORMULA, self::COMPOSITE_PRODUCT_TYPE_ID, 'Crystalline Carbonide']],
            $this->productsOfRows($reader),
        );
    }

    public function testABlueprintWithoutManufacturingProductExposesNoProduct(): void
    {
        $reader = $this->readerOver([
            $this->personalBlueprint($this->main, self::FOURTH_ITEM_ID, self::HURRICANE_BLUEPRINT, -1, -1, 0, 0),
        ]);

        self::assertSame([[self::HURRICANE_BLUEPRINT, null, null]], $this->productsOfRows($reader));
    }

    public function testAProductMissingFromTheSdeNamesIsMarkedUnknownRatherThanBlank(): void
    {
        $reader = $this->readerOver(
            [$this->personalBlueprint($this->main, self::FIRST_ITEM_ID, self::ATRON_BLUEPRINT, -1, -1, 0, 0)],
            [],
            [self::ATRON_BLUEPRINT . '-' . self::MANUFACTURING_ACTIVITY_ID => self::UNNAMED_PRODUCT_TYPE_ID],
        );

        self::assertSame(
            [[self::ATRON_BLUEPRINT, self::UNNAMED_PRODUCT_TYPE_ID, BlueprintLibraryReader::UNKNOWN_TYPE_NAME]],
            $this->productsOfRows($reader),
        );
    }

    /**
     * @param list<OwnedBlueprint> $blueprints
     * @param list<int> $reactionFormulaTypeIds
     * @param array<string, int>|null $products
     */
    private function readerOver(array $blueprints, array $reactionFormulaTypeIds = [], ?array $products = null): BlueprintLibraryReader
    {
        $this->corporationMember(self::MAIN_CORPORATION_ID, ['Director']);

        return new BlueprintLibraryReader(
            $this->repositoryHolding($blueprints),
            $this->visibilityOf([self::MAIN_CORPORATION_ID => [3]]),
            $this->corporationDirectorAccess(),
            new TypeNameResolver($this->sdeTypeRepositoryKnowing(self::BLUEPRINT_NAMES + self::PRODUCT_NAMES)),
            $this->jobsInProgressOn([]),
            $this->divisionsNamed(),
            $this->reactionFormulas($reactionFormulaTypeIds),
            $this->productsOf($products ?? [
                self::DRAKE_BLUEPRINT . '-' . self::MANUFACTURING_ACTIVITY_ID => self::DRAKE_TYPE_ID,
                self::RIFTER_BLUEPRINT . '-' . self::MANUFACTURING_ACTIVITY_ID => self::RIFTER_TYPE_ID,
                self::COMPOSITE_REACTION_FORMULA . '-' . self::REACTION_ACTIVITY_ID => self::COMPOSITE_PRODUCT_TYPE_ID,
                self::COMPOSITE_REACTION_FORMULA . '-' . self::MANUFACTURING_ACTIVITY_ID => self::DRAKE_TYPE_ID,
            ]),
        );
    }

    /** @return list<array{int, int|null, string|null}> */
    private function productsOfRows(BlueprintLibraryReader $reader): array
    {
        $rows = array_map(
            static fn (BlueprintLibraryRow $row): array => [$row->typeId, $row->productTypeId, $row->productName],
            $reader->readFor($this->user)->rows,
        );
        usort($rows, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return $rows;
    }
}
