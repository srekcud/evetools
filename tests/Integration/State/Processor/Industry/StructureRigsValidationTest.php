<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Processor\Industry;

use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\Exception\ValidationException;
use ApiPlatform\Validator\ValidatorInterface;
use App\ApiResource\Industry\StructureConfigResource;
use App\ApiResource\Input\Industry\CreateStructureInput;
use App\ApiResource\Input\Industry\UpdateStructureInput;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use App\State\Processor\Industry\CreateStructureProcessor;
use App\State\Processor\Industry\UpdateStructureProcessor;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

#[Group('issue-199')]
final class StructureRigsValidationTest extends IntegrationTestCase
{
    private const string M_EQUIPMENT_MATERIAL_RIG = 'Standup M-Set Equipment Manufacturing Material Efficiency I';
    private const string M_AMMUNITION_MATERIAL_RIG = 'Standup M-Set Ammunition Manufacturing Material Efficiency II';
    private const string M_BASIC_SMALL_SHIP_TIME_RIG = 'Standup M-Set Basic Small Ship Manufacturing Time Efficiency I';
    private const string M_STRUCTURE_MATERIAL_RIG = 'Standup M-Set Structure Manufacturing Material Efficiency II';
    private const string L_EQUIPMENT_RIG = 'Standup L-Set Equipment Manufacturing Efficiency I';
    private const string L_AMMUNITION_RIG = 'Standup L-Set Ammunition Manufacturing Efficiency II';
    private const string L_CAPITAL_SHIP_RIG = 'Standup L-Set Capital Ship Manufacturing Efficiency I';
    private const string L_DRONE_AND_FIGHTER_RIG = 'Standup L-Set Drone and Fighter Manufacturing Efficiency II';
    private const string M_COMPOSITE_REACTOR_RIG = 'Standup M-Set Composite Reactor Material Efficiency I';
    private const string XL_SHIP_RIG = 'Standup XL-Set Ship Manufacturing Efficiency II';
    private const string UNKNOWN_RIG = 'Standup M-Set Inexistant I';

    private User $pilot;

    protected function setUp(): void
    {
        parent::setUp();
        $character = $this->createCharacter(name: 'Rig Fitting Pilot');
        $pilot = $character->getUser();
        \assert($pilot instanceof User);
        $pilot->setMainCharacter($character);
        $this->pilot = $pilot;
        $this->em->flush();

        self::getContainer()->get('security.token_storage')
            ->setToken(new UsernamePasswordToken($this->pilot, 'api', $this->pilot->getRoles()));
    }

    public function testCreatingARaitaruWithThreeDistinctMediumManufacturingRigsStoresThem(): void
    {
        $rigs = [self::M_EQUIPMENT_MATERIAL_RIG, self::M_AMMUNITION_MATERIAL_RIG, self::M_BASIC_SMALL_SHIP_TIME_RIG];

        $resource = $this->create(['name' => 'Fitted Raitaru', 'structureType' => 'raitaru', 'rigs' => $rigs]);

        self::assertSame($rigs, $resource->rigs);
        self::assertSame($rigs, $this->storedStructure($resource->id)->getRigs());
    }

    public function testCreatingARaitaruWithFourRigsIsRejectedOnRigsAndStoresNothing(): void
    {
        $rejection = $this->rejectionOf(fn () => $this->create([
            'name' => 'Overfitted Raitaru',
            'structureType' => 'raitaru',
            'rigs' => [self::M_EQUIPMENT_MATERIAL_RIG, self::M_AMMUNITION_MATERIAL_RIG, self::M_BASIC_SMALL_SHIP_TIME_RIG, self::M_STRUCTURE_MATERIAL_RIG],
        ]));

        $this->assertRejectedOnRigs($rejection);
        self::assertSame([], $this->storedStructuresOfPilot());
    }

    public function testCreatingARaitaruWithTheSameRigTwiceIsRejectedOnRigsAndStoresNothing(): void
    {
        $rejection = $this->rejectionOf(fn () => $this->create([
            'name' => 'Doubled Raitaru',
            'structureType' => 'raitaru',
            'rigs' => [self::M_AMMUNITION_MATERIAL_RIG, self::M_AMMUNITION_MATERIAL_RIG],
        ]));

        $this->assertRejectedOnRigs($rejection);
        self::assertSame([], $this->storedStructuresOfPilot());
    }

    public function testCreatingARaitaruWithARigUnknownToTheCatalogueIsRejectedOnRigsAndStoresNothing(): void
    {
        $rejection = $this->rejectionOf(fn () => $this->create([
            'name' => 'Mystery Raitaru',
            'structureType' => 'raitaru',
            'rigs' => [self::UNKNOWN_RIG],
        ]));

        $this->assertRejectedOnRigs($rejection);
        self::assertSame([], $this->storedStructuresOfPilot());
    }

    public function testCreatingARaitaruWithALargeManufacturingRigIsRejectedOnRigsAndStoresNothing(): void
    {
        $rejection = $this->rejectionOf(fn () => $this->create([
            'name' => 'Oversized Raitaru',
            'structureType' => 'raitaru',
            'rigs' => [self::L_EQUIPMENT_RIG],
        ]));

        $this->assertRejectedOnRigs($rejection);
        self::assertSame([], $this->storedStructuresOfPilot());
    }

    public function testCreatingARaitaruWithAMediumReactionRigIsRejectedOnRigsAndStoresNothing(): void
    {
        $rejection = $this->rejectionOf(fn () => $this->create([
            'name' => 'Reacting Raitaru',
            'structureType' => 'raitaru',
            'rigs' => [self::M_COMPOSITE_REACTOR_RIG],
        ]));

        $this->assertRejectedOnRigs($rejection);
        self::assertSame([], $this->storedStructuresOfPilot());
    }

    public function testCreatingAnNpcStationWithAManufacturingRigIsRejectedOnRigsAndStoresNothing(): void
    {
        $rejection = $this->rejectionOf(fn () => $this->create([
            'name' => 'Jita 4-4',
            'structureType' => 'station',
            'rigs' => [self::M_STRUCTURE_MATERIAL_RIG],
        ]));

        $this->assertRejectedOnRigs($rejection);
        self::assertSame([], $this->storedStructuresOfPilot());
    }

    public function testCreatingALegacyEngineeringComplexAcceptsAnExtraLargeManufacturingRig(): void
    {
        $resource = $this->create(['name' => 'Legacy Complex', 'structureType' => 'engineering_complex', 'rigs' => [self::XL_SHIP_RIG]]);

        self::assertSame([self::XL_SHIP_RIG], $resource->rigs);
        self::assertSame([self::XL_SHIP_RIG], $this->storedStructure($resource->id)->getRigs());
    }

    public function testPatchingAnAzbelWithFourLargeRigsIsRejectedOnRigsAndLeavesItUnchanged(): void
    {
        $azbel = $this->storeStructure('Home Azbel', 'azbel', [self::L_CAPITAL_SHIP_RIG]);

        $rejection = $this->rejectionOf(fn () => $this->update($azbel, [
            'rigs' => [self::L_EQUIPMENT_RIG, self::L_AMMUNITION_RIG, self::L_CAPITAL_SHIP_RIG, self::L_DRONE_AND_FIGHTER_RIG],
        ]));

        $this->assertRejectedOnRigs($rejection);
        $stored = $this->storedStructure($azbel->getId()->toRfc4122());
        self::assertSame('azbel', $stored->getStructureType());
        self::assertSame([self::L_CAPITAL_SHIP_RIG], $stored->getRigs());
    }

    public function testPatchingARaitaruIntoAnAzbelWithoutRigsValidatesItsMediumRigAgainstTheAzbelAndLeavesItUnchanged(): void
    {
        $raitaru = $this->storeStructure('Growing Raitaru', 'raitaru', [self::M_EQUIPMENT_MATERIAL_RIG]);

        $rejection = $this->rejectionOf(fn () => $this->update($raitaru, ['structureType' => 'azbel']));

        $this->assertRejectedOnRigs($rejection);
        $stored = $this->storedStructure($raitaru->getId()->toRfc4122());
        self::assertSame('raitaru', $stored->getStructureType());
        self::assertSame([self::M_EQUIPMENT_MATERIAL_RIG], $stored->getRigs());
    }

    public function testRenamingARaitaruWithAMediumRigKeepsItsRigs(): void
    {
        $raitaru = $this->storeStructure('Old Raitaru', 'raitaru', [self::M_AMMUNITION_MATERIAL_RIG]);

        $resource = $this->update($raitaru, ['name' => 'Renamed Raitaru']);

        self::assertSame([self::M_AMMUNITION_MATERIAL_RIG], $resource->rigs);
        $stored = $this->storedStructure($resource->id);
        self::assertSame('Renamed Raitaru', $stored->getName());
        self::assertSame([self::M_AMMUNITION_MATERIAL_RIG], $stored->getRigs());
    }

    public function testCreatingASotiyoWithoutRigsStoresNoRig(): void
    {
        $resource = $this->create(['name' => 'Bare Sotiyo', 'structureType' => 'sotiyo', 'rigs' => []]);

        self::assertSame([], $resource->rigs);
        self::assertSame([], $this->storedStructure($resource->id)->getRigs());
    }

    private function create(array $payload): StructureConfigResource
    {
        $input = $this->denormalizer()->denormalize($payload + ['securityType' => 'nullsec'], CreateStructureInput::class, 'json');
        $this->validator()->validate($input);

        return self::getContainer()->get(CreateStructureProcessor::class)->process($input, new Post());
    }

    private function update(IndustryStructureConfig $structure, array $payload): StructureConfigResource
    {
        $input = $this->denormalizer()->denormalize($payload, UpdateStructureInput::class, 'json');
        $this->validator()->validate($input);

        return self::getContainer()->get(UpdateStructureProcessor::class)
            ->process($input, new Patch(), ['id' => $structure->getId()->toRfc4122()]);
    }

    private function rejectionOf(callable $submission): ValidationException
    {
        try {
            $submission();
        } catch (ValidationException $rejection) {
            return $rejection;
        }

        self::fail('The structure was accepted instead of being rejected on its rigs.');
    }

    private function assertRejectedOnRigs(ValidationException $rejection): void
    {
        self::assertSame(422, $rejection->getStatusCode());

        $propertyPaths = [];
        foreach ($rejection->getConstraintViolationList() as $violation) {
            $propertyPaths[] = $violation->getPropertyPath();
        }
        self::assertSame(['rigs'], array_values(array_unique($propertyPaths)));
    }

    private function storeStructure(string $name, string $structureType, array $rigs): IndustryStructureConfig
    {
        $structure = (new IndustryStructureConfig())
            ->setUser($this->pilot)
            ->setName($name)
            ->setSecurityType('nullsec')
            ->setStructureType($structureType)
            ->setRigs($rigs);
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

    private function storedStructuresOfPilot(): array
    {
        $this->em->clear();

        return array_values(self::getContainer()->get(IndustryStructureConfigRepository::class)->findBy(['user' => $this->pilot->getId()]));
    }

    private function validator(): ValidatorInterface
    {
        return self::getContainer()->get(ValidatorInterface::class);
    }

    private function denormalizer(): DenormalizerInterface
    {
        return self::getContainer()->get(DenormalizerInterface::class);
    }
}
