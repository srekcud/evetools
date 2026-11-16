<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Processor\Industry;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\StructureListImportResource;
use App\ApiResource\Industry\StructureListExportResource;
use App\ApiResource\Input\Industry\ImportStructureListInput;
use App\Entity\CachedStructure;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use App\State\Processor\Industry\ImportStructureListProcessor;
use App\State\Provider\Industry\StructureListExportProvider;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ImportStructureListProcessorTest extends IntegrationTestCase
{
    private const int EXPORTER_CORPORATION_ID = 98_000_001;
    private const int IMPORTER_CORPORATION_ID = 98_000_777;
    private const int RAITARU_LOCATION_ID = 1_035_466_617_946;
    private const int TATARA_LOCATION_ID = 1_035_466_617_950;
    private const int UNCACHED_LOCATION_ID = 1_035_466_617_948;
    private const int CACHED_SYSTEM_ID = 30_000_142;
    private const int SUBMITTED_SYSTEM_ID = 30_002_187;
    private const string ME_RIG = 'Standup M-Set Basic Small Ship Manufacturing Material Efficiency I';
    private const string ME_RIG_TIER_TWO = 'Standup M-Set Basic Small Ship Manufacturing Material Efficiency II';
    private const string LARGE_RIG = 'Standup L-Set Basic Small Ship Manufacturing Efficiency I';

    private User $exporter;
    private User $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = $this->pilotOfCorporation('Exporter', self::EXPORTER_CORPORATION_ID);
        $this->importer = $this->pilotOfCorporation('Importer', self::IMPORTER_CORPORATION_ID);
        $this->em->persist(
            (new CachedStructure())
                ->setStructureId(self::RAITARU_LOCATION_ID)
                ->setName('Cached Raitaru')
                ->setSolarSystemId(self::CACHED_SYSTEM_ID)
                ->setOwnerCorporationId(self::EXPORTER_CORPORATION_ID)
                ->setTypeId(35825),
        );
        $this->em->flush();
    }

    public function testExportThenImportOnAnotherAccountReproducesStructuresRigsAndTaxes(): void
    {
        $this->store($this->exporter, 'Home Raitaru', self::RAITARU_LOCATION_ID, 'raitaru', [self::ME_RIG], 3.5);
        $this->store($this->exporter, 'Reaction Tatara', self::TATARA_LOCATION_ID, 'tatara', [], 0.0);
        $this->store($this->exporter, 'Custom Azbel', null, 'azbel', [self::LARGE_RIG], null);

        $exported = $this->export($this->exporter);
        $result = $this->import($this->importer, $exported->structures);

        self::assertSame(['create', 'create', 'create'], $this->statuses($result));
        self::assertTrue($result->applied);
        $stored = $this->storedByName($this->importer);
        self::assertSame([self::ME_RIG], $stored['Home Raitaru']->getRigs());
        self::assertSame(3.5, $stored['Home Raitaru']->getFacilityTaxPercent());
        self::assertSame('raitaru', $stored['Home Raitaru']->getStructureType());
        self::assertSame(0.0, $stored['Reaction Tatara']->getFacilityTaxPercent());
        self::assertSame([self::LARGE_RIG], $stored['Custom Azbel']->getRigs());
        self::assertNull($stored['Custom Azbel']->getFacilityTaxPercent());
        self::assertNull($stored['Custom Azbel']->getLocationId());
    }

    public function testSecondImportOfTheSameListCreatesNoDuplicate(): void
    {
        $this->store($this->exporter, 'Home Raitaru', self::RAITARU_LOCATION_ID, 'raitaru', [], 3.5);
        $this->store($this->exporter, 'Custom Azbel', null, 'azbel', [], 2.0);
        $entries = $this->export($this->exporter)->structures;
        $this->import($this->importer, $entries);

        $second = $this->import($this->importer, $entries);

        self::assertSame(['skip', 'skip'], $this->statuses($second));
        self::assertCount(2, $this->storedByName($this->importer));
    }

    public function testMissingFacilityTaxStaysUnknownNotZero(): void
    {
        $result = $this->import($this->importer, [$this->entry('Custom Raitaru', null, taxRate: null)]);

        self::assertSame(['create'], $this->statuses($result));
        self::assertNull($this->storedByName($this->importer)['Custom Raitaru']->getFacilityTaxPercent());
    }

    public function testInvalidEntriesAreReportedAndSkippedWhileValidOnesAreImported(): void
    {
        $result = $this->import($this->importer, [
            $this->entry('Large rig on raitaru', null, rigs: [self::LARGE_RIG]),
            $this->entry('Unknown rig', null, rigs: ['Standup Imaginary Rig I']),
            $this->entry('Tax too high', null, taxRate: 51.0),
            $this->entry('Bad security', null, securityType: 'wormhole'),
            $this->entry('Bad type', null, structureType: 'deathstar'),
            $this->entry('Valid', null),
        ]);

        self::assertSame(['invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'create'], $this->statuses($result));
        foreach (array_slice($result->entries, 0, 5) as $line) {
            self::assertNotSame([], $line['errors']);
        }
        self::assertSame(['Valid'], array_keys($this->storedByName($this->importer)));
    }

    public function testEntryMissingARequiredFieldIsInvalidInsteadOfReceivingADefault(): void
    {
        $withoutType = $this->entry('No type', null);
        unset($withoutType['structureType']);
        $withoutSecurity = $this->entry('No security', null);
        unset($withoutSecurity['securityType']);
        $withoutName = $this->entry('', null);

        $result = $this->import($this->importer, [$withoutType, $withoutSecurity, $withoutName]);

        self::assertSame(['invalid', 'invalid', 'invalid'], $this->statuses($result));
        self::assertSame([], $this->storedByName($this->importer));
    }

    public function testExistingStructureIsKeptUnlessOverwriteIsRequested(): void
    {
        $this->store($this->importer, 'Mine', self::RAITARU_LOCATION_ID, 'raitaru', [self::ME_RIG], 3.5);

        $result = $this->import($this->importer, [$this->entry('Theirs', self::RAITARU_LOCATION_ID, rigs: [self::ME_RIG_TIER_TWO], taxRate: 1.0)]);

        self::assertSame(['skip'], $this->statuses($result));
        $stored = $this->storedByName($this->importer)['Mine'];
        self::assertSame([self::ME_RIG], $stored->getRigs());
        self::assertSame(3.5, $stored->getFacilityTaxPercent());
    }

    public function testOverwriteReplacesRigsAndTaxButNothingElse(): void
    {
        $this->store($this->importer, 'Mine', self::RAITARU_LOCATION_ID, 'raitaru', [self::ME_RIG], 3.5, isDefault: true);

        $result = $this->import(
            $this->importer,
            [$this->entry('Theirs', self::RAITARU_LOCATION_ID, structureType: 'engineering_complex', securityType: 'highsec', rigs: [self::ME_RIG_TIER_TWO], taxRate: 1.0)],
            overwriteExisting: true,
        );

        self::assertSame(['update'], $this->statuses($result));
        $stored = $this->storedByName($this->importer)['Mine'];
        self::assertSame([self::ME_RIG_TIER_TWO], $stored->getRigs());
        self::assertSame(1.0, $stored->getFacilityTaxPercent());
        self::assertSame('raitaru', $stored->getStructureType());
        self::assertSame('nullsec', $stored->getSecurityType());
        self::assertTrue($stored->isDefault());
    }

    public function testOverwriteWithoutATaxKeepsTheKnownTax(): void
    {
        $this->store($this->importer, 'Mine', self::RAITARU_LOCATION_ID, 'raitaru', [self::ME_RIG], 3.5);

        $this->import($this->importer, [$this->entry('Theirs', self::RAITARU_LOCATION_ID, rigs: [], taxRate: null)], overwriteExisting: true);

        $stored = $this->storedByName($this->importer)['Mine'];
        self::assertSame([], $stored->getRigs());
        self::assertSame(3.5, $stored->getFacilityTaxPercent());
    }

    public function testOverwriteWithRigsNotFittingTheExistingStructureIsInvalid(): void
    {
        $this->store($this->importer, 'Mine', self::RAITARU_LOCATION_ID, 'raitaru', [self::ME_RIG], 3.5);

        $result = $this->import($this->importer, [$this->entry('Theirs', self::RAITARU_LOCATION_ID, rigs: [self::LARGE_RIG])], overwriteExisting: true);

        self::assertSame(['invalid'], $this->statuses($result));
        self::assertSame([self::ME_RIG], $this->storedByName($this->importer)['Mine']->getRigs());
    }

    public function testCorporationFlagIsRecomputedForTheImporterAndDefaultIsNeverImported(): void
    {
        $this->store($this->exporter, 'Corp Raitaru', self::RAITARU_LOCATION_ID, 'raitaru', [], 3.5, isDefault: true, isCorporationStructure: true);
        $entries = $this->export($this->exporter)->structures;

        $this->import($this->importer, $entries);

        $stored = $this->storedByName($this->importer)['Corp Raitaru'];
        self::assertFalse($stored->isCorporationStructure());
        self::assertFalse($stored->isDefault());
        self::assertSame(self::IMPORTER_CORPORATION_ID, $stored->getCorporationId());
    }

    public function testExportCarriesNoDefaultFlagCorporationFlagOrInternalId(): void
    {
        $this->store($this->exporter, 'Corp Raitaru', self::RAITARU_LOCATION_ID, 'raitaru', [], 3.5, isDefault: true, isCorporationStructure: true);

        $exported = $this->export($this->exporter);

        self::assertSame('evetools.industry-structures', $exported->format);
        self::assertSame(1, $exported->version);
        self::assertSame(
            ['facilityTaxRate', 'locationId', 'name', 'rigs', 'securityType', 'solarSystemId', 'structureType'],
            $this->sortedKeys($exported->structures[0]),
        );
    }

    public function testExportLeavesOutDeletedStructuresAndOtherUsersStructures(): void
    {
        $this->store($this->exporter, 'Kept', null, 'raitaru', [], null);
        $deleted = $this->store($this->exporter, 'Deleted', null, 'raitaru', [], null);
        $deleted->setIsDeleted(true);
        $this->store($this->importer, 'Someone else', null, 'raitaru', [], null);
        $this->em->flush();

        $exported = $this->export($this->exporter);

        self::assertSame(['Kept'], array_column($exported->structures, 'name'));
    }

    public function testImportedStructureTakesItsSolarSystemFromTheEsiCacheElseTheEntry(): void
    {
        $result = $this->import($this->importer, [
            $this->entry('Cached', self::RAITARU_LOCATION_ID, solarSystemId: self::SUBMITTED_SYSTEM_ID),
            $this->entry('Uncached', self::UNCACHED_LOCATION_ID, solarSystemId: self::SUBMITTED_SYSTEM_ID),
        ]);

        self::assertSame(['create', 'create'], $this->statuses($result));
        $stored = $this->storedByName($this->importer);
        self::assertSame(self::CACHED_SYSTEM_ID, $stored['Cached']->getSolarSystemId());
        self::assertSame(self::SUBMITTED_SYSTEM_ID, $stored['Uncached']->getSolarSystemId());
    }

    public function testSameLocationTwiceInTheListImportsItOnce(): void
    {
        $result = $this->import($this->importer, [
            $this->entry('First', self::UNCACHED_LOCATION_ID),
            $this->entry('Second', self::UNCACHED_LOCATION_ID),
        ]);

        self::assertSame(['create', 'skip'], $this->statuses($result));
        self::assertSame(['First'], array_keys($this->storedByName($this->importer)));
    }

    public function testManualEntriesAreMatchedByNameAndSameNameTwiceImportsOnce(): void
    {
        $this->store($this->importer, 'Already here', null, 'raitaru', [], null);

        $result = $this->import($this->importer, [
            $this->entry('Already here', null),
            $this->entry('Brand new', null),
            $this->entry('Brand new', null),
        ]);

        self::assertSame(['skip', 'create', 'skip'], $this->statuses($result));
        self::assertSame(['Already here', 'Brand new'], array_keys($this->storedByName($this->importer)));
    }

    public function testStructureDeletedByTheImporterIsRestoredNotDuplicated(): void
    {
        $deleted = $this->store($this->importer, 'Old name', self::RAITARU_LOCATION_ID, 'raitaru', [], null);
        $deleted->setIsDeleted(true);
        $this->em->flush();

        $result = $this->import($this->importer, [$this->entry('Restored', self::RAITARU_LOCATION_ID)]);

        self::assertSame(['create'], $this->statuses($result));
        $this->em->clear();
        $rows = $this->repository()->findBy(['user' => $this->importer, 'locationId' => self::RAITARU_LOCATION_ID]);
        self::assertCount(1, $rows);
        self::assertFalse($rows[0]->isDeleted());
    }

    public function testDryRunWritesNothingAndReportsTheStatuses(): void
    {
        $this->store($this->importer, 'Mine', self::TATARA_LOCATION_ID, 'tatara', [], 2.0);

        $result = $this->import($this->importer, [
            $this->entry('New', self::RAITARU_LOCATION_ID),
            $this->entry('Mine', self::TATARA_LOCATION_ID, structureType: 'tatara'),
            $this->entry('Broken', null, taxRate: 99.0),
        ], dryRun: true);

        self::assertSame(['create', 'skip', 'invalid'], $this->statuses($result));
        self::assertFalse($result->applied);
        self::assertSame(['Mine'], array_keys($this->storedByName($this->importer)));
    }

    public function testMoreThanFiveHundredEntriesAreRefused(): void
    {
        $entries = [];
        for ($i = 0; $i < 501; ++$i) {
            $entries[] = $this->entry('Structure '.$i, null);
        }

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);

        $this->import($this->importer, $entries);
    }

    public function testImportOnlyTouchesTheCurrentUsersStructures(): void
    {
        $this->store($this->exporter, 'Exporter owned', self::TATARA_LOCATION_ID, 'tatara', [], 2.0);

        $this->import(
            $this->importer,
            [$this->entry('Theirs', self::TATARA_LOCATION_ID, structureType: 'tatara', taxRate: 9.0)],
            overwriteExisting: true,
        );

        $this->em->clear();
        $exporterRows = $this->repository()->findBy(['user' => $this->exporter]);
        self::assertSame(2.0, $exporterRows[0]->getFacilityTaxPercent());
        self::assertSame('Exporter owned', $exporterRows[0]->getName());
    }

    /**
     * @param list<string> $rigs
     *
     * @return array<string, mixed>
     */
    private function entry(
        string $name,
        ?int $locationId,
        string $structureType = 'raitaru',
        string $securityType = 'nullsec',
        array $rigs = [],
        ?float $taxRate = 3.0,
        ?int $solarSystemId = null,
    ): array {
        return [
            'name' => $name,
            'locationId' => $locationId,
            'solarSystemId' => $solarSystemId,
            'structureType' => $structureType,
            'securityType' => $securityType,
            'rigs' => $rigs,
            'facilityTaxRate' => $taxRate,
        ];
    }

    /** @param list<array<string, mixed>> $entries */
    private function import(User $as, array $entries, bool $overwriteExisting = false, bool $dryRun = false): StructureListImportResource
    {
        $this->authenticate($as);
        $input = new ImportStructureListInput();
        $input->entries = $entries;
        $input->overwriteExisting = $overwriteExisting;
        $input->dryRun = $dryRun;

        $result = self::getContainer()->get(ImportStructureListProcessor::class)->process($input, new Post());
        $this->em->clear();
        $this->exporter = $this->reloaded($this->exporter);
        $this->importer = $this->reloaded($this->importer);

        return $result;
    }

    private function export(User $as): StructureListExportResource
    {
        $this->authenticate($as);

        return self::getContainer()->get(StructureListExportProvider::class)->provide(new Get());
    }

    /** @return list<string> */
    private function statuses(StructureListImportResource $result): array
    {
        return array_column($result->entries, 'status');
    }

    /** @return array<string, IndustryStructureConfig> */
    private function storedByName(User $user): array
    {
        $byName = [];
        foreach ($this->repository()->findByUser($user) as $structure) {
            $byName[$structure->getName()] = $structure;
        }
        ksort($byName);

        return $byName;
    }

    /**
     * @param list<string> $rigs
     */
    private function store(
        User $user,
        string $name,
        ?int $locationId,
        string $structureType,
        array $rigs,
        ?float $taxRate,
        bool $isDefault = false,
        bool $isCorporationStructure = false,
    ): IndustryStructureConfig {
        $structure = (new IndustryStructureConfig())
            ->setUser($user)
            ->setName($name)
            ->setLocationId($locationId)
            ->setSolarSystemId(self::CACHED_SYSTEM_ID)
            ->setCorporationId($user->getCorporationId())
            ->setSecurityType('nullsec')
            ->setStructureType($structureType)
            ->setRigs($rigs)
            ->setFacilityTaxPercent($taxRate)
            ->setIsDefault($isDefault)
            ->setIsCorporationStructure($isCorporationStructure);
        $this->em->persist($structure);
        $this->em->flush();

        return $structure;
    }

    private function pilotOfCorporation(string $name, int $corporationId): User
    {
        $character = $this->createCharacter(name: $name)->setCorporationId($corporationId);
        $user = $character->getUser();
        \assert($user instanceof User);
        $user->setMainCharacter($character);

        return $user;
    }

    private function reloaded(User $user): User
    {
        $reloaded = $this->em->find(User::class, $user->getId());
        \assert($reloaded instanceof User);

        return $reloaded;
    }

    private function authenticate(User $user): void
    {
        self::getContainer()->get('security.token_storage')
            ->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));
    }

    private function repository(): IndustryStructureConfigRepository
    {
        return self::getContainer()->get(IndustryStructureConfigRepository::class);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    }
}
