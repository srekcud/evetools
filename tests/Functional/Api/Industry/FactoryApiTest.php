<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Constant\EveConstants;
use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1314')]
final class FactoryApiTest extends WebTestCase
{
    use SeedsJitaOrderBooksAndCostIndices;

    private const int RAITARU_STRUCTURE_ID = 1_031_400_000_100;
    private const int REGION_ID = 10_000_002;
    private const int CONSTELLATION_ID = 20_000_020;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const string STATIONS_PATH = '/api/industry/factory/stations';
    private const string PROJECT_STOCK_PATH = '/api/industry/factory/project-stock';
    private const string MANUFACTURING_ME_RIG = 'Standup M-Set Basic Large Ship Manufacturing Material Efficiency I';
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $pilot;
    private User $otherPilot;
    private Character $aria;
    private IndustryStructureConfig $raitaru;
    private IndustryStructureConfig $unlocated;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->aria = $this->character(2_131_400_001, 'Aria Solberg');
        $this->pilot = $this->userOf($this->aria);
        $this->otherPilot = $this->userOf($this->character(2_131_400_002, 'Brann Holt'));

        $region = (new MapRegion())->setRegionId(self::REGION_ID)->setRegionName('The Forge');
        $constellation = (new MapConstellation())->setConstellationId(self::CONSTELLATION_ID)->setConstellationName('Kimotoro')->setRegion($region);
        $this->em->persist($region);
        $this->em->persist($constellation);
        $this->em->persist((new MapSolarSystem())->setSolarSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)->setSolarSystemName('Jita')->setConstellation($constellation)->setRegionId(self::REGION_ID));

        $this->raitaru = (new IndustryStructureConfig())
            ->setUser($this->pilot)
            ->setName('Raitaru Alpha')
            ->setStructureType('raitaru')
            ->setSecurityType('highsec')
            ->setLocationId(self::RAITARU_STRUCTURE_ID)
            ->setSolarSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)
            ->setRigs([self::MANUFACTURING_ME_RIG]);
        $this->unlocated = (new IndustryStructureConfig())
            ->setUser($this->pilot)
            ->setName('Unlocated Beta')
            ->setStructureType('athanor')
            ->setSecurityType('nullsec');
        $this->em->persist($this->raitaru);
        $this->em->persist($this->unlocated);
        $this->em->flush();
    }

    public function testStationsAnswersTheFactoryTableWithUnknownValuesSerializedAsNull(): void
    {
        $this->seedJitaCostIndex('manufacturing', 0.0412);
        $this->em->persist(
            (new CachedIndustryJob())
                ->setCharacter($this->aria)
                ->setJobId(1_314_001)
                ->setActivityId(1)
                ->setBlueprintTypeId(588)
                ->setProductTypeId(587)
                ->setRuns(3)
                ->setStatus('active')
                ->setStartDate(new \DateTimeImmutable('2026-11-20 10:00:00'))
                ->setEndDate(new \DateTimeImmutable('2026-11-24 10:00:00'))
                ->setStationId(self::RAITARU_STRUCTURE_ID),
        );
        $rifterBatch = $this->projectOf($this->pilot, 'Rifter batch', ProjectStatus::InProgress);
        $rifterBatch->addBomItem(
            (new IndustryProjectBomLine())
                ->setTypeId(587)
                ->setTypeName('Rifter')
                ->setRequiredQuantity(10)
                ->setIsJob(true)
                ->setJobGroup('final')
                ->setPlannedStructureId($this->raitaru->getId()->toRfc4122()),
        );
        $this->em->flush();

        $this->actAs($this->pilot);
        $this->client->request('GET', self::STATIONS_PATH, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        $stations = $this->responseJson();
        self::assertCount(2, $stations);

        [$raitaru, $unlocated] = $stations;
        self::assertSame($this->raitaru->getId()->toRfc4122(), $raitaru['id']);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $raitaru['id']);
        self::assertSame(
            ['Raitaru Alpha', 'raitaru', 'highsec', self::RAITARU_STRUCTURE_ID, EveConstants::JITA_SOLAR_SYSTEM_ID, 'Jita', ['manufacturing'], 0.0412, 1],
            [$raitaru['name'], $raitaru['structureType'], $raitaru['securityType'], $raitaru['locationId'], $raitaru['solarSystemId'], $raitaru['solarSystemName'], $raitaru['specialisations'], $raitaru['manufacturingCostIndex'], $raitaru['linesInUse']],
        );
        self::assertArrayHasKey('reactionCostIndex', $raitaru);
        self::assertNull($raitaru['reactionCostIndex']);
        self::assertSame([['characterId' => 2_131_400_001, 'characterName' => 'Aria Solberg', 'status' => 'granted']], $raitaru['access']);
        self::assertSame([['id' => $rifterBatch->getId()->toRfc4122(), 'name' => 'Rifter batch']], $raitaru['projects']);

        self::assertSame('Unlocated Beta', $unlocated['name']);
        foreach (['locationId', 'solarSystemId', 'solarSystemName', 'manufacturingCostIndex', 'reactionCostIndex', 'linesInUse'] as $unknownField) {
            self::assertArrayHasKey($unknownField, $unlocated);
            self::assertNull($unlocated[$unknownField], $unknownField);
        }
        self::assertSame([], $unlocated['specialisations']);
        self::assertSame([['characterId' => 2_131_400_001, 'characterName' => 'Aria Solberg', 'status' => 'unknown']], $unlocated['access']);
        self::assertSame([], $unlocated['projects']);
    }

    public function testStationsAreRefusedWithoutAuthentication(): void
    {
        $this->client->request('GET', self::STATIONS_PATH, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testProjectStockAnswersTheStockCommittedToTheProjectsOfTheUser(): void
    {
        $rifterBatch = $this->projectOf($this->pilot, 'Rifter batch', ProjectStatus::InProgress);
        $tritanium = $this->materialLine($rifterBatch, self::TRITANIUM_TYPE_ID, 'Tritanium', 100);
        $this->contribute($rifterBatch, $tritanium, 60, ContributionStatus::Approved);
        $this->contribute($rifterBatch, $tritanium, 40, ContributionStatus::Pending);
        $ishtarBatch = $this->projectOf($this->pilot, 'Ishtar batch', ProjectStatus::Completed);
        $this->contribute($ishtarBatch, $this->materialLine($ishtarBatch, self::PYERITE_TYPE_ID, 'Pyerite', 80), 95, ContributionStatus::Approved);
        $foreignBatch = $this->projectOf($this->otherPilot, 'Foreign batch', ProjectStatus::InProgress);
        $this->contribute($foreignBatch, $this->materialLine($foreignBatch, self::TRITANIUM_TYPE_ID, 'Tritanium', 500), 500, ContributionStatus::Approved);
        $this->em->flush();

        $this->actAs($this->pilot);
        $this->client->request('GET', self::PROJECT_STOCK_PATH, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        $lines = $this->responseJson();
        $rifterId = $rifterBatch->getId()->toRfc4122();
        $ishtarId = $ishtarBatch->getId()->toRfc4122();
        $byId = array_column($lines, null, 'id');
        self::assertEqualsCanonicalizing(
            [$rifterId.':34:reserved', $ishtarId.':35:consumed', $ishtarId.':35:returned'],
            array_keys($byId),
        );
        self::assertSame(
            [$rifterId, 'Rifter batch', 'in_progress', self::TRITANIUM_TYPE_ID, 'Tritanium', 'reserved', 60],
            $this->stockFields($byId[$rifterId.':34:reserved']),
        );
        self::assertSame(
            [$ishtarId, 'Ishtar batch', 'completed', self::PYERITE_TYPE_ID, 'Pyerite', 'consumed', 80],
            $this->stockFields($byId[$ishtarId.':35:consumed']),
        );
        self::assertSame(
            [$ishtarId, 'Ishtar batch', 'completed', self::PYERITE_TYPE_ID, 'Pyerite', 'returned', 15],
            $this->stockFields($byId[$ishtarId.':35:returned']),
        );
    }

    public function testProjectStockIsRefusedWithoutAuthentication(): void
    {
        $this->client->request('GET', self::PROJECT_STOCK_PATH, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    private function stockFields(array $line): array
    {
        return [$line['projectId'], $line['projectName'], $line['projectStatus'], $line['typeId'], $line['typeName'], $line['state'], $line['quantity']];
    }

    private function character(int $eveCharacterId, string $name): Character
    {
        return (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_131_400)
            ->setCorporationName('Solberg Industries');
    }

    private function userOf(Character $character): User
    {
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);

        return $user;
    }

    private function projectOf(User $owner, string $name, ProjectStatus $status): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($owner)->setName($name)->setStatus($status);
        $project->addMember((new IndustryProjectParticipant())->setUser($owner)->setRole(ParticipantRole::Owner)->setStatus(ParticipantStatus::Accepted));
        $this->em->persist($project);

        return $project;
    }

    private function materialLine(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName($typeName)->setRequiredQuantity($requiredQuantity)->setIsJob(false);
        $project->addBomItem($line);

        return $line;
    }

    private function contribute(IndustryProject $project, IndustryProjectBomLine $line, int $quantity, ContributionStatus $status): void
    {
        $project->addContribution(
            (new IndustryProjectContribution())
                ->setMember($project->getMembers()->first())
                ->setBomItem($line)
                ->setType(ContributionType::Material)
                ->setQuantity($quantity)
                ->setEstimatedValue(null)
                ->setStatus($status)
                ->setIsAutoDetected(false)
                ->setIsVerified(false),
        );
    }

    private function actAs(User $user): void
    {
        $this->client->getKernel()->boot();
        $this->client->loginUser($this->em->find(User::class, $user->getId()), 'api');
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
