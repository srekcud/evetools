<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\PlanetaryRoute;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\Sde\PlanetSchematicType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[Group('issue-1380')]
final class PlanetaryInputNeedsApiTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-11-25T12:00:00+00:00';
    private const string YESTERDAY = '2026-11-24T12:00:00+00:00';

    private const int SPACEPORTS_GROUP_ID = 1030;
    private const int COMMODITIES_GROUP_ID = 990_042;
    private const int STRUCTURES_CATEGORY_ID = 990_066;

    private const int LAUNCHPAD_TYPE_ID = 990_256;
    private const int EXTRACTOR_TYPE_ID = 990_848;
    private const int FACTORY_TYPE_ID = 990_473;
    private const int BACTERIA = 990_001;
    private const int REACTIVE_METALS = 990_002;
    private const int OXYGEN = 990_101;
    private const int COOLANT = 990_102;

    private const int ALICE_SCHEMATIC = 990_001;
    private const int BOB_SCHEMATIC = 990_002;

    private const int ALICE_PLANET = 40_113_801;
    private const int BOB_PLANET = 40_113_802;

    private const int ALICE_LAUNCHPAD = 1_038_000_000_101;
    private const int ALICE_FACTORY = 1_038_000_000_102;
    private const int ALICE_EXTRACTOR = 1_038_000_000_103;
    private const int BOB_LAUNCHPAD = 1_038_000_000_201;
    private const int BOB_FACTORY = 1_038_000_000_202;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $alice;
    private User $bob;
    private int $nextEveCharacterId = 2_113_801_001;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedSde();
        $this->alice = $this->persistPilot('Alice Tarn');
        $this->bob = $this->persistPilot('Bob Kess');
        $this->persistAliceColony();
        $this->persistBobColony();
    }

    public function testInputNeedsRequireAuthentication(): void
    {
        $this->client->request('GET', '/api/planetary/input-needs', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUserSeesTheInputNeedsOfTheirOwnColoniesOnly(): void
    {
        $this->client->loginUser($this->alice, 'api');

        $json = $this->getInputNeeds();

        self::assertTrue($json['complete']);
        self::assertSame([self::ALICE_PLANET], array_column($json['colonies'], 'planetId'));
        $needs = $json['colonies'][0]['needs'];
        self::assertSame([self::BACTERIA], array_column($needs, 'typeId'));
        self::assertEqualsWithDelta(960.0, $needs[0]['dailyRequired'], 0.0001);
        self::assertEqualsWithDelta(0.0, $needs[0]['dailyProducedLocally'], 0.0001);
        self::assertEqualsWithDelta(960.0, $needs[0]['dailyNetImport'], 0.0001);
        self::assertSame([self::ALICE_LAUNCHPAD], array_column($json['launchpads'], 'pinId'));
        self::assertSame([self::BACTERIA], array_column($json['launchpads'][0]['feeds'], 'typeId'));
        self::assertEqualsWithDelta(960.0, $json['launchpads'][0]['feeds'][0]['dailyRate'], 0.0001);
        self::assertSame([self::BACTERIA], array_column($json['weekly'], 'typeId'));
        self::assertEqualsWithDelta(6720.0, $json['weekly'][0]['weeklyConsumption'], 0.0001);
    }

    public function testAnotherUserSeesTheirOwnFiguresAndNothingOfTheFirst(): void
    {
        $this->client->loginUser($this->bob, 'api');

        $json = $this->getInputNeeds();

        self::assertSame([self::BOB_PLANET], array_column($json['colonies'], 'planetId'));
        self::assertSame([self::REACTIVE_METALS], array_column($json['colonies'][0]['needs'], 'typeId'));
        self::assertEqualsWithDelta(480.0, $json['colonies'][0]['needs'][0]['dailyRequired'], 0.0001);
        self::assertSame([self::BOB_LAUNCHPAD], array_column($json['launchpads'], 'pinId'));
        self::assertSame([self::REACTIVE_METALS], array_column($json['weekly'], 'typeId'));
        self::assertEqualsWithDelta(3360.0, $json['weekly'][0]['weeklyConsumption'], 0.0001);
    }

    private function seedSde(): void
    {
        $category = $this->em->find(InvCategory::class, self::STRUCTURES_CATEGORY_ID)
            ?? (new InvCategory())->setCategoryId(self::STRUCTURES_CATEGORY_ID)->setCategoryName('Planetary Infrastructure');
        $this->em->persist($category);
        $spaceports = $this->em->find(InvGroup::class, self::SPACEPORTS_GROUP_ID)
            ?? (new InvGroup())->setGroupId(self::SPACEPORTS_GROUP_ID)->setGroupName('Spaceports')->setCategory($category);
        $this->em->persist($spaceports);
        $commodities = (new InvGroup())->setGroupId(self::COMMODITIES_GROUP_ID)->setGroupName('Basic Commodities')->setCategory($category);
        $this->em->persist($commodities);

        $this->persistType(self::LAUNCHPAD_TYPE_ID, 'Launchpad', $spaceports, null, 10000.0);
        $this->persistType(self::EXTRACTOR_TYPE_ID, 'Extractor Control Unit', $commodities, null, null);
        $this->persistType(self::FACTORY_TYPE_ID, 'Basic Industry Facility', $commodities, null, null);
        $this->persistType(self::BACTERIA, 'Bacteria', $commodities, 0.38, null);
        $this->persistType(self::REACTIVE_METALS, 'Reactive Metals', $commodities, 0.52, null);
        $this->persistType(self::OXYGEN, 'Oxygen', $commodities, 1.5, null);
        $this->persistType(self::COOLANT, 'Coolant', $commodities, 1.75, null);

        $this->persistSchematic(self::ALICE_SCHEMATIC, 3600, [self::BACTERIA => 40], self::OXYGEN, 1);
        $this->persistSchematic(self::BOB_SCHEMATIC, 1800, [self::REACTIVE_METALS => 10], self::COOLANT, 1);
        $this->em->flush();
    }

    private function persistType(int $typeId, string $typeName, InvGroup $group, ?float $volume, ?float $capacity): void
    {
        $this->em->persist(
            (new InvType())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setGroup($group)
                ->setVolume($volume)
                ->setCapacity($capacity)
                ->setPublished(true),
        );
    }

    /**
     * @param array<int, int> $inputs
     */
    private function persistSchematic(int $schematicId, int $cycleTime, array $inputs, int $outputTypeId, int $outputQuantity): void
    {
        $schematic = (new PlanetSchematic())
            ->setSchematicId($schematicId)
            ->setSchematicName('Schematic '.$schematicId)
            ->setCycleTime($cycleTime);
        $this->em->persist($schematic);
        foreach ($inputs as $typeId => $quantity) {
            $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($typeId)->setIsInput(true)->setQuantity($quantity));
        }
        $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($outputTypeId)->setIsInput(false)->setQuantity($outputQuantity));
    }

    private function persistPilot(string $name): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($this->nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId(98_538_001)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function persistAliceColony(): void
    {
        $colony = $this->newColony($this->alice, self::ALICE_PLANET, 'Aldranette II');
        $colony->addPin((new PlanetaryPin())
            ->setPinId(self::ALICE_LAUNCHPAD)
            ->setTypeId(self::LAUNCHPAD_TYPE_ID)
            ->setContents([['type_id' => self::BACTERIA, 'amount' => 500]]));
        $colony->addPin((new PlanetaryPin())
            ->setPinId(self::ALICE_FACTORY)
            ->setTypeId(self::FACTORY_TYPE_ID)
            ->setSchematicId(self::ALICE_SCHEMATIC));
        $colony->addPin((new PlanetaryPin())
            ->setPinId(self::ALICE_EXTRACTOR)
            ->setTypeId(self::EXTRACTOR_TYPE_ID)
            ->setExtractorProductTypeId(self::BACTERIA)
            ->setExtractorCycleTime(1800)
            ->setExtractorQtyPerCycle(4400)
            ->setExpiryTime(new \DateTimeImmutable(self::YESTERDAY)));
        $colony->addRoute((new PlanetaryRoute())
            ->setRouteId(1)
            ->setSourcePinId(self::ALICE_LAUNCHPAD)
            ->setDestinationPinId(self::ALICE_FACTORY)
            ->setContentTypeId(self::BACTERIA)
            ->setQuantity(1800.0));
        $this->em->persist($colony);
        $this->em->flush();
    }

    private function persistBobColony(): void
    {
        $colony = $this->newColony($this->bob, self::BOB_PLANET, 'Jaspet IV');
        $colony->addPin((new PlanetaryPin())
            ->setPinId(self::BOB_LAUNCHPAD)
            ->setTypeId(self::LAUNCHPAD_TYPE_ID));
        $colony->addPin((new PlanetaryPin())
            ->setPinId(self::BOB_FACTORY)
            ->setTypeId(self::FACTORY_TYPE_ID)
            ->setSchematicId(self::BOB_SCHEMATIC));
        $colony->addRoute((new PlanetaryRoute())
            ->setRouteId(1)
            ->setSourcePinId(self::BOB_LAUNCHPAD)
            ->setDestinationPinId(self::BOB_FACTORY)
            ->setContentTypeId(self::REACTIVE_METALS)
            ->setQuantity(600.0));
        $this->em->persist($colony);
        $this->em->flush();
    }

    private function newColony(User $user, int $planetId, string $planetName): PlanetaryColony
    {
        $character = $user->getMainCharacter();
        self::assertNotNull($character);

        return (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId($planetId)
            ->setPlanetName($planetName)
            ->setPlanetType('temperate')
            ->setSolarSystemId(30_001_538)
            ->setSolarSystemName('Aldranette')
            ->setUpgradeLevel(4)
            ->setNumPins(3)
            ->setLastUpdate(new \DateTimeImmutable(self::NOW));
    }

    /**
     * @return array<string, mixed>
     */
    private function getInputNeeds(): array
    {
        $this->client->request('GET', '/api/planetary/input-needs', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
