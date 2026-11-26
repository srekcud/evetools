<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
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

#[Group('issue-1387')]
final class PlanetaryColonyExtractionBalanceApiTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-11-26T09:00:00+00:00';
    private const string YESTERDAY = '2026-11-25T09:00:00+00:00';

    private const int CATEGORY_ID = 990_387;
    private const int COMMODITIES_GROUP_ID = 990_388;
    private const int EXTRACTOR_TYPE = 990_389;
    private const int FACTORY_TYPE = 990_390;
    private const int STORAGE_TYPE = 990_391;
    private const int MICROORGANISMS = 990_392;
    private const int BIOFUELS = 990_393;
    private const int BIOFUELS_SCHEMATIC = 990_387;

    private const int EXTRACTION_PLANET = 40_138_701;
    private const int PLAIN_PLANET = 40_138_702;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private PlanetaryColony $extractionColony;
    private PlanetaryColony $plainColony;
    private int $nextPinId = 1_038_700_100_001;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedSde();

        $extractionPilot = $this->newCharacter(2_113_871_001, 'Extraction Pilot');
        $plainPilot = $this->newCharacter(2_113_871_002, 'Plain Pilot');
        $this->owner = new User();
        $this->em->persist($this->owner);
        foreach ([$extractionPilot, $plainPilot] as $character) {
            $this->owner->addCharacter($character);
            $this->em->persist($character);
        }
        $this->owner->setMainCharacter($extractionPilot);

        $this->extractionColony = $this->newColony($extractionPilot, self::EXTRACTION_PLANET, 'Aldranette IV');
        $this->extractionColony->addPin(
            (new PlanetaryPin())
                ->setPinId($this->nextPinId++)
                ->setTypeId(self::EXTRACTOR_TYPE)
                ->setExtractorProductTypeId(self::MICROORGANISMS)
                ->setExtractorCycleTime(1800)
                ->setExtractorQtyPerCycle(4400)
                ->setExtractorNumHeads(3)
                ->setInstallTime(new \DateTimeImmutable('2026-11-24T09:00:00+00:00'))
                ->setExpiryTime(new \DateTimeImmutable(self::YESTERDAY)),
        );
        $this->extractionColony->addPin(
            (new PlanetaryPin())
                ->setPinId($this->nextPinId++)
                ->setTypeId(self::FACTORY_TYPE)
                ->setSchematicId(self::BIOFUELS_SCHEMATIC),
        );
        $this->em->persist($this->extractionColony);

        $this->plainColony = $this->newColony($plainPilot, self::PLAIN_PLANET, 'Aldranette V');
        $this->plainColony->addPin(
            (new PlanetaryPin())
                ->setPinId($this->nextPinId++)
                ->setTypeId(self::STORAGE_TYPE),
        );
        $this->em->persist($this->plainColony);
        $this->em->flush();
    }

    public function testListCarriesTheExtractionBalanceOfTheExtractionColonyAndAnEmptyOneForTheOther(): void
    {
        $rows = $this->listRowsByPlanet();

        self::assertArrayHasKey('extractionBalance', $rows[self::EXTRACTION_PLANET]);
        $this->assertExpiredExtractorBalance($rows[self::EXTRACTION_PLANET]['extractionBalance']);
        self::assertArrayHasKey('extractionBalance', $rows[self::PLAIN_PLANET]);
        self::assertSame([], $rows[self::PLAIN_PLANET]['extractionBalance']);
    }

    public function testDetailCarriesTheSameExtractionBalance(): void
    {
        $detail = $this->detailOf($this->extractionColony);

        self::assertArrayHasKey('extractionBalance', $detail);
        $this->assertExpiredExtractorBalance($detail['extractionBalance']);
    }

    public function testDetailOfAColonyWithoutExtractionCarriesTheKeyEmpty(): void
    {
        $detail = $this->detailOf($this->plainColony);

        self::assertArrayHasKey('extractionBalance', $detail);
        self::assertSame([], $detail['extractionBalance']);
    }

    /**
     * @param list<array<string, mixed>> $extractionBalance
     */
    private function assertExpiredExtractorBalance(array $extractionBalance): void
    {
        self::assertCount(1, $extractionBalance);
        $row = $extractionBalance[0];
        self::assertSame(self::MICROORGANISMS, $row['typeId']);
        self::assertSame('Microorganisms', $row['typeName']);
        self::assertEqualsWithDelta(0.0, $row['extractedPerDay'], 0.0001);
        self::assertEqualsWithDelta(144000.0, $row['demandedPerDay'], 0.0001);
        self::assertEqualsWithDelta(-144000.0, $row['balancePerDay'], 0.0001);
        self::assertEqualsWithDelta(0.0, $row['runRate'], 0.0001);
        self::assertSame(1, $row['factoryCount']);
        self::assertSame('deficit', $row['advice']['kind']);
        self::assertArrayHasKey('heads', $row['advice']);
        self::assertNull($row['advice']['heads']);
        self::assertSame(1, $row['advice']['factories']);
    }

    private function seedSde(): void
    {
        $category = $this->em->find(InvCategory::class, self::CATEGORY_ID)
            ?? (new InvCategory())->setCategoryId(self::CATEGORY_ID)->setCategoryName('Planetary Infrastructure 1387');
        $this->em->persist($category);
        $commodities = $this->em->find(InvGroup::class, self::COMMODITIES_GROUP_ID)
            ?? (new InvGroup())->setGroupId(self::COMMODITIES_GROUP_ID)->setGroupName('Basic Commodities 1387')->setCategory($category);
        $this->em->persist($commodities);

        foreach ([
            self::EXTRACTOR_TYPE => 'Barren Extractor Control Unit',
            self::FACTORY_TYPE => 'Barren Basic Industry Facility',
            self::STORAGE_TYPE => 'Barren Storage Facility',
            self::MICROORGANISMS => 'Microorganisms',
            self::BIOFUELS => 'Biofuels',
        ] as $typeId => $typeName) {
            $this->em->persist(
                (new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($commodities)->setPublished(true),
            );
        }

        $schematic = (new PlanetSchematic())
            ->setSchematicId(self::BIOFUELS_SCHEMATIC)
            ->setSchematicName('Biofuels')
            ->setCycleTime(1800);
        $this->em->persist($schematic);
        $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId(self::MICROORGANISMS)->setIsInput(true)->setQuantity(3000));
        $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId(self::BIOFUELS)->setIsInput(false)->setQuantity(20));
        $this->em->flush();
    }

    private function newCharacter(int $eveCharacterId, string $name): Character
    {
        return (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_538_001)
            ->setCorporationName('Verhoeven Deep Core');
    }

    private function newColony(Character $character, int $planetId, string $planetName): PlanetaryColony
    {
        return (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId($planetId)
            ->setPlanetName($planetName)
            ->setPlanetType('barren')
            ->setSolarSystemId(30_001_538)
            ->setSolarSystemName('Aldranette')
            ->setUpgradeLevel(3)
            ->setNumPins(2)
            ->setLastUpdate(new \DateTimeImmutable(self::NOW));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listRowsByPlanet(): array
    {
        $this->client->loginUser($this->owner, 'api');
        $this->client->request('GET', '/api/planetary', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        $rows = array_column(
            json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR),
            null,
            'planetId',
        );
        self::assertArrayHasKey(self::EXTRACTION_PLANET, $rows);
        self::assertArrayHasKey(self::PLAIN_PLANET, $rows);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function detailOf(PlanetaryColony $colony): array
    {
        $this->client->loginUser($this->owner, 'api');
        $this->client->request('GET', '/api/planetary/'.$colony->getId()?->toRfc4122(), server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
