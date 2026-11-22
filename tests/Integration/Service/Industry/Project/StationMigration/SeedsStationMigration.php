<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry\Project\StationMigration;

use App\Entity\CachedAsset;
use App\Entity\CorpAssetVisibility;
use App\Entity\EveToken;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContainer;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Service\ESI\EsiClient;
use Symfony\Component\Uid\Uuid;

trait SeedsStationMigration
{
    private const int CORPORATION_ID = 98_132_001;
    private const int STATION_A = 60_003_760;
    private const int STATION_B = 60_008_494;
    private const int STATION_C = 60_011_866;
    private const int SHIP_CATEGORY_ID = 6;
    private const int MODULE_CATEGORY_ID = 7;
    private const int FRIGATE_GROUP_ID = 25;
    private const int COMPONENT_GROUP_ID = 334;
    private const int HULL_TYPE_ID = 11_999;
    private const int COMPONENT_TYPE_ID = 11_536;
    private const int FINAL_PRODUCT_TYPE_ID = 11_184;
    private const string FIRST_CONTAINER_NAME = 'Ishtar';
    private const string SECOND_CONTAINER_NAME = 'Fuel Depot';

    private Uuid $ownerId;
    private string $azbelAId;
    private string $sotiyoBId;
    private string $athanorCId;
    private string $deletedAzbelId;
    private string $foreignAzbelId;
    private int $nextAssetItemId = 1_132_000_001_000;

    private array $officeItemIdByStation = [];

    private array $projectIds = [];

    private array $containerIds = [];

    private function seedStationMigrationWorld(): void
    {
        $this->replaceEsiWithADirector();
        $owner = $this->createCorporationMember('Station Owner', self::CORPORATION_ID, true);
        $this->ownerId = $owner->getId();
        $this->em->persist((new CorpAssetVisibility())
            ->setCorporationId(self::CORPORATION_ID)
            ->setVisibleDivisions([2])
            ->setConfiguredBy($owner));

        $this->azbelAId = $this->storeStructure($owner, 'Azbel A', 'azbel', self::STATION_A, false);
        $this->sotiyoBId = $this->storeStructure($owner, 'Sotiyo B', 'sotiyo', self::STATION_B, false);
        $this->athanorCId = $this->storeStructure($owner, 'Athanor C', 'athanor', self::STATION_C, false);
        $this->deletedAzbelId = $this->storeStructure($owner, 'Deleted Azbel', 'azbel', self::STATION_A, true);
        $this->foreignAzbelId = $this->storeStructure($this->createUser(), 'Foreign Azbel', 'azbel', self::STATION_A, false);

        $ships = (new InvCategory())->setCategoryId(self::SHIP_CATEGORY_ID)->setCategoryName('Ship');
        $modules = (new InvCategory())->setCategoryId(self::MODULE_CATEGORY_ID)->setCategoryName('Module');
        $frigates = (new InvGroup())->setGroupId(self::FRIGATE_GROUP_ID)->setGroupName('Frigate')->setCategory($ships);
        $components = (new InvGroup())->setGroupId(self::COMPONENT_GROUP_ID)->setGroupName('Component')->setCategory($modules);
        foreach ([$ships, $modules, $frigates, $components] as $sdeEntity) {
            $this->em->persist($sdeEntity);
        }
        $this->em->persist((new InvType())->setTypeId(self::HULL_TYPE_ID)->setTypeName('Test Hull')->setGroup($frigates)->setVolume(2_500.0));
        foreach (range(0, 3) as $componentIndex) {
            $this->em->persist((new InvType())->setTypeId(self::COMPONENT_TYPE_ID + $componentIndex)->setTypeName('Test Component '.$componentIndex)->setGroup($components)->setVolume(1.0));
        }
        $this->flushAndClear();
    }

    private function owner(): User
    {
        return $this->em->getReference(User::class, $this->ownerId);
    }

    private function replaceEsiWithADirector(): void
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturn(['roles' => ['Director']]);
        self::getContainer()->set(EsiClient::class, $esiClient);
    }

    private function createCorporationMember(string $name, int $corporationId, bool $withDirectorToken): User
    {
        $character = $this->createCharacter(name: $name);
        $character->setCorporationId($corporationId);
        $user = $character->getUser();
        \assert($user instanceof User);
        $user->setMainCharacter($character);
        if ($withDirectorToken) {
            $token = (new EveToken())
                ->setAccessToken('access-token')
                ->setRefreshTokenEncrypted('encrypted-refresh-token')
                ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
                ->setScopes(['esi-assets.read_corporation_assets.v1', 'esi-characters.read_corporation_roles.v1']);
            $character->setEveToken($token);
            $this->em->persist($token);
        }
        $this->em->flush();

        return $user;
    }

    private function storeStructure(User $user, string $name, string $structureType, int $locationId, bool $deleted): string
    {
        $structure = (new IndustryStructureConfig())
            ->setUser($user)
            ->setName($name)
            ->setLocationId($locationId)
            ->setSecurityType('nullsec')
            ->setStructureType($structureType)
            ->setIsDeleted($deleted);
        $this->em->persist($structure);

        return $structure->getId()->toRfc4122();
    }

    private function newProject(?string $name = 'Hull run', ProjectStatus $status = ProjectStatus::InProgress, ?User $owner = null): IndustryProject
    {
        $project = (new IndustryProject())
            ->setOwner($owner ?? $this->owner())
            ->setName($name)
            ->setStatus($status);
        $this->em->persist($project);

        return $project;
    }

    private function addJobLine(
        IndustryProject $project,
        int $typeId,
        string $jobGroup,
        string $activityType,
        ?string $plannedStructureId,
        int $requiredQuantity = 120,
        int $runs = 6,
    ): IndustryProjectBomLine {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Line ' . $typeId)
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(true)
            ->setJobGroup($jobGroup)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setPlannedStructureId($plannedStructureId);
        $project->addBomItem($line);
        $this->em->persist($line);

        return $line;
    }

    private function addMaterialLine(IndustryProject $project, int $typeId, int $requiredQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Material ' . $typeId)
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(false);
        $project->addBomItem($line);
        $this->em->persist($line);

        return $line;
    }

    private function addComponentLines(IndustryProject $project, ?string ...$plannedStructureIds): void
    {
        foreach ($plannedStructureIds as $index => $plannedStructureId) {
            $this->addJobLine($project, self::COMPONENT_TYPE_ID + $index, 'component', 'manufacturing', $plannedStructureId, 100 + 30 * $index, 3 + $index);
        }
    }

    private function addTargetProduct(IndustryProject $project, int $typeId): void
    {
        $item = (new IndustryProjectProduct())
            ->setTypeId($typeId)
            ->setTypeName('Product ' . $typeId)
            ->setMeLevel(10)
            ->setTeLevel(20)
            ->setRuns(4)
            ->setSortOrder(0);
        $project->addItem($item);
        $this->em->persist($item);
    }

    private function addContainer(IndustryProject $project, string $name, ?int $locationId = null): string
    {
        $container = null === $locationId
            ? new IndustryProjectContainer($project, $name)
            : (new IndustryProjectContainer($project, $name))->locateAt($locationId);
        $project->getContainers()->add($container);
        $this->em->persist($container);

        return $container->getId()->toRfc4122();
    }

    private function cacheOfficeAt(int $stationLocationId): int
    {
        if (isset($this->officeItemIdByStation[$stationLocationId])) {
            return $this->officeItemIdByStation[$stationLocationId];
        }

        $officeItemId = $this->nextAssetItemId++;
        $this->persistAsset($officeItemId, 27, 'Office', $stationLocationId, 'station', 'OfficeFolder', null, null);
        $this->officeItemIdByStation[$stationLocationId] = $officeItemId;

        return $officeItemId;
    }

    private function cacheContainerAt(int $stationLocationId, string $name, string $flag = 'CorpSAG2'): void
    {
        $this->persistAsset($this->nextAssetItemId++, 17_366, 'Station Container', $this->cacheOfficeAt($stationLocationId), 'item', $flag, 'Division ' . substr($flag, -1), $name);
    }

    private function cacheContainerWithUncachedOffice(string $name): void
    {
        $this->persistAsset($this->nextAssetItemId++, 17_366, 'Station Container', 1_132_999_999_999, 'item', 'CorpSAG2', 'Division 2', $name);
    }

    private function persistAsset(int $itemId, int $typeId, string $typeName, int $locationId, string $locationType, string $locationFlag, ?string $divisionName, ?string $itemName): void
    {
        $this->em->persist((new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity(1)
            ->setLocationId($locationId)
            ->setLocationName('Test Station')
            ->setLocationType($locationType)
            ->setLocationFlag($locationFlag)
            ->setDivisionName($divisionName)
            ->setItemName($itemName)
            ->setSolarSystemId(30_000_142)
            ->setSolarSystemName('Jita')
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationAsset(true));
    }

    private function reloadedProject(IndustryProject|string $project): IndustryProject
    {
        $id = \is_string($project) ? $project : $project->getId()->toRfc4122();
        $reloaded = $this->em->find(IndustryProject::class, $id);
        \assert($reloaded instanceof IndustryProject);

        return $reloaded;
    }

    private function reloadedContainer(string $containerId): IndustryProjectContainer
    {
        $reloaded = $this->em->find(IndustryProjectContainer::class, $containerId);
        \assert($reloaded instanceof IndustryProjectContainer);

        return $reloaded;
    }

    private function databaseState(): array
    {
        $connection = $this->em->getConnection();

        return [
            'projects' => $connection->fetchAllAssociative('SELECT id, name, status, work_stations FROM industry_projects ORDER BY id'),
            'containers' => $connection->fetchAllAssociative('SELECT id, project_id, name, location_id FROM industry_project_containers ORDER BY id'),
            'bomLines' => $connection->fetchAllAssociative('SELECT id, type_id, required_quantity, fulfilled_quantity, runs, planned_structure_id, planned_structure_status, is_job FROM industry_project_bom_lines ORDER BY id'),
        ];
    }

    private function workStationOf(string $structureId): array
    {
        return ['structureId' => $structureId, 'recommendedStructureId' => null];
    }

    private function seedProjectsOfEveryStatus(): void
    {
        $completed = $this->newProject('Delivered fleet', ProjectStatus::Completed);
        $this->addComponentLines($completed, $this->azbelAId, $this->azbelAId);
        $this->containerIds['completed'] = $this->addContainer($completed, 'Delivered Box');

        $sotiyo = $this->newProject('Sotiyo run', ProjectStatus::Published);
        $this->addComponentLines($sotiyo, $this->sotiyoBId);
        $this->containerIds['sotiyo'] = $this->addContainer($sotiyo, self::SECOND_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_B, self::SECOND_CONTAINER_NAME);

        $ishtar = $this->newProject('Ishtar fleet', ProjectStatus::InProgress);
        $this->addComponentLines($ishtar, $this->azbelAId, $this->azbelAId, $this->azbelAId);
        $this->addJobLine($ishtar, 16_634, 'component', 'reaction', null, 80, 2);
        $this->containerIds['ishtar'] = $this->addContainer($ishtar, self::FIRST_CONTAINER_NAME);
        $this->cacheContainerAt(self::STATION_A, self::FIRST_CONTAINER_NAME);

        $draft = $this->newProject('Draft idea', ProjectStatus::Draft);

        $this->flushAndClear();
        $this->projectIds = [
            'completed' => $completed->getId()->toRfc4122(),
            'sotiyo' => $sotiyo->getId()->toRfc4122(),
            'ishtar' => $ishtar->getId()->toRfc4122(),
            'draft' => $draft->getId()->toRfc4122(),
        ];
        $createdAtByProject = ['draft' => '2025-12-01 08:00:00', 'ishtar' => '2026-01-01 08:00:00', 'sotiyo' => '2026-02-01 08:00:00', 'completed' => '2026-03-01 08:00:00'];
        foreach ($createdAtByProject as $key => $createdAt) {
            $this->em->getConnection()->executeStatement('UPDATE industry_projects SET created_at = :createdAt WHERE id = :id', ['createdAt' => $createdAt, 'id' => $this->projectIds[$key]]);
        }
    }
}
