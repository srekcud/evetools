<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Planetary;

use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\PlanetSchematic;
use App\Entity\Sde\PlanetSchematicType;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1398')]
final class PlanetaryProjectNeedsApiTest extends WebTestCase
{
    private const string PROJECT_NEEDS_URI = '/api/planetary/project-needs';

    private const int COMMODITIES_GROUP_ID = 996_042;
    private const int PLANETARY_CATEGORY_ID = 996_066;

    private const int BROADCAST_NODE = 996_001;
    private const int ROBOTICS = 996_002;
    private const int TRITANIUM = 996_003;

    private const int BROADCAST_NODE_SCHEMATIC = 996_001;
    private const int ROBOTICS_SCHEMATIC = 996_002;

    private const int JITA_STATION_ID = 60_003_760;
    private const int AMARR_STATION_ID = 60_008_494;

    private const int ALICE_MAIN_EVE_ID = 2_113_980_001;
    private const int ALICE_SECOND_EVE_ID = 2_113_980_002;
    private const int BOB_EVE_ID = 2_113_980_003;
    private const int CAROL_EVE_ID = 2_113_980_004;
    private const int DAN_EVE_ID = 2_113_980_005;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $alice;
    private User $bob;
    private User $carol;
    private User $dan;
    private IndustryProject $ishtarFleet;
    private IndustryProject $rattlesnakeBatch;
    private IndustryProject $carolSolo;
    private int $nextItemId = 1_139_800_000_001;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedSde();
        $this->alice = $this->persistPilot('Alice Tarn', self::ALICE_MAIN_EVE_ID);
        $aldo = $this->persistSecondCharacter($this->alice, 'Aldo Tarn', self::ALICE_SECOND_EVE_ID);
        $this->bob = $this->persistPilot('Bob Kess', self::BOB_EVE_ID);
        $this->carol = $this->persistPilot('Carol Vane', self::CAROL_EVE_ID);
        $this->dan = $this->persistPilot('Dan Orsk', self::DAN_EVE_ID);

        $this->persistProjects();

        $this->persistAsset($this->alice->getMainCharacter(), self::BROADCAST_NODE, 7, self::JITA_STATION_ID);
        $this->persistAsset($aldo, self::BROADCAST_NODE, 3, self::AMARR_STATION_ID);
        $this->persistAsset($this->bob->getMainCharacter(), self::BROADCAST_NODE, 90, self::JITA_STATION_ID);
        $this->persistAsset($this->dan->getMainCharacter(), self::TRITANIUM, 400, self::JITA_STATION_ID);
        $this->em->flush();
    }

    public function testTheProjectNeedsRequireAuthentication(): void
    {
        $this->client->request('GET', self::PROJECT_NEEDS_URI, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testOnlyMaterialLinesOfPlanetaryProductsLeftToProvideOnOpenProjectsOfAnAcceptedParticipantAreAskedAgainstTheStockOfAllTheCharacters(): void
    {
        $body = $this->read($this->alice);

        self::assertSame('project-needs', $body['id']);
        self::assertSame([self::BROADCAST_NODE, self::ROBOTICS], array_column($body['products'], 'typeId'));

        $broadcastNode = $this->productOf($body, self::BROADCAST_NODE);
        self::assertSame('Broadcast Node', $broadcastNode['typeName']);
        self::assertSame([11, 10, 1], [$broadcastNode['required'], $broadcastNode['stock'], $broadcastNode['missing']]);
        self::assertSame(
            [
                ['projectId' => $this->ishtarFleet->getId()->toRfc4122(), 'projectName' => 'Ishtar fleet', 'remaining' => 6],
                ['projectId' => $this->rattlesnakeBatch->getId()->toRfc4122(), 'projectName' => 'Rattlesnake batch', 'remaining' => 5],
            ],
            $broadcastNode['projects'],
        );

        $robotics = $this->productOf($body, self::ROBOTICS);
        self::assertSame('Robotics', $robotics['typeName']);
        self::assertSame([5, 0, 5], [$robotics['required'], $robotics['stock'], $robotics['missing']]);
        self::assertSame(
            [['projectId' => $this->ishtarFleet->getId()->toRfc4122(), 'projectName' => 'Ishtar fleet', 'remaining' => 5]],
            $robotics['projects'],
        );
    }

    public function testAnotherUserSeesTheirOwnProjectsAndTheirOwnStockAndNothingOfTheFirst(): void
    {
        $body = $this->read($this->bob);

        self::assertSame([self::BROADCAST_NODE], array_column($body['products'], 'typeId'));
        $broadcastNode = $this->productOf($body, self::BROADCAST_NODE);
        self::assertSame([505, 90, 415], [$broadcastNode['required'], $broadcastNode['stock'], $broadcastNode['missing']]);
        self::assertSame(['Rattlesnake batch', 'Sleeper batch', 'Solo batch'], array_column($broadcastNode['projects'], 'projectName'));
        self::assertSame([5, 200, 300], array_column($broadcastNode['projects'], 'remaining'));
    }

    public function testNullsAreSerializedAsExplicitNullsWhenThePersonalAssetsWereNeverSynchronized(): void
    {
        $body = $this->read($this->carol);

        self::assertCount(1, $body['products']);
        $robotics = $body['products'][0];
        self::assertSame(self::ROBOTICS, $robotics['typeId']);
        self::assertSame(10, $robotics['required']);
        self::assertArrayHasKey('stock', $robotics);
        self::assertNull($robotics['stock']);
        self::assertArrayHasKey('missing', $robotics);
        self::assertNull($robotics['missing']);
        self::assertSame(
            [['projectId' => $this->carolSolo->getId()->toRfc4122(), 'projectName' => 'Carol solo', 'remaining' => 10]],
            $robotics['projects'],
        );
    }

    public function testAUserWithoutAnyProjectGetsAnEmptyListEvenWithSynchronizedAssets(): void
    {
        $body = $this->read($this->dan);

        self::assertArrayHasKey('products', $body);
        self::assertSame([], $body['products']);
    }

    private function actAs(User $user): void
    {
        $this->client->getKernel()->boot();
        $this->client->loginUser($this->em->find(User::class, $user->getId()), 'api');
    }

    /**
     * @return array<string, mixed>
     */
    private function read(User $user): array
    {
        $this->actAs($user);
        $this->client->request('GET', self::PROJECT_NEEDS_URI, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function productOf(array $body, int $typeId): array
    {
        foreach ($body['products'] as $product) {
            if ($product['typeId'] === $typeId) {
                return $product;
            }
        }

        self::fail(\sprintf('No product for type %d', $typeId));
    }

    private function seedSde(): void
    {
        $category = (new InvCategory())->setCategoryId(self::PLANETARY_CATEGORY_ID)->setCategoryName('Planetary Commodities');
        $this->em->persist($category);
        $group = (new InvGroup())->setGroupId(self::COMMODITIES_GROUP_ID)->setGroupName('Specialized Commodities')->setCategory($category);
        $this->em->persist($group);

        $this->persistType(self::BROADCAST_NODE, 'Broadcast Node', $group);
        $this->persistType(self::ROBOTICS, 'Robotics', $group);
        $this->persistType(self::TRITANIUM, 'Tritanium', $group);

        $this->persistSchematic(self::BROADCAST_NODE_SCHEMATIC, [self::TRITANIUM => 40], self::BROADCAST_NODE);
        $this->persistSchematic(self::ROBOTICS_SCHEMATIC, [self::BROADCAST_NODE => 10], self::ROBOTICS);
        $this->em->flush();
    }

    private function persistType(int $typeId, string $typeName, InvGroup $group): void
    {
        $this->em->persist(
            (new InvType())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setGroup($group)
                ->setVolume(1.5)
                ->setPublished(true),
        );
    }

    /**
     * @param array<int, int> $inputs
     */
    private function persistSchematic(int $schematicId, array $inputs, int $outputTypeId): void
    {
        $schematic = (new PlanetSchematic())
            ->setSchematicId($schematicId)
            ->setSchematicName('Schematic '.$schematicId)
            ->setCycleTime(3600);
        $this->em->persist($schematic);
        foreach ($inputs as $typeId => $quantity) {
            $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($typeId)->setIsInput(true)->setQuantity($quantity));
        }
        $this->em->persist((new PlanetSchematicType())->setSchematic($schematic)->setTypeId($outputTypeId)->setIsInput(false)->setQuantity(1));
    }

    private function persistPilot(string $name, int $eveCharacterId): User
    {
        $user = new User();
        $this->em->persist($user);
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_539_801)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    private function persistSecondCharacter(User $user, string $name, int $eveCharacterId): Character
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98_539_801)
            ->setCorporationName('Verhoeven Deep Core');
        $user->addCharacter($character);
        $this->em->persist($character);
        $this->em->flush();

        return $character;
    }

    private function persistProjects(): void
    {
        $this->ishtarFleet = $this->projectOf($this->alice, 'Ishtar fleet', ProjectStatus::InProgress);
        $this->materialLine($this->ishtarFleet, self::BROADCAST_NODE, 'Broadcast Node', 10, 4);
        $this->jobLine($this->ishtarFleet, self::BROADCAST_NODE, 'Broadcast Node', 70);
        $this->materialLine($this->ishtarFleet, self::TRITANIUM, 'Tritanium', 500, 0);
        $this->materialLine($this->ishtarFleet, self::ROBOTICS, 'Robotics', 4, 1);
        $this->materialLine($this->ishtarFleet, self::ROBOTICS, 'Robotics', 2, 0);

        $this->rattlesnakeBatch = $this->projectOf($this->bob, 'Rattlesnake batch', ProjectStatus::Published);
        $this->memberOf($this->rattlesnakeBatch, $this->alice, ParticipantStatus::Accepted);
        $this->materialLine($this->rattlesnakeBatch, self::BROADCAST_NODE, 'Broadcast Node', 5, 0);

        $finished = $this->projectOf($this->alice, 'Finished fleet', ProjectStatus::Completed);
        $this->materialLine($finished, self::BROADCAST_NODE, 'Broadcast Node', 100, 0);

        $sleeperBatch = $this->projectOf($this->bob, 'Sleeper batch', ProjectStatus::InProgress);
        $this->memberOf($sleeperBatch, $this->alice, ParticipantStatus::Pending);
        $this->materialLine($sleeperBatch, self::BROADCAST_NODE, 'Broadcast Node', 200, 0);

        $soloBatch = $this->projectOf($this->bob, 'Solo batch', ProjectStatus::InProgress);
        $this->materialLine($soloBatch, self::BROADCAST_NODE, 'Broadcast Node', 300, 0);

        $this->carolSolo = $this->projectOf($this->carol, 'Carol solo', ProjectStatus::InProgress);
        $this->materialLine($this->carolSolo, self::ROBOTICS, 'Robotics', 12, 2);

        $this->em->flush();
    }

    private function projectOf(User $owner, string $name, ProjectStatus $status): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($owner)->setName($name)->setStatus($status);
        $project->addMember((new IndustryProjectParticipant())->setUser($owner)->setRole(ParticipantRole::Owner)->setStatus(ParticipantStatus::Accepted));
        $this->em->persist($project);

        return $project;
    }

    private function memberOf(IndustryProject $project, User $user, ParticipantStatus $status): void
    {
        $project->addMember((new IndustryProjectParticipant())->setUser($user)->setRole(ParticipantRole::Member)->setStatus($status));
    }

    private function materialLine(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity): void
    {
        $project->addBomItem(
            (new IndustryProjectBomLine())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setRequiredQuantity($requiredQuantity)
                ->setFulfilledQuantity($fulfilledQuantity)
                ->setIsJob(false),
        );
    }

    private function jobLine(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity): void
    {
        $project->addBomItem(
            (new IndustryProjectBomLine())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setRequiredQuantity($requiredQuantity)
                ->setIsJob(true)
                ->setJobGroup('final'),
        );
    }

    private function persistAsset(Character $character, int $typeId, int $quantity, int $locationId): void
    {
        $this->em->persist((new CachedAsset())
            ->setItemId($this->nextItemId++)
            ->setTypeId($typeId)
            ->setTypeName('Type '.$typeId)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName('Location '.$locationId)
            ->setLocationType('station')
            ->setLocationFlag('Hangar')
            ->setIsSingleton(false)
            ->setCharacter($character)
            ->setIsCorporationAsset(false));
    }
}
