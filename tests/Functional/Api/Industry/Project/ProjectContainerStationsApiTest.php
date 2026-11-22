<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectContainer;
use App\Entity\IndustryStructureConfig;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1292')]
final class ProjectContainerStationsApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int ALPHA_LOCATION_ID = 60_003_760;
    private const int BETA_LOCATION_ID = 1_042_000_000_001;
    private const int GAMMA_LOCATION_ID = 60_008_494;
    private const int UNKNOWN_LOCATION_ID = 999;
    private const int ALPHA_SOLAR_SYSTEM_ID = 30_000_142;
    private const int BETA_SOLAR_SYSTEM_ID = 30_002_187;
    private const int GAMMA_SOLAR_SYSTEM_ID = 30_002_659;
    private const int CONTAINER_NAME_MAXIMUM_LENGTH = 255;
    private const string ALPHA_PROPOSED_NAME = 'Ishtar batch Alpha';
    private const string BETA_PROPOSED_NAME = 'Ishtar batch Beta';

    private IndustryStructureConfig $alpha;
    private IndustryStructureConfig $beta;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->alpha = $this->persistStructure('Alpha', self::ALPHA_LOCATION_ID, self::ALPHA_SOLAR_SYSTEM_ID);
        $this->beta = $this->persistStructure('Beta', self::BETA_LOCATION_ID, self::BETA_SOLAR_SYSTEM_ID);
        $this->persistStructure('Gamma', self::GAMMA_LOCATION_ID, self::GAMMA_SOLAR_SYSTEM_ID);
        $this->ishtarBatch->setWorkStations([
            'final_products' => ['structureId' => $this->alpha->getId()->toRfc4122(), 'recommendedStructureId' => null],
            'components' => ['structureId' => $this->beta->getId()->toRfc4122(), 'recommendedStructureId' => null],
        ]);
        $this->em->flush();
    }

    public function testAcceptedMemberReadsOneEntryPerStationOfThePlanWithAProposedNameAndNoContainer(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->containerStationsUri());

        self::assertResponseStatusCodeSame(200);
        $entries = $this->rowsSortedBy('structureName', $this->responseJson());
        self::assertSame(
            [
                [$this->alpha->getId()->toRfc4122(), 'Alpha', self::ALPHA_LOCATION_ID, self::ALPHA_SOLAR_SYSTEM_ID, self::ALPHA_PROPOSED_NAME],
                [$this->beta->getId()->toRfc4122(), 'Beta', self::BETA_LOCATION_ID, self::BETA_SOLAR_SYSTEM_ID, self::BETA_PROPOSED_NAME],
            ],
            array_map(static fn (array $entry): array => [$entry['structureId'], $entry['structureName'], $entry['locationId'], $entry['solarSystemId'], $entry['proposedName']], $entries),
        );
        foreach ($entries as $entry) {
            self::assertSame(['container', 'locationId', 'proposedName', 'solarSystemId', 'structureId', 'structureName'], $this->sortedKeys($entry));
            self::assertArrayHasKey('container', $entry);
            self::assertNull($entry['container']);
        }
    }

    public function testStationWithAContainerExposesItAndProposesNoName(): void
    {
        $container = $this->persistContainer(self::ALPHA_PROPOSED_NAME, self::ALPHA_LOCATION_ID);

        $this->actAs($this->member);
        $this->request('GET', $this->containerStationsUri());

        self::assertResponseStatusCodeSame(200);
        $alphaEntry = $this->rowsSortedBy('structureName', $this->responseJson())[0];
        self::assertSame(['id' => $container->getId()->toRfc4122(), 'name' => self::ALPHA_PROPOSED_NAME], $alphaEntry['container']);
        self::assertArrayHasKey('proposedName', $alphaEntry);
        self::assertNull($alphaEntry['proposedName']);
    }

    public function testProjectWithoutAnyPlanStructureListsNoStation(): void
    {
        $this->ishtarBatch->setWorkStations([]);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('GET', $this->containerStationsUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseJson());
    }

    public function testOwnerCreatesTheContainerOfAStationNamedAfterTheProposal(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->containersUri(), ['locationId' => self::ALPHA_LOCATION_ID]);

        self::assertResponseStatusCodeSame(200);
        $entry = $this->responseJson();
        self::assertSame([self::ALPHA_LOCATION_ID, 'Alpha', self::ALPHA_PROPOSED_NAME, null], [$entry['locationId'], $entry['structureName'], $entry['container']['name'], $entry['proposedName']]);
        self::assertSame(
            [[self::CONTAINER_NAME, null], [self::ALPHA_PROPOSED_NAME, self::ALPHA_LOCATION_ID]],
            $this->storedContainers(),
        );
        self::assertSame($this->storedContainerId(self::ALPHA_PROPOSED_NAME), $entry['container']['id']);
    }

    public function testOwnerCreatesTheContainerOfAStationWithAGivenName(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->containersUri(), ['locationId' => self::BETA_LOCATION_ID, 'name' => 'Mats']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Mats', $this->responseJson()['container']['name']);
        self::assertSame([[self::CONTAINER_NAME, null], ['Mats', self::BETA_LOCATION_ID]], $this->storedContainers());
    }

    public function testPostingTheNameOfAContainerWithoutLocationLocatesItInsteadOfDuplicatingIt(): void
    {
        $existingContainerId = $this->storedContainerId(self::CONTAINER_NAME);

        $this->actAs($this->owner);
        $this->request('POST', $this->containersUri(), ['locationId' => self::ALPHA_LOCATION_ID, 'name' => 'ishtar-206']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($existingContainerId, $this->responseJson()['container']['id']);
        self::assertSame([[self::CONTAINER_NAME, self::ALPHA_LOCATION_ID]], $this->storedContainers());
    }

    public function testAdministratorWhoIsNotTheOwnerCreatesTheContainerOfAStation(): void
    {
        $this->addMembership($this->ishtarBatch, $this->ownerCorpmate, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->em->flush();

        $this->actAs($this->ownerCorpmate);
        $this->request('POST', $this->containersUri(), ['locationId' => self::ALPHA_LOCATION_ID]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::ALPHA_PROPOSED_NAME, $this->responseJson()['container']['name']);
    }

    public function testStationThatAlreadyHasAContainerIsRefusedWith409(): void
    {
        $this->persistContainer('X', self::ALPHA_LOCATION_ID);

        $this->actAs($this->owner);
        $this->request('POST', $this->containersUri(), ['locationId' => self::ALPHA_LOCATION_ID]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame([[self::CONTAINER_NAME, null], ['X', self::ALPHA_LOCATION_ID]], $this->storedContainers());
    }

    public function testNameTakenByAContainerOfAnotherStationIsRefusedWith409(): void
    {
        $this->persistContainer('Mats', self::BETA_LOCATION_ID);

        $this->actAs($this->owner);
        $this->request('POST', $this->containersUri(), ['locationId' => self::ALPHA_LOCATION_ID, 'name' => 'Mats']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame([[self::CONTAINER_NAME, null], ['Mats', self::BETA_LOCATION_ID]], $this->storedContainers());
    }

    public function testLocationThatIsNotAStationOfThePlanIsRefusedWith422(): void
    {
        foreach ([self::UNKNOWN_LOCATION_ID, self::GAMMA_LOCATION_ID] as $locationId) {
            $this->actAs($this->owner);
            $this->request('POST', $this->containersUri(), ['locationId' => $locationId]);

            self::assertResponseStatusCodeSame(422);
            self::assertSame([422, 'Location is not a station of the plan'], $this->errorDetail());
        }
        self::assertSame([[self::CONTAINER_NAME, null]], $this->storedContainers());
    }

    public function testBodyWithoutLocationIdIsRefusedWith422(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->containersUri(), ['name' => 'Mats']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([[self::CONTAINER_NAME, null]], $this->storedContainers());
    }

    public function testNonIntegerOrNonPositiveLocationIdIsRefusedWith422(): void
    {
        foreach (['abc', 0, -5, 100.5] as $locationId) {
            $this->actAs($this->owner);
            $this->request('POST', $this->containersUri(), ['locationId' => $locationId]);

            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame([[self::CONTAINER_NAME, null]], $this->storedContainers());
    }

    public function testNameLongerThan255CharactersIsRefusedWith422AndTheMaximumIsAccepted(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->containersUri(), ['locationId' => self::ALPHA_LOCATION_ID, 'name' => str_repeat('N', self::CONTAINER_NAME_MAXIMUM_LENGTH + 1)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([[self::CONTAINER_NAME, null]], $this->storedContainers());

        $this->actAs($this->owner);
        $this->request('POST', $this->containersUri(), ['locationId' => self::ALPHA_LOCATION_ID, 'name' => str_repeat('N', self::CONTAINER_NAME_MAXIMUM_LENGTH)]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(str_repeat('N', self::CONTAINER_NAME_MAXIMUM_LENGTH), $this->responseJson()['container']['name']);
    }

    public function testCompletedProjectRefusesTheContainerWith409(): void
    {
        $this->ishtarBatch->setStatus(ProjectStatus::Completed);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('POST', $this->containersUri(), ['locationId' => self::ALPHA_LOCATION_ID]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame([[self::CONTAINER_NAME, null]], $this->storedContainers());
    }

    public function testAcceptedMemberWhoIsNeitherOwnerNorAdministratorCannotCreateAContainer(): void
    {
        $this->actAs($this->member);
        $this->request('POST', $this->containersUri(), ['locationId' => self::ALPHA_LOCATION_ID]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([[self::CONTAINER_NAME, null]], $this->storedContainers());
    }

    public function testPilotOutsideTheProjectIsRefusedTheStationsAndTheContainerCreation(): void
    {
        $this->actAs($this->outsider);
        $this->request('GET', $this->containerStationsUri());
        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());

        $this->actAs($this->outsider);
        $this->request('POST', $this->containersUri(), ['locationId' => self::ALPHA_LOCATION_ID]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([[self::CONTAINER_NAME, null]], $this->storedContainers());
    }

    private function persistStructure(string $name, int $locationId, int $solarSystemId): IndustryStructureConfig
    {
        $structure = (new IndustryStructureConfig())
            ->setUser($this->owner)
            ->setName($name)
            ->setStructureType('raitaru')
            ->setSecurityType('highsec')
            ->setLocationId($locationId)
            ->setSolarSystemId($solarSystemId)
            ->setRigs([]);
        $this->em->persist($structure);

        return $structure;
    }

    private function persistContainer(string $name, int $locationId): IndustryProjectContainer
    {
        $container = (new IndustryProjectContainer($this->ishtarBatch, $name))->locateAt($locationId);
        $this->ishtarBatch->getContainers()->add($container);
        $this->em->persist($container);
        $this->em->flush();

        return $container;
    }

    private function containerStationsUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/container-stations';
    }

    private function containersUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/containers';
    }

    private function storedContainers(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT name, location_id FROM industry_project_containers WHERE project_id = :projectId ORDER BY name COLLATE "C"',
            ['projectId' => $this->ishtarBatch->getId()->toRfc4122()],
        );

        return array_map(static fn (array $row): array => [$row['name'], null === $row['location_id'] ? null : (int) $row['location_id']], $rows);
    }

    private function storedContainerId(string $name): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT id FROM industry_project_containers WHERE project_id = :projectId AND name = :name',
            ['projectId' => $this->ishtarBatch->getId()->toRfc4122(), 'name' => $name],
        );
    }

    private function sortedKeys(array $entry): array
    {
        $keys = array_keys($entry);
        sort($keys);

        return $keys;
    }
}
