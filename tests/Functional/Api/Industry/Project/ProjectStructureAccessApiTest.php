<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CharacterStructureAccess;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\StructureAccessSource;
use App\Enum\StructureAccessStatus;
use App\Message\RefreshProjectStructureAccess;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[Group('issue-1299')]
final class ProjectStructureAccessApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const string UNKNOWN_PROJECT_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e52';
    private const int AZBEL_LOCATION_ID = 1_040_000_001_000;
    private const int JITA_FORTIZAR_STATION_ID = 60_003_760;
    private const int PERIMETER_SYSTEM_ID = 30_000_144;
    private const int JITA_SYSTEM_ID = 30_000_142;
    private const string JITA_FORTIZAR_NAME = 'Jita IV - Caldari Navy Assembly Plant';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $azbel = $this->structureConfig('Azbel', self::AZBEL_LOCATION_ID, self::PERIMETER_SYSTEM_ID, 'azbel');
        $fortizar = $this->structureConfig(self::JITA_FORTIZAR_NAME, self::JITA_FORTIZAR_STATION_ID, self::JITA_SYSTEM_ID, 'fortizar');
        $this->ramJobLine->setPlannedStructureId($azbel->getId()->toRfc4122());
        $this->ishtarJobLine->setPlannedStructureId($fortizar->getId()->toRfc4122());
        $this->addMembership($this->ishtarBatch, $this->ownerCorpmate, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->addMembership($this->ishtarBatch, $this->outsider, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->holdAccess($this->member, StructureAccessStatus::Granted);
        $this->holdAccess($this->ownerCorpmate, StructureAccessStatus::Denied);
        $this->holdAccess($this->pendingMember, StructureAccessStatus::Denied);
        $this->em->flush();
    }

    public function testOwnerReadsHowManyAcceptedMembersCanLaunchAreDeniedOrUnknownInEachStationOfThePlan(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/station-access');

        self::assertResponseStatusCodeSame(200);
        $stations = $this->rowsSortedBy('locationId', $this->responseJson());
        self::assertSame(
            [
                [self::JITA_FORTIZAR_STATION_ID, self::JITA_FORTIZAR_NAME, self::JITA_SYSTEM_ID, 4, 0, 0],
                [self::AZBEL_LOCATION_ID, 'Azbel', self::PERIMETER_SYSTEM_ID, 1, 1, 2],
            ],
            array_map(static fn (array $station): array => [$station['locationId'], $station['name'], $station['solarSystemId'], $station['canLaunch'], $station['denied'], $station['unknown']], $stations),
        );
        foreach ($stations as $station) {
            self::assertSame(['canLaunch', 'denied', 'locationId', 'name', 'solarSystemId', 'unknown'], $this->sortedKeysOf($station));
        }
        foreach (['Brann Holt', 'Corin Vale', 'Elia Varr', 'Dorn Achar', 'Aria Solberg'] as $characterName) {
            self::assertStringNotContainsString($characterName, (string) $this->client->getResponse()->getContent());
        }
    }

    public function testMemberWithoutManagementRightsIsRefusedTheCountsAndTheRefresh(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/station-access');

        self::assertResponseStatusCodeSame(403);

        $this->actAs($this->member);
        $this->request('POST', $this->projectUri($this->ishtarBatch).'/structure-access/refresh');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->queuedRefreshes());
    }

    public function testProjectIdentifierThatIsNotAUuidOrMatchesNoProjectIsNotFoundOnBothRoutes(): void
    {
        foreach ([self::NOT_A_UUID, self::UNKNOWN_PROJECT_ID] as $projectId) {
            $this->actAs($this->owner);
            $this->request('GET', '/api/industry/projects/'.$projectId.'/station-access');

            self::assertResponseStatusCodeSame(404);

            $this->actAs($this->owner);
            $this->request('POST', '/api/industry/projects/'.$projectId.'/structure-access/refresh');

            self::assertResponseStatusCodeSame(404);
        }
        self::assertSame([], $this->queuedRefreshes());
    }

    public function testBothRoutesAreRefusedWithoutConnectedUser(): void
    {
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/station-access');

        self::assertResponseStatusCodeSame(401);

        $this->request('POST', $this->projectUri($this->ishtarBatch).'/structure-access/refresh');

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->queuedRefreshes());
    }

    public function testOwnerQueuesTheRefreshAndGetsAnAcceptedAnswer(): void
    {
        $this->actAs($this->owner);
        $this->request('POST', $this->projectUri($this->ishtarBatch).'/structure-access/refresh');

        self::assertResponseStatusCodeSame(202);
        self::assertSame([$this->ishtarBatch->getId()->toRfc4122()], $this->queuedRefreshes());
    }

    private function structureConfig(string $name, int $locationId, int $solarSystemId, string $structureType): IndustryStructureConfig
    {
        $configuration = (new IndustryStructureConfig())
            ->setUser($this->owner)
            ->setName($name)
            ->setLocationId($locationId)
            ->setSolarSystemId($solarSystemId)
            ->setStructureType($structureType)
            ->setSecurityType('highsec')
            ->setRigs([]);
        $this->em->persist($configuration);

        return $configuration;
    }

    private function holdAccess(User $user, StructureAccessStatus $status): void
    {
        $this->em->persist(new CharacterStructureAccess($user->getMainCharacter(), self::AZBEL_LOCATION_ID, $status, StructureAccessSource::Esi, new \DateTimeImmutable()));
    }

    private function sortedKeysOf(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function queuedRefreshes(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $projectIds = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RefreshProjectStructureAccess) {
                $projectIds[] = $message->projectId;
            }
        }

        return $projectIds;
    }
}
