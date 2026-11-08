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

#[Group('issue-274')]
final class MiningOperationChunkApiTest extends WebTestCase
{
    use PersistsMiningOperations;

    private const string LABEL = 'Chunk de Rock Hollow';
    private const string PLANNED_START = '2099-11-05T20:00:00+00:00';
    private const string UNKNOWN_CHUNK_ID = '0195f3a0-2740-7000-8000-000000000499';
    private const int ILYRA_REFINERY_ID = 1_035_000_000_101;
    private const int CYRA_REFINERY_ID = 1_035_000_000_102;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $aria;
    private string $ilyraChunkId;
    private string $cyraChunkId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, ['Aria II']);
        $ilyraChunk = $this->chunk(self::C1, self::ILYRA_REFINERY_ID, 40_301_101);
        $cyraChunk = $this->chunk(self::C2, self::CYRA_REFINERY_ID, 40_301_102);
        $this->em->flush();
        $this->ilyraChunkId = (string) $ilyraChunk->getId();
        $this->cyraChunkId = (string) $cyraChunk->getId();
    }

    public function testAriaCreatesAnOperationFromTheChunkOfHerCorporationAndTheOperationIsLinkedToIt(): void
    {
        $this->request($this->creation($this->ilyraChunkId));

        self::assertResponseStatusCodeSame(201);
        $created = $this->responseJson();
        self::assertArrayHasKey('chunkId', $created);
        self::assertSame($this->ilyraChunkId, $created['chunkId']);
        self::assertSame(
            [$this->ilyraChunkId],
            $this->em->getConnection()->fetchFirstColumn('SELECT chunk_id FROM mining_operations WHERE id = ?', [$created['id']]),
        );
    }

    public function testChunkOfAnotherCorporationUnknownChunkAndMalformedChunkIdCreateNoOperation(): void
    {
        $this->request($this->creation($this->cyraChunkId));
        $statusOfForeignChunk = $this->client->getResponse()->getStatusCode();
        $this->request($this->creation(self::UNKNOWN_CHUNK_ID));
        $statusOfUnknownChunk = $this->client->getResponse()->getStatusCode();
        $this->request($this->creation('not-a-uuid'));

        self::assertSame(
            [404, 404, 422, 0],
            [$statusOfForeignChunk, $statusOfUnknownChunk, $this->client->getResponse()->getStatusCode(), (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mining_operations')],
        );
    }

    private function chunk(int $corporationId, int $structureId, int $moonId): CorporationMiningExtraction
    {
        $chunk = (new CorporationMiningExtraction())
            ->setCorporationId($corporationId)
            ->setStructureId($structureId)
            ->setStructureName(sprintf('Refinery %d', $structureId))
            ->setMoonId($moonId)
            ->setExtractionStartTime(new \DateTimeImmutable('2099-11-02T18:00:00+00:00'))
            ->setChunkArrivalTime(new \DateTimeImmutable('2099-11-05T20:00:00+00:00'))
            ->setNaturalDecayTime(new \DateTimeImmutable('2099-11-08T20:00:00+00:00'));
        $this->em->persist($chunk);

        return $chunk;
    }

    private function creation(string $chunkId): array
    {
        return [
            'label' => self::LABEL,
            'plannedStartAt' => self::PLANNED_START,
            'visibility' => 'corporation',
            'splitMode' => 'value_prorata',
            'characterIds' => [(string) $this->miningCharacters['Aria II']->getId()],
            'chunkId' => $chunkId,
        ];
    }

    private function request(array $body): void
    {
        $actorId = $this->aria->getId();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $actorId), 'api');
        $this->client->request(
            'POST',
            '/api/mining-operations',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
