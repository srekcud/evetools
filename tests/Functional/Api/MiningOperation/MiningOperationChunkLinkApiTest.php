<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\CorporationMiningExtraction;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-531')]
final class MiningOperationChunkLinkApiTest extends WebTestCase
{
    use PersistsMiningOperations;

    private const string EXTRACTIONS_ENDPOINT = '/api/mining/corporation-extractions';
    private const int PLANNED_BEATS_ARCHIVED_MOON_ID = 40_531_001;
    private const int ARCHIVED_BEATS_IN_PROGRESS_MOON_ID = 40_531_002;
    private const int COLLEAGUE_CORPORATION_MOON_ID = 40_531_003;
    private const int COLLEAGUE_ARCHIVED_MOON_ID = 40_531_004;
    private const int COLLEAGUE_REMOVED_MEMBER_MOON_ID = 40_531_005;
    private const int PENDING_MEMBER_MOON_ID = 40_531_006;
    private const int ACCEPTED_MEMBER_MOON_ID = 40_531_007;
    private const int LINKED_MOON_ID = 40_531_008;
    private const string LINKED_MOON_NAME = 'Ilyra V - Moon 4';
    private const string LINKED_STRUCTURE_NAME = 'Athanor Ilyra Prime';
    private const int LINKED_STRUCTURE_ID = 1_035_000_531_008;
    private const string LINKED_ARRIVAL = '2099-11-05T20:00:00+00:00';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $aria;
    private User $brann;
    private User $cyra;
    private array $chunks = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, ['Aria II']);
        $this->brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, ['Brann II']);
        $this->cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C2, ['Cyra II']);
        foreach ([
            self::PLANNED_BEATS_ARCHIVED_MOON_ID,
            self::ARCHIVED_BEATS_IN_PROGRESS_MOON_ID,
            self::COLLEAGUE_CORPORATION_MOON_ID,
            self::COLLEAGUE_ARCHIVED_MOON_ID,
            self::COLLEAGUE_REMOVED_MEMBER_MOON_ID,
            self::PENDING_MEMBER_MOON_ID,
            self::ACCEPTED_MEMBER_MOON_ID,
            self::LINKED_MOON_ID,
        ] as $moonId) {
            $this->chunks[$moonId] = $this->chunk($moonId);
        }
        $this->em->flush();
    }

    public function testChunkLinksTheMostRecentOperationByPlannedStartAmongTheOwnedActiveAndArchivedOnesAndCountsBoth(): void
    {
        $plannedChunk = $this->chunks[self::PLANNED_BEATS_ARCHIVED_MOON_ID];
        $this->operationOn($plannedChunk, $this->aria, 'Rock Hollow archived', '2099-11-01T20:00:00+00:00', MiningOperationStatus::Completed);
        $planned = $this->operationOn($plannedChunk, $this->aria, 'Rock Hollow evening', '2099-11-06T20:00:00+00:00', MiningOperationStatus::Planned);
        $archivedChunk = $this->chunks[self::ARCHIVED_BEATS_IN_PROGRESS_MOON_ID];
        $this->operationOn($archivedChunk, $this->aria, 'Deep Core running', '2099-11-03T20:00:00+00:00', MiningOperationStatus::InProgress);
        $settled = $this->operationOn($archivedChunk, $this->aria, 'Deep Core settled', '2099-11-05T20:00:00+00:00', MiningOperationStatus::Settled);
        $this->em->flush();

        $this->as($this->aria);
        $this->request('GET', self::EXTRACTIONS_ENDPOINT);

        $byPlannedStart = $this->extractionOfMoon(self::PLANNED_BEATS_ARCHIVED_MOON_ID);
        $byArchive = $this->extractionOfMoon(self::ARCHIVED_BEATS_IN_PROGRESS_MOON_ID);
        self::assertSame(
            [
                [['id' => $planned->getId()->toRfc4122(), 'label' => 'Rock Hollow evening', 'status' => 'planned'], 2],
                [['id' => $settled->getId()->toRfc4122(), 'label' => 'Deep Core settled', 'status' => 'settled'], 2],
            ],
            [
                [$byPlannedStart['linkedOperation'], $byPlannedStart['linkedOperationCount']],
                [$byArchive['linkedOperation'], $byArchive['linkedOperationCount']],
            ],
        );
    }

    public function testChunkLinksNothingForTheOperationsOfAColleagueWhateverTheirVisibilityOrArchiveState(): void
    {
        $this->operationOn($this->chunks[self::COLLEAGUE_CORPORATION_MOON_ID], $this->brann, 'Brann corporation planned', '2099-11-04T20:00:00+00:00', MiningOperationStatus::Planned, MiningOperationVisibility::Corporation);
        $this->operationOn($this->chunks[self::COLLEAGUE_ARCHIVED_MOON_ID], $this->brann, 'Brann corporation archived', '2099-11-02T20:00:00+00:00', MiningOperationStatus::Completed, MiningOperationVisibility::Corporation);
        $removed = $this->operationOn($this->chunks[self::COLLEAGUE_REMOVED_MEMBER_MOON_ID], $this->brann, 'Brann removed member', '2099-11-03T20:00:00+00:00', MiningOperationStatus::InProgress);
        $this->addMiningMembership($removed, $this->aria, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Removed, ['Aria II']);
        $this->em->flush();

        $this->as($this->aria);
        $this->request('GET', self::EXTRACTIONS_ENDPOINT);

        foreach ([self::COLLEAGUE_CORPORATION_MOON_ID, self::COLLEAGUE_ARCHIVED_MOON_ID, self::COLLEAGUE_REMOVED_MEMBER_MOON_ID] as $moonId) {
            $extraction = $this->extractionOfMoon($moonId);
            self::assertArrayHasKey('linkedOperation', $extraction);
            self::assertNull($extraction['linkedOperation']);
            self::assertArrayHasKey('linkedOperationCount', $extraction);
            self::assertSame(0, $extraction['linkedOperationCount']);
        }
    }

    public function testChunkLinksTheOperationOfAColleagueWhereTheUserIsAPendingOrAcceptedMemberAndCountsOnlyThoseOperations(): void
    {
        $pendingChunk = $this->chunks[self::PENDING_MEMBER_MOON_ID];
        $invited = $this->operationOn($pendingChunk, $this->brann, 'Brann invites Aria', '2099-11-04T20:00:00+00:00', MiningOperationStatus::Planned);
        $this->addMiningMembership($invited, $this->aria, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Aria II']);
        $this->operationOn($pendingChunk, $this->brann, 'Brann other fleet', '2099-11-07T20:00:00+00:00', MiningOperationStatus::Planned);
        $acceptedChunk = $this->chunks[self::ACCEPTED_MEMBER_MOON_ID];
        $joined = $this->operationOn($acceptedChunk, $this->brann, 'Brann fleet joined', '2099-11-05T20:00:00+00:00', MiningOperationStatus::InProgress);
        $this->addMiningMembership($joined, $this->aria, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Aria II']);
        $this->em->flush();

        $this->as($this->aria);
        $this->request('GET', self::EXTRACTIONS_ENDPOINT);

        $pending = $this->extractionOfMoon(self::PENDING_MEMBER_MOON_ID);
        $accepted = $this->extractionOfMoon(self::ACCEPTED_MEMBER_MOON_ID);
        self::assertSame(
            [
                [['id' => $invited->getId()->toRfc4122(), 'label' => 'Brann invites Aria', 'status' => 'planned'], 1],
                [['id' => $joined->getId()->toRfc4122(), 'label' => 'Brann fleet joined', 'status' => 'in_progress'], 1],
            ],
            [
                [$pending['linkedOperation'], $pending['linkedOperationCount']],
                [$accepted['linkedOperation'], $accepted['linkedOperationCount']],
            ],
        );
    }

    public function testOperationShowsItsChunkToAReaderOfTheChunkCorporationAndNothingToAParticipantOfAnotherCorporation(): void
    {
        $operation = $this->operationOn($this->chunks[self::LINKED_MOON_ID], $this->aria, 'Ilyra chunk fleet', '2099-11-05T20:00:00+00:00', MiningOperationStatus::Planned);
        $this->addMiningMembership($operation, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Cyra II']);
        $this->em->flush();
        $operationId = $operation->getId()->toRfc4122();

        $this->as($this->aria);
        $this->request('GET', '/api/mining-operations/' . $operationId);
        $seenByOwner = $this->responseJson();
        $this->as($this->cyra);
        $this->request('GET', '/api/mining-operations/' . $operationId);
        $seenByForeignParticipant = $this->responseJson();

        self::assertArrayHasKey('chunk', $seenByOwner);
        self::assertSame(
            [
                'id' => $this->chunks[self::LINKED_MOON_ID]->getId()->toRfc4122(),
                'moonId' => self::LINKED_MOON_ID,
                'moonName' => self::LINKED_MOON_NAME,
                'structureId' => self::LINKED_STRUCTURE_ID,
                'structureName' => self::LINKED_STRUCTURE_NAME,
                'chunkArrivalTime' => self::LINKED_ARRIVAL,
            ],
            $seenByOwner['chunk'],
        );
        self::assertArrayHasKey('chunkId', $seenByForeignParticipant);
        self::assertSame($this->chunks[self::LINKED_MOON_ID]->getId()->toRfc4122(), $seenByForeignParticipant['chunkId']);
        self::assertArrayHasKey('chunk', $seenByForeignParticipant);
        self::assertNull($seenByForeignParticipant['chunk']);
    }

    private function chunk(int $moonId): CorporationMiningExtraction
    {
        $isLinkedMoon = $moonId === self::LINKED_MOON_ID;
        $chunk = (new CorporationMiningExtraction())
            ->setCorporationId(self::C1)
            ->setStructureId($isLinkedMoon ? self::LINKED_STRUCTURE_ID : 1_035_000_000_000 + $moonId % 1000)
            ->setStructureName($isLinkedMoon ? self::LINKED_STRUCTURE_NAME : sprintf('Refinery %d', $moonId))
            ->setMoonId($moonId)
            ->setMoonName($isLinkedMoon ? self::LINKED_MOON_NAME : sprintf('Moon %d', $moonId))
            ->setExtractionStartTime(new \DateTimeImmutable('2099-11-02T18:00:00+00:00'))
            ->setChunkArrivalTime(new \DateTimeImmutable(self::LINKED_ARRIVAL))
            ->setNaturalDecayTime(new \DateTimeImmutable('2099-11-08T20:00:00+00:00'));
        $this->em->persist($chunk);

        return $chunk;
    }

    private function operationOn(
        CorporationMiningExtraction $chunk,
        User $owner,
        string $label,
        string $plannedStartAt,
        MiningOperationStatus $status,
        MiningOperationVisibility $visibility = MiningOperationVisibility::Private,
    ): MiningOperation {
        $ownerMain = $owner->getMainCharacter()?->getName();
        $operation = $this->persistMiningOperation($this->em, $owner, $label, $plannedStartAt, $status, $visibility, self::C1, [$ownerMain]);
        $operation->setChunk($chunk);

        return $operation;
    }

    private function extractionOfMoon(int $moonId): array
    {
        self::assertResponseStatusCodeSame(200);
        $matches = [];
        foreach ($this->responseJson()['corporations'] as $corporation) {
            if ($corporation['corporationId'] !== self::C1) {
                continue;
            }
            foreach ($corporation['extractions'] as $extraction) {
                if ($extraction['moonId'] === $moonId) {
                    $matches[] = $extraction;
                }
            }
        }
        self::assertCount(1, $matches);

        return $matches[0];
    }

    private function as(User $user): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $user->getId()), 'api');
    }

    private function request(string $method, string $uri): void
    {
        $this->client->request($method, $uri, server: ['HTTP_ACCEPT' => 'application/json']);
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
