<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\Sde\DgmAttributeType;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvMarketGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\Sde\PlanetSchematicType;
use App\Entity\User;
use App\Service\Planetary\ExtractorYieldCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1403')]
final class PlanetaryPlannerAccountApiTest extends WebTestCase
{
    private const int AQUEOUS_LIQUIDS = 990_010;
    private const int HEAVY_METALS = 990_012;
    private const int EXTRACTOR_TYPE_ID = 990_848;
    private const int DECAY_ATTRIBUTE_ID = 1683;
    private const int NOISE_ATTRIBUTE_ID = 1687;
    private const float DELTA = 0.0001;
    private const int ALICE_MAIN_EVE_ID = 2_113_903_001;
    private const int ALICE_SECOND_EVE_ID = 2_113_903_002;
    private const int BOB_EVE_ID = 2_113_903_003;
    private const int FACTORY_TYPE_ID = 991_501;
    private const int BASIC_SCHEMATIC = 991_401;
    private const int REFINED_SCHEMATIC = 991_402;
    private const int SECOND_REFINED_SCHEMATIC = 991_403;
    private const int SPECIALIZED_SCHEMATIC = 991_404;
    private const int ADVANCED_SCHEMATIC = 991_405;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $alice;
    private User $bob;
    private int $nextPlanetId = 40_139_001;
    private int $nextPinId = 1_039_000_000_001;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedExtractionFactors(0.012, 0.8);
        $this->alice = $this->persistPilot('Alice Tarn', self::ALICE_MAIN_EVE_ID);
        $this->persistSecondCharacter($this->alice, 'Aldo Tarn', self::ALICE_SECOND_EVE_ID);
        $this->bob = $this->persistPilot('Bob Kess', self::BOB_EVE_ID);
    }

    public function testTheAccountRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/planetary/planner/account', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheAccountCarriesTheColoniesTheSlotsAndTheExtractionYieldOfTheConnectedUser(): void
    {
        $install = new \DateTimeImmutable('2026-11-24T12:00:00+00:00');
        $this->persistColony($this->alice->getMainCharacter(), self::AQUEOUS_LIQUIDS, 4400, 1800, $install, $install->modify('+24 hours'));
        $this->persistSkill($this->alice->getMainCharacter(), CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION, 4);
        $this->client->loginUser($this->alice, 'api');

        $body = $this->getAccount();
        $expectedPerHour = self::getContainer()->get(ExtractorYieldCalculator::class)->programDailyAverage(4400, 1800, $install, $install->modify('+24 hours')) / 24;

        self::assertSame('account', $body['id']);
        self::assertSame(1, $body['usedPlanets']);
        self::assertSame([self::ALICE_SECOND_EVE_ID, self::ALICE_MAIN_EVE_ID], array_column($body['characters'], 'characterId'));
        self::assertSame(
            ['characterId' => self::ALICE_MAIN_EVE_ID, 'characterName' => 'Alice Tarn', 'usedPlanets' => 1, 'maxPlanets' => 5],
            array_column($body['characters'], null, 'characterId')[self::ALICE_MAIN_EVE_ID],
        );
        self::assertCount(1, $body['extractionYields']);
        self::assertSame(self::AQUEOUS_LIQUIDS, $body['extractionYields'][0]['typeId']);
        self::assertEqualsWithDelta(13_078.0883, $body['extractionYields'][0]['perHourPerPlanet'], self::DELTA);
        self::assertEqualsWithDelta($expectedPerHour, $body['extractionYields'][0]['perHourPerPlanet'], self::DELTA);
        self::assertSame(1, $body['extractionYields'][0]['planets']);
    }

    public function testAColonyWhoseExtractorHasNoInstallTimeIsLeftOutOfTheYields(): void
    {
        $install = new \DateTimeImmutable('2026-11-24T12:00:00+00:00');
        $this->persistColony($this->alice->getMainCharacter(), self::AQUEOUS_LIQUIDS, 4400, 1800, $install, $install->modify('+24 hours'));
        $this->persistColony($this->alice->getMainCharacter(), self::AQUEOUS_LIQUIDS, 4400, 1800, null, $install->modify('+24 hours'));
        $this->persistColony($this->alice->getMainCharacter(), self::HEAVY_METALS, 900, 3600, $install, null);
        $this->client->loginUser($this->alice, 'api');

        $body = $this->getAccount();

        self::assertSame(3, $body['usedPlanets']);
        self::assertCount(1, $body['extractionYields']);
        self::assertSame(self::AQUEOUS_LIQUIDS, $body['extractionYields'][0]['typeId']);
        self::assertSame(1, $body['extractionYields'][0]['planets']);
        self::assertEqualsWithDelta(13_078.0883, $body['extractionYields'][0]['perHourPerPlanet'], self::DELTA);
    }

    public function testACharacterWithoutTheConsolidationSkillInTheDatabaseHasANullMaximum(): void
    {
        $this->persistSkill($this->alice->getMainCharacter(), CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION, 4);
        $this->client->loginUser($this->alice, 'api');

        $characters = $this->getAccount()['characters'];

        $second = array_values(array_filter($characters, static fn (array $character): bool => $character['characterId'] === self::ALICE_SECOND_EVE_ID));
        self::assertCount(1, $second);
        self::assertArrayHasKey('maxPlanets', $second[0]);
        self::assertNull($second[0]['maxPlanets']);
    }

    public function testTheColoniesAndTheExtractionOfAnotherUserAreAbsent(): void
    {
        $install = new \DateTimeImmutable('2026-11-24T12:00:00+00:00');
        $this->persistColony($this->alice->getMainCharacter(), self::AQUEOUS_LIQUIDS, 4400, 1800, $install, $install->modify('+24 hours'));
        $this->persistColony($this->bob->getMainCharacter(), self::HEAVY_METALS, 900, 3600, $install, $install->modify('+24 hours'));
        $this->persistColony($this->bob->getMainCharacter(), self::HEAVY_METALS, 900, 3600, $install, $install->modify('+24 hours'));
        $this->persistSkill($this->bob->getMainCharacter(), CachedCharacterSkill::SKILL_INTERPLANETARY_CONSOLIDATION, 5);
        $this->client->loginUser($this->alice, 'api');

        $body = $this->getAccount();

        self::assertSame(1, $body['usedPlanets']);
        self::assertSame([self::AQUEOUS_LIQUIDS], array_column($body['extractionYields'], 'typeId'));
        self::assertNotContains(self::BOB_EVE_ID, array_column($body['characters'], 'characterId'));
    }

    public function testFactoriesCountsTheFactoriesOfTheConnectedUserByTierOfTheirProductAndNeverThoseOfAnotherUser(): void
    {
        $this->seedFactorySchematics();
        $install = new \DateTimeImmutable('2026-11-24T12:00:00+00:00');
        $this->persistFactoryColony($this->alice->getMainCharacter(), [self::REFINED_SCHEMATIC, self::SPECIALIZED_SCHEMATIC], true);
        $this->persistFactoryColony($this->alice->getMainCharacter(), [self::SECOND_REFINED_SCHEMATIC], false);
        $this->persistColony($this->alice->getMainCharacter(), self::AQUEOUS_LIQUIDS, 4400, 1800, $install, $install->modify('+24 hours'));
        $this->persistFactoryColony($this->bob->getMainCharacter(), [self::ADVANCED_SCHEMATIC, self::ADVANCED_SCHEMATIC, self::BASIC_SCHEMATIC, self::REFINED_SCHEMATIC], true);
        $this->client->loginUser($this->alice, 'api');

        $body = $this->getAccount();

        self::assertSame(['P1' => 0, 'P2' => 2, 'P3' => 1, 'P4' => 0], $body['factories']);
    }

    public function testFactoriesCountsEveryTierWithItsOwnNumberOfFactories(): void
    {
        $this->seedFactorySchematics();
        $this->persistFactoryColony($this->alice->getMainCharacter(), [self::BASIC_SCHEMATIC, self::BASIC_SCHEMATIC, self::BASIC_SCHEMATIC, self::REFINED_SCHEMATIC], false);
        $this->persistFactoryColony($this->alice->getMainCharacter(), [self::SPECIALIZED_SCHEMATIC, self::SPECIALIZED_SCHEMATIC, self::ADVANCED_SCHEMATIC, self::ADVANCED_SCHEMATIC, self::ADVANCED_SCHEMATIC, self::ADVANCED_SCHEMATIC, self::ADVANCED_SCHEMATIC], true);
        $this->client->loginUser($this->alice, 'api');

        $body = $this->getAccount();

        self::assertSame(['P1' => 3, 'P2' => 1, 'P3' => 2, 'P4' => 5], $body['factories']);
    }

    public function testFactoriesIsZeroInEveryTierWithoutAnyFactory(): void
    {
        $this->seedFactorySchematics();
        $install = new \DateTimeImmutable('2026-11-24T12:00:00+00:00');
        $this->persistColony($this->alice->getMainCharacter(), self::AQUEOUS_LIQUIDS, 4400, 1800, $install, $install->modify('+24 hours'));
        $this->persistFactoryColony($this->bob->getMainCharacter(), [self::ADVANCED_SCHEMATIC], false);
        $this->client->loginUser($this->alice, 'api');

        $body = $this->getAccount();

        self::assertArrayHasKey('factories', $body);
        self::assertSame(['P1' => 0, 'P2' => 0, 'P3' => 0, 'P4' => 0], $body['factories']);
    }

    /**
     * @return array<string, mixed>
     */
    private function getAccount(): array
    {
        $this->client->request('GET', '/api/planetary/planner/account', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function persistPilot(string $name, int $eveCharacterId): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_539_001)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function persistSecondCharacter(User $user, string $name, int $eveCharacterId): void
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_539_001)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $this->em->persist($character);
        $this->em->flush();
    }

    private function seedExtractionFactors(float $decayFactor, float $noiseFactor): void
    {
        foreach ([self::DECAY_ATTRIBUTE_ID => $decayFactor, self::NOISE_ATTRIBUTE_ID => $noiseFactor] as $attributeId => $defaultValue) {
            $attribute = $this->em->find(DgmAttributeType::class, $attributeId);
            if ($attribute === null) {
                $attribute = (new DgmAttributeType())->setAttributeId($attributeId);
                $this->em->persist($attribute);
            }
            $attribute->setDefaultValue($defaultValue);
        }
        $this->em->flush();
    }

    private function persistColony(Character $character, int $productTypeId, int $quantityPerCycle, int $cycleSeconds, ?\DateTimeImmutable $installTime, ?\DateTimeImmutable $expiryTime): void
    {
        $colony = (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId($this->nextPlanetId++)
            ->setPlanetType('temperate')
            ->setSolarSystemId(30_001_539)
            ->setUpgradeLevel(4)
            ->setNumPins(1)
            ->setLastUpdate(new \DateTimeImmutable('2026-11-25T12:00:00+00:00'));
        $colony->addPin((new PlanetaryPin())
            ->setPinId($this->nextPinId++)
            ->setTypeId(self::EXTRACTOR_TYPE_ID)
            ->setExtractorProductTypeId($productTypeId)
            ->setExtractorQtyPerCycle($quantityPerCycle)
            ->setExtractorCycleTime($cycleSeconds)
            ->setInstallTime($installTime)
            ->setExpiryTime($expiryTime));
        $this->em->persist($colony);
        $this->em->flush();
    }

    private function seedFactorySchematics(): void
    {
        $category = (new InvCategory())->setCategoryId(991_001)->setCategoryName('Test Follow Commodities');
        $group = (new InvGroup())->setGroupId(991_002)->setGroupName('Test Follow Products')->setCategory($category);
        $this->em->persist($category);
        $this->em->persist($group);
        $products = [
            self::BASIC_SCHEMATIC => [991_201, 1334, 991_301, 3_000],
            self::REFINED_SCHEMATIC => [991_202, 1335, 991_201, 40],
            self::SECOND_REFINED_SCHEMATIC => [991_203, 1335, 991_201, 30],
            self::SPECIALIZED_SCHEMATIC => [991_204, 1336, 991_202, 10],
            self::ADVANCED_SCHEMATIC => [991_205, 1337, 991_204, 6],
        ];
        foreach ($products as $schematicId => [$outputTypeId, $marketGroupId, $inputTypeId, $inputQuantity]) {
            $marketGroup = $this->em->find(InvMarketGroup::class, $marketGroupId);
            if ($marketGroup === null) {
                $marketGroup = (new InvMarketGroup())->setMarketGroupId($marketGroupId)->setMarketGroupName(sprintf('Test market group %d', $marketGroupId));
                $this->em->persist($marketGroup);
            }
            $this->em->persist((new InvType())->setTypeId($outputTypeId)->setTypeName(sprintf('Test product %d', $outputTypeId))->setGroup($group)->setVolume(0.19)->setMarketGroup($marketGroup));
            $schematic = (new PlanetSchematic())->setSchematicId($schematicId)->setSchematicName(sprintf('Test schematic %d', $schematicId))->setCycleTime(3_600);
            $this->em->persist($schematic);
            $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($inputTypeId)->setIsInput(true)->setQuantity($inputQuantity));
            $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($outputTypeId)->setIsInput(false)->setQuantity(5));
        }
        $this->em->flush();
    }

    private function persistFactoryColony(Character $character, array $schematicIds, bool $withLaunchpad): void
    {
        $colony = (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId($this->nextPlanetId++)
            ->setPlanetType('temperate')
            ->setSolarSystemId(30_001_539)
            ->setUpgradeLevel(4)
            ->setNumPins(\count($schematicIds))
            ->setLastUpdate(new \DateTimeImmutable('2026-11-25T12:00:00+00:00'));
        foreach ($schematicIds as $schematicId) {
            $colony->addPin((new PlanetaryPin())->setPinId($this->nextPinId++)->setTypeId(self::FACTORY_TYPE_ID)->setSchematicId($schematicId));
        }
        if ($withLaunchpad) {
            $colony->addPin((new PlanetaryPin())->setPinId($this->nextPinId++)->setTypeId(2_544));
        }
        $this->em->persist($colony);
        $this->em->flush();
    }

    private function persistSkill(Character $character, int $skillId, int $level): void
    {
        $this->em->persist((new CachedCharacterSkill())->setCharacter($character)->setSkillId($skillId)->setLevel($level)->setSkillPoints(256_000));
        $this->em->flush();
    }
}
