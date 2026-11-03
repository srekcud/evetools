<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\CorporationMiningExtraction;
use App\Entity\User;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-310')]
final class MiningOperationRecurrenceApiTest extends WebTestCase
{
    use PersistsMiningOperations;

    private const string LABEL = 'Jaspet de jeudi';
    private const string PLANNED_START = '2099-11-05T20:00:00+00:00';
    private const int ATHANOR_STRUCTURE_ID = 1_001;
    private const int FOREIGN_STRUCTURE_ID = 2_002;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $aria;
    private User $brann;
    private User $dorn;
    private User $actor;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, ['Aria II']);
        $this->brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1);
        $this->dorn = $this->persistMiningPilot($this->em, 'Dorn Achar', null);
        $this->extraction(self::C1, self::ATHANOR_STRUCTURE_ID, 'Athanor Ilyra', 91);
        $this->extraction(self::C2, self::FOREIGN_STRUCTURE_ID, 'Athanor Cyra', 92);
        $this->em->flush();
    }

    public function testAriaCreatesAWeeklyRecurrenceWithItsFirstOperationAndSeesItsNextOccurrence(): void
    {
        $this->actor = $this->aria;

        $this->request('POST', '/api/mining-operations', $this->creation(['kind' => 'weekly']));

        self::assertResponseStatusCodeSame(201);
        $created = $this->responseJson();
        self::assertIsString($created['recurrenceId'] ?? null);
        self::assertNull($created['chunkId'] ?? null);
        self::assertSame([self::C1, 'weekly'], [(int) $this->recurrenceRow($created['recurrenceId'])['corporation_id'], $this->recurrenceRow($created['recurrenceId'])['kind']]);
        $listed = $this->listedRecurrences();
        self::assertCount(1, $listed);
        self::assertSame(
            [$created['recurrenceId'], 'weekly', self::LABEL, null, '2099-11-12T20:00:00+00:00'],
            [$listed[0]['id'] ?? null, $listed[0]['kind'] ?? null, $listed[0]['label'] ?? null, $listed[0]['structureId'] ?? null, $listed[0]['nextOccurrenceAt'] ?? null],
        );
        self::assertArrayHasKey('createdAt', $listed[0]);
    }

    public function testAriaCreatesAChunkRecurrenceOnTheRefineryOfHerCorporationWithoutANextOccurrenceDate(): void
    {
        $this->actor = $this->aria;

        $this->request('POST', '/api/mining-operations', $this->creation(['kind' => 'chunk', 'structureId' => self::ATHANOR_STRUCTURE_ID]));

        self::assertResponseStatusCodeSame(201);
        $listed = $this->listedRecurrences();
        self::assertCount(1, $listed);
        self::assertSame(
            ['chunk', self::ATHANOR_STRUCTURE_ID, 'Athanor Ilyra', null],
            [$listed[0]['kind'] ?? null, $listed[0]['structureId'] ?? null, $listed[0]['structureName'] ?? null, $listed[0]['nextOccurrenceAt'] ?? null],
        );
    }

    public function testChunkRecurrenceOnARefineryOfAnotherCorporationIsRefusedAndNothingIsCreated(): void
    {
        $this->actor = $this->aria;

        $this->request('POST', '/api/mining-operations', $this->creation(['kind' => 'chunk', 'structureId' => self::FOREIGN_STRUCTURE_ID]));

        self::assertResponseStatusCodeSame(400);
        self::assertSame([0, 0], [$this->countOwnedBy('mining_operations', $this->aria), $this->countOwnedBy('mining_operation_recurrences', $this->aria)]);
    }

    public function testChunkRecurrenceWithoutAKnownCorporationIsRefused(): void
    {
        $this->actor = $this->dorn;

        $this->request('POST', '/api/mining-operations', ['visibility' => 'private'] + $this->creation(['kind' => 'chunk', 'structureId' => self::ATHANOR_STRUCTURE_ID], 'Dorn Achar'));

        self::assertResponseStatusCodeSame(400);
        self::assertSame([0, 0], [$this->countOwnedBy('mining_operations', $this->dorn), $this->countOwnedBy('mining_operation_recurrences', $this->dorn)]);
    }

    public function testCreationWithoutRecurrenceCreatesNoRecurrence(): void
    {
        $this->actor = $this->aria;

        $this->request('POST', '/api/mining-operations', $this->creation(null));

        self::assertResponseStatusCodeSame(201);
        $created = $this->responseJson();
        self::assertSame([null, null, 0], [$created['recurrenceId'] ?? null, $created['chunkId'] ?? null, $this->countOwnedBy('mining_operation_recurrences', $this->aria)]);
    }

    public function testBrannNeitherSeesNorStopsTheRecurrenceOfAria(): void
    {
        $recurrenceId = $this->ariaCreatesAWeeklyRecurrence();
        $this->actor = $this->brann;

        $seenByBrann = $this->listedRecurrences();
        $this->request('DELETE', '/api/mining-operations/recurrences/' . $recurrenceId);
        $statusOfBrannDelete = $this->client->getResponse()->getStatusCode();
        $this->actor = $this->aria;
        $this->request('DELETE', '/api/mining-operations/recurrences/' . $recurrenceId);

        self::assertSame([[], 404, 204], [$seenByBrann, $statusOfBrannDelete, $this->client->getResponse()->getStatusCode()]);
    }

    public function testAriaStopsHerRecurrenceAndItsOperationKeepsTheLink(): void
    {
        $recurrenceId = $this->ariaCreatesAWeeklyRecurrence();
        $this->actor = $this->aria;

        $this->request('DELETE', '/api/mining-operations/recurrences/' . $recurrenceId);

        self::assertResponseStatusCodeSame(204);
        self::assertNotNull($this->recurrenceRow($recurrenceId)['stopped_at'] ?? null);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mining_operations WHERE recurrence_id = ?', [$recurrenceId]));
        self::assertSame([], $this->listedRecurrences());
        $this->request('DELETE', '/api/mining-operations/recurrences/' . $recurrenceId);
        self::assertResponseStatusCodeSame(404);
    }

    private function listedRecurrences(): array
    {
        $this->request('GET', '/api/mining-operations/recurrences');
        self::assertResponseStatusCodeSame(200);

        return $this->responseJson();
    }

    private function ariaCreatesAWeeklyRecurrence(): string
    {
        $this->actor = $this->aria;
        $this->request('POST', '/api/mining-operations', $this->creation(['kind' => 'weekly']));

        $recurrenceId = $this->responseJson()['recurrenceId'] ?? null;
        self::assertIsString($recurrenceId);

        return $recurrenceId;
    }

    private function creation(?array $recurrence, string $characterName = 'Aria II'): array
    {
        $creation = [
            'label' => self::LABEL,
            'plannedStartAt' => self::PLANNED_START,
            'visibility' => 'corporation',
            'splitMode' => 'value_prorata',
            'characterIds' => [(string) $this->miningCharacters[$characterName]->getId()],
        ];

        return $recurrence === null ? $creation : $creation + ['recurrence' => $recurrence];
    }

    private function extraction(int $corporationId, int $structureId, string $structureName, int $moonId): void
    {
        $this->em->persist(
            (new CorporationMiningExtraction())
                ->setCorporationId($corporationId)
                ->setStructureId($structureId)
                ->setStructureName($structureName)
                ->setMoonId($moonId)
                ->setExtractionStartTime(new \DateTimeImmutable('2026-11-02T18:00:00+00:00'))
                ->setChunkArrivalTime(new \DateTimeImmutable('2099-11-05T20:00:00+00:00'))
                ->setNaturalDecayTime(new \DateTimeImmutable('2099-11-08T20:00:00+00:00')),
        );
    }

    private function recurrenceRow(string $recurrenceId): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM mining_operation_recurrences WHERE id = ?', [$recurrenceId]) ?: [];
    }

    private function countOwnedBy(string $table, User $owner): int
    {
        return (int) $this->em->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE owner_id = ?', $table), [(string) $owner->getId()]);
    }

    private function request(string $method, string $uri, ?array $body = null): void
    {
        $actorId = $this->actor->getId();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $actorId), 'api');
        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
