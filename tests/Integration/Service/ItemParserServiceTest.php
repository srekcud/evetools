<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Service\ItemParserService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;

/**
 * Issue #33: resolving the type names of a pasted item list (appraisal, shopping list)
 * ran one or two queries per line on `sde_inv_types`. Resolving N names must cost a
 * bounded number of queries (one exact-name batch, one case-insensitive batch for the
 * misses), whatever N, with exactly the same result as before.
 *
 * The SDE is empty in the test database: the types are inserted by the test.
 */
final class ItemParserServiceTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    /** one exact-name batch + one case-insensitive batch for the misses, whatever the number of lines */
    private const int MAX_TYPE_QUERIES = 2;

    private const string INV_TYPES_TABLE_PATTERN = '/\bsde_inv_types\b/';

    /**
     * 50 pasted lines: 25 exact names, 15 names in another case, 2 unpublished types, 8 unknown names.
     * Each entry: [pasted line, expected typeId (null = not found), expected typeName or reported name, expected quantity].
     *
     * @var list<array{string, ?int, string, int}>
     */
    private const array PASTED_LINES = [
        ['10000x Tritanium', 34, 'Tritanium', 10000],
        ['ferox x2', 16227, 'Ferox', 2],
        ['Unknown Widget	3', null, 'Unknown Widget', 3],
        ['5000x Pyerite', 35, 'Pyerite', 5000],
        ['HURRICANE x1', 24702, 'Hurricane', 1],
        ['2500x Mexallon', 36, 'Mexallon', 2500],
        ['Unpublished Test Item	4', null, 'Unpublished Test Item', 4],
        ['1200x Isogen', 37, 'Isogen', 1200],
        ['purifier	3', 12038, 'Purifier', 3],
        ['300x Nocxium', 38, 'Nocxium', 300],
        ['7x Tritanium Ore', null, 'Tritanium Ore', 7],
        ['150x Zydrine', 39, 'Zydrine', 150],
        ['RETRIEVER x4', 17478, 'Retriever', 4],
        ['75x Megacyte', 40, 'Megacyte', 75],
        ['20x Morphite', 11399, 'Morphite', 20],
        ['hulk x1', 22544, 'Hulk', 1],
        ['1x Sabre Blueprint', null, 'Sabre Blueprint', 1],
        ['Heavy Water	8000', 16272, 'Heavy Water', 8000],
        ['damage control ii x6', 2048, 'Damage Control II', 6],
        ['Liquid Ozone	9000', 16273, 'Liquid Ozone', 9000],
        ['retired prototype hull x1', null, 'retired prototype hull', 1],
        ['Helium Isotopes	40000', 16274, 'Helium Isotopes', 40000],
        ['LARGE SHIELD EXTENDER II x12', 3841, 'Large Shield Extender II', 12],
        ['Strontium Clathrates	2000', 16275, 'Strontium Clathrates', 2000],
        ['2x Plex Mug', null, 'Plex Mug', 2],
        ['Oxygen Isotopes	30000', 17887, 'Oxygen Isotopes', 30000],
        ['nanite repair paste x500', 28668, 'Nanite Repair Paste', 500],
        ['Nitrogen Isotopes	25000', 17888, 'Nitrogen Isotopes', 25000],
        ['Hydrogen Isotopes	20000', 17889, 'Hydrogen Isotopes', 20000],
        ['construction blocks x900', 3828, 'Construction Blocks', 900],
        ['400x Enriched Uranium', 44, 'Enriched Uranium', 400],
        ['100x Veldspar Pebble', null, 'Veldspar Pebble', 100],
        ['1000x Oxygen', 3683, 'Oxygen', 1000],
        ['CONSUMER ELECTRONICS x80', 9836, 'Consumer Electronics', 80],
        ['600x Mechanical Parts', 3689, 'Mechanical Parts', 600],
        ['nitrogen fuel block x40000', 4051, 'Nitrogen Fuel Block', 40000],
        ['250x Coolant', 9832, 'Coolant', 250],
        ['9x Not An Item', null, 'Not An Item', 9],
        ['60x Robotics', 9848, 'Robotics', 60],
        ['HYDROGEN FUEL BLOCK x35000', 4246, 'Hydrogen Fuel Block', 35000],
        ['3x Sabre', 22456, 'Sabre', 3],
        ['helium fuel block x30000', 4247, 'Helium Fuel Block', 30000],
        ['8x Jackdaw', 34828, 'Jackdaw', 8],
        ['1x Mystery Box', null, 'Mystery Box', 1],
        ['2x Ishtar', 12005, 'Ishtar', 2],
        ['Oxygen fuel block x25000', 4312, 'Oxygen Fuel Block', 25000],
        ['5x Malediction', 11186, 'Malediction', 5],
        ['capital construction parts x10', 21037, 'Capital Construction Parts', 10],
        ['4x Harpy', 11381, 'Harpy', 4],
        ['11x Unknown   Gadget', null, 'Unknown Gadget', 11],
    ];

    /** Published types as stored in the SDE: typeId => typeName. */
    private const array PUBLISHED_TYPES = [
        34 => 'Tritanium', 35 => 'Pyerite', 36 => 'Mexallon', 37 => 'Isogen', 38 => 'Nocxium',
        39 => 'Zydrine', 40 => 'Megacyte', 11399 => 'Morphite', 16272 => 'Heavy Water',
        16273 => 'Liquid Ozone', 16274 => 'Helium Isotopes', 16275 => 'Strontium Clathrates',
        17887 => 'Oxygen Isotopes', 17888 => 'Nitrogen Isotopes', 17889 => 'Hydrogen Isotopes',
        44 => 'Enriched Uranium', 3683 => 'Oxygen', 3689 => 'Mechanical Parts', 9832 => 'Coolant',
        9848 => 'Robotics', 22456 => 'Sabre', 34828 => 'Jackdaw', 12005 => 'Ishtar',
        11186 => 'Malediction', 11381 => 'Harpy',
        16227 => 'Ferox', 24702 => 'Hurricane', 12038 => 'Purifier', 17478 => 'Retriever',
        22544 => 'Hulk', 2048 => 'Damage Control II', 3841 => 'Large Shield Extender II',
        28668 => 'Nanite Repair Paste', 3828 => 'Construction Blocks', 9836 => 'Consumer Electronics',
        4051 => 'Nitrogen Fuel Block', 4246 => 'Hydrogen Fuel Block', 4247 => 'Helium Fuel Block',
        4312 => 'Oxygen Fuel Block', 21037 => 'Capital Construction Parts',
    ];

    /** Unpublished types: a name match must still be reported as not found. */
    private const array UNPUBLISHED_TYPES = [
        99001 => 'Unpublished Test Item',
        99002 => 'Retired Prototype Hull',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $category = (new InvCategory())->setCategoryId(4)->setCategoryName('Material')->setPublished(true);
        $group = (new InvGroup())->setGroupId(18)->setGroupName('Mineral')->setCategory($category)->setPublished(true);
        $this->em->persist($category);
        $this->em->persist($group);

        foreach (self::PUBLISHED_TYPES as $typeId => $typeName) {
            $this->createType($typeId, $typeName, published: true);
        }
        foreach (self::UNPUBLISHED_TYPES as $typeId => $typeName) {
            $this->createType($typeId, $typeName, published: false);
        }
        $this->flushAndClear();
    }

    public function testResolvingFiftyPastedLinesQueriesTypesABoundedNumberOfTimes(): void
    {
        $parser = $this->itemParser();
        $parsedItems = $parser->parseItemList($this->pastedText());

        $typeQueries = $this->queriesMatching(
            $this->sqlExecutedDuring(fn () => $parser->resolveItemNames($parsedItems)),
            self::INV_TYPES_TABLE_PATTERN,
        );

        self::assertLessThanOrEqual(
            self::MAX_TYPE_QUERIES,
            \count($typeQueries),
            sprintf(
                "50 pasted lines: expected at most %d queries on sde_inv_types, got %d:\n%s",
                self::MAX_TYPE_QUERIES,
                \count($typeQueries),
                implode("\n", $typeQueries),
            ),
        );
    }

    public function testResolvingFiftyPastedLinesFindsExactAndOtherCaseNamesAndReportsTheOthers(): void
    {
        $parser = $this->itemParser();

        $resolved = $parser->resolveItemNames($parser->parseItemList($this->pastedText()));

        $expectedFound = [];
        $expectedNotFound = [];
        foreach (self::PASTED_LINES as [, $typeId, $name, $quantity]) {
            if ($typeId === null) {
                $expectedNotFound[] = $name;
            } else {
                $expectedFound[] = ['typeId' => $typeId, 'typeName' => $name, 'quantity' => $quantity];
            }
        }

        self::assertCount(40, $resolved['found']);
        self::assertSame($expectedFound, $resolved['found']);
        self::assertSame([
            'Unknown Widget',
            'Unpublished Test Item',
            'Tritanium Ore',
            'Sabre Blueprint',
            'retired prototype hull',
            'Plex Mug',
            'Veldspar Pebble',
            'Not An Item',
            'Mystery Box',
            'Unknown Gadget',
        ], $resolved['notFound']);
        self::assertSame($expectedNotFound, $resolved['notFound']);
    }

    public function testAnExactNameIsPreferredOverANameDifferingOnlyByCase(): void
    {
        $this->createType(99010, 'Plex', published: true);
        $this->createType(99011, 'PLEX', published: true);
        $this->flushAndClear();

        $resolved = $this->itemParser()->resolveItemNames([
            ['name' => 'PLEX', 'quantity' => 500],
            ['name' => 'Plex', 'quantity' => 20],
        ]);

        self::assertSame([
            ['typeId' => 99011, 'typeName' => 'PLEX', 'quantity' => 500],
            ['typeId' => 99010, 'typeName' => 'Plex', 'quantity' => 20],
        ], $resolved['found']);
        self::assertSame([], $resolved['notFound']);
    }

    private function pastedText(): string
    {
        return implode("\n", array_column(self::PASTED_LINES, 0));
    }

    private function itemParser(): ItemParserService
    {
        return self::getContainer()->get(ItemParserService::class);
    }

    private function createType(int $typeId, string $typeName, bool $published): void
    {
        $type = (new InvType())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setGroup($this->em->getReference(InvGroup::class, 18))
            ->setPublished($published);
        $this->em->persist($type);
    }
}
