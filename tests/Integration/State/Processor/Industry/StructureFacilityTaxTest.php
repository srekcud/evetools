<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Processor\Industry;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Industry\StructureConfigResource;
use App\ApiResource\Input\Industry\CreateStructureInput;
use App\ApiResource\Input\Industry\UpdateStructureInput;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use App\State\Processor\Industry\CreateStructureProcessor;
use App\State\Processor\Industry\UpdateStructureProcessor;
use App\State\Provider\Industry\StructureConfigCollectionProvider;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final class StructureFacilityTaxTest extends IntegrationTestCase
{
    private const float NPC_STATION_FACILITY_TAX_RATE = 0.25;

    private User $pilot;

    protected function setUp(): void
    {
        parent::setUp();
        $character = $this->createCharacter(name: 'Facility Tax Pilot');
        $pilot = $character->getUser();
        \assert($pilot instanceof User);
        $pilot->setMainCharacter($character);
        $this->pilot = $pilot;
        $this->em->flush();

        self::getContainer()->get('security.token_storage')
            ->setToken(new UsernamePasswordToken($this->pilot, 'api', $this->pilot->getRoles()));
    }

    public function testCreatingARaitaruStoresAndExposesTheSubmittedFacilityTax(): void
    {
        $resource = $this->create(['name' => 'Home Raitaru', 'structureType' => 'raitaru', 'facilityTaxRate' => 5]);

        self::assertSame(5.0, $this->exposedFacilityTaxRate($resource));
        self::assertSame(5.0, $this->storedStructure($resource->id)->getFacilityTaxRate());
    }

    public function testCreatingARaitaruWithoutFacilityTaxLeavesItNotEntered(): void
    {
        $resource = $this->create(['name' => 'Untaxed Raitaru', 'structureType' => 'raitaru']);

        self::assertNull($this->exposedFacilityTaxRate($resource));
        self::assertNull($this->storedStructure($resource->id)->getFacilityTaxRate());
    }

    public function testCreatingAnAzbelExposesADecimalFacilityTax(): void
    {
        $resource = $this->create(['name' => 'Capital Azbel', 'structureType' => 'azbel', 'facilityTaxRate' => 3.5]);

        self::assertSame(3.5, $this->exposedFacilityTaxRate($resource));
    }

    public function testCreatingAStructureAcceptsTheZeroFacilityTaxLowerBound(): void
    {
        $resource = $this->create(['name' => 'Free Sotiyo', 'structureType' => 'sotiyo', 'facilityTaxRate' => 0]);

        self::assertSame(0.0, $this->exposedFacilityTaxRate($resource));
    }

    public function testCreatingAStructureAcceptsTheFiftyPercentFacilityTaxUpperBound(): void
    {
        $resource = $this->create(['name' => 'Greedy Tatara', 'structureType' => 'tatara', 'facilityTaxRate' => 50]);

        self::assertSame(50.0, $this->exposedFacilityTaxRate($resource));
    }

    public function testPatchingTheFacilityTaxOfARaitaruReplacesIt(): void
    {
        $raitaru = $this->storeStructure('Taxed Raitaru', 'raitaru', 2.0);

        $resource = $this->update($raitaru, ['facilityTaxRate' => 7.5]);

        self::assertSame(7.5, $this->exposedFacilityTaxRate($resource));
        self::assertSame(7.5, $this->storedStructure($resource->id)->getFacilityTaxRate());
    }

    public function testPatchingARaitaruWithoutFacilityTaxKeepsTheStoredOne(): void
    {
        $raitaru = $this->storeStructure('Taxed Raitaru', 'raitaru', 2.0);

        $resource = $this->update($raitaru, ['name' => 'X']);

        self::assertSame(2.0, $this->exposedFacilityTaxRate($resource));
        self::assertSame(2.0, $this->storedStructure($resource->id)->getFacilityTaxRate());
    }

    public function testCreatingAnNpcStationExposesTheFacilityTaxFixedByTheGame(): void
    {
        $resource = $this->create(['name' => 'Jita 4-4', 'structureType' => 'station', 'facilityTaxRate' => 10]);

        self::assertSame(self::NPC_STATION_FACILITY_TAX_RATE, $this->exposedFacilityTaxRate($resource));
    }

    public function testStructureListExposesTheGameFacilityTaxForAStoredNpcStationWithoutTax(): void
    {
        $station = $this->storeStructure('Amarr VIII', 'station', null);

        $list = self::getContainer()->get(StructureConfigCollectionProvider::class)->provide(new Get());

        $listed = array_values(array_filter(
            $list->structures,
            fn (StructureConfigResource $resource) => $resource->id === $station->getId()->toRfc4122(),
        ));
        self::assertCount(1, $listed);
        self::assertSame(self::NPC_STATION_FACILITY_TAX_RATE, $this->exposedFacilityTaxRate($listed[0]));
    }

    public function testNpcStationTurnedIntoARaitaruWithoutFacilityTaxHasNoFacilityTax(): void
    {
        $station = $this->create(['name' => 'Dodixie IX', 'structureType' => 'station', 'facilityTaxRate' => 10]);

        $resource = $this->updateById($station->id, ['structureType' => 'raitaru']);

        self::assertNull($this->exposedFacilityTaxRate($resource));
        self::assertNull($this->storedStructure($resource->id)->getFacilityTaxRate());
    }

    public function testNpcStationTurnedIntoARaitaruTakesTheSubmittedFacilityTax(): void
    {
        $station = $this->create(['name' => 'Rens VI', 'structureType' => 'station', 'facilityTaxRate' => 10]);

        $resource = $this->updateById($station->id, ['structureType' => 'raitaru', 'facilityTaxRate' => 4]);

        self::assertSame(4.0, $this->exposedFacilityTaxRate($resource));
        self::assertSame(4.0, $this->storedStructure($resource->id)->getFacilityTaxRate());
    }

    private function create(array $payload): StructureConfigResource
    {
        $input = $this->denormalizer()->denormalize($payload + ['securityType' => 'nullsec', 'rigs' => []], CreateStructureInput::class, 'json');

        return self::getContainer()->get(CreateStructureProcessor::class)->process($input, new Post());
    }

    private function update(IndustryStructureConfig $structure, array $payload): StructureConfigResource
    {
        return $this->updateById($structure->getId()->toRfc4122(), $payload);
    }

    private function updateById(string $structureId, array $payload): StructureConfigResource
    {
        $input = $this->denormalizer()->denormalize($payload, UpdateStructureInput::class, 'json');

        return self::getContainer()->get(UpdateStructureProcessor::class)->process($input, new Patch(), ['id' => $structureId]);
    }

    private function exposedFacilityTaxRate(StructureConfigResource $resource): ?float
    {
        $exposed = get_object_vars($resource);
        self::assertArrayHasKey('facilityTaxRate', $exposed);

        return $exposed['facilityTaxRate'];
    }

    private function storeStructure(string $name, string $structureType, ?float $facilityTaxRate): IndustryStructureConfig
    {
        $structure = (new IndustryStructureConfig())
            ->setUser($this->pilot)
            ->setName($name)
            ->setSecurityType('nullsec')
            ->setStructureType($structureType)
            ->setRigs([])
            ->setFacilityTaxRate($facilityTaxRate);
        $this->em->persist($structure);
        $this->em->flush();

        return $structure;
    }

    private function storedStructure(string $structureId): IndustryStructureConfig
    {
        $this->em->clear();
        $structure = self::getContainer()->get(IndustryStructureConfigRepository::class)->find($structureId);
        self::assertInstanceOf(IndustryStructureConfig::class, $structure);

        return $structure;
    }

    private function denormalizer(): DenormalizerInterface
    {
        return self::getContainer()->get(DenormalizerInterface::class);
    }
}
