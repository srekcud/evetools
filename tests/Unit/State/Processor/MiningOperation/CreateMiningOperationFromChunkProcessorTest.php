<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\MiningOperation\CreateMiningOperationInput;
use App\Entity\CorporationMiningExtraction;
use App\Entity\MiningOperation;
use App\Entity\User;
use App\Repository\CorporationMiningExtractionRepository;
use App\Service\MiningOperation\MiningBoostFit;
use App\Service\MiningOperation\MiningOperationChunkResolver;
use App\Service\MiningOperation\MiningOperationEnrolment;
use App\Service\MiningOperation\MiningPresenceResolver;
use App\Service\MiningOperation\MountableCompressorCatalog;
use App\State\Processor\MiningOperation\CreateMiningOperationProcessor;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CreateMiningOperationProcessor::class)]
#[CoversClass(MiningOperationChunkResolver::class)]
#[Group('issue-274')]
#[AllowMockObjectsWithoutExpectations]
final class CreateMiningOperationFromChunkProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;

    private const string NOW = '2026-11-05 19:00:00';
    private const string CHUNK_ID = '0195f3a0-2740-7000-8000-000000000401';
    private const int REFINERY_ID = 1_035_000_000_401;
    private const int MOON_ID = 40_301_401;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private CreateMiningOperationProcessor $processor;
    private array $storedChunks = [];

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->security = $this->createStub(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $extractionRepository = $this->createStub(CorporationMiningExtractionRepository::class);
        $extractionRepository->method('find')->willReturnCallback(function (mixed $id): ?CorporationMiningExtraction {
            $identifier = $id instanceof Uuid ? $id->toRfc4122() : (string) $id;

            return array_values(array_filter($this->storedChunks, static fn (CorporationMiningExtraction $chunk): bool => $chunk->getId()?->toRfc4122() === $identifier))[0] ?? null;
        });
        $this->processor = new CreateMiningOperationProcessor(
            $this->security,
            new MiningOperationEnrolment($this->characterRepositoryReadingOpenEnrolments(), $this->entityManager),
            new MiningOperationResourceMapper(new MiningPresenceResolver(new MockClock(self::NOW, 'UTC'))),
            new MiningBoostFit($this->createStub(MountableCompressorCatalog::class)),
            new MiningOperationChunkResolver($extractionRepository, new MockClock(self::NOW, 'UTC')),
        );
    }

    public function testAriaCreatesAnOperationLinkedToTheArrivingChunkOfHerCorporation(): void
    {
        $this->storeChunk(self::C1, '2026-11-05T20:00:00+00:00', '2026-11-05T23:00:00+00:00');
        $this->security->method('getUser')->willReturn($this->aria);
        $this->entityManager->expects($this->once())->method('persist')->with($this->callback(
            static fn (object $entity): bool => $entity instanceof MiningOperation && $entity->getChunk()?->getId()?->toRfc4122() === self::CHUNK_ID,
        ));

        $resource = $this->processor->process($this->creation($this->aria, self::CHUNK_ID), new Post());

        self::assertSame(self::CHUNK_ID, $resource->chunkId);
        self::assertSame(self::SPODUMAIN_LABEL, $resource->label);
    }

    public function testAriaCannotCreateAnOperationFromTheChunkOfAnotherCorporation(): void
    {
        $this->storeChunk(self::C2, '2026-11-05T20:00:00+00:00', '2026-11-05T23:00:00+00:00');
        $this->security->method('getUser')->willReturn($this->aria);
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);

        $this->processor->process($this->creation($this->aria, self::CHUNK_ID), new Post());
    }

    public function testPilotWithoutAKnownCorporationCannotCreateAnOperationFromAChunk(): void
    {
        $this->storeChunk(self::C1, '2026-11-05T20:00:00+00:00', '2026-11-05T23:00:00+00:00');
        $this->security->method('getUser')->willReturn($this->dorn);
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);

        $this->processor->process($this->creation($this->dorn, self::CHUNK_ID, 'private'), new Post());
    }

    public function testAriaCannotCreateAnOperationFromAnExplodedChunk(): void
    {
        $this->storeChunk(self::C1, '2026-11-05T12:00:00+00:00', '2026-11-05T15:00:00+00:00');
        $this->security->method('getUser')->willReturn($this->aria);
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(BadRequestHttpException::class);

        $this->processor->process($this->creation($this->aria, self::CHUNK_ID), new Post());
    }

    private function storeChunk(int $corporationId, string $arrival, string $decay): void
    {
        $chunk = (new CorporationMiningExtraction())
            ->setCorporationId($corporationId)
            ->setStructureId(self::REFINERY_ID)
            ->setMoonId(self::MOON_ID)
            ->setExtractionStartTime(new \DateTimeImmutable('2026-11-02T18:00:00+00:00'))
            ->setChunkArrivalTime(new \DateTimeImmutable($arrival))
            ->setNaturalDecayTime(new \DateTimeImmutable($decay));
        (new \ReflectionProperty(CorporationMiningExtraction::class, 'id'))->setValue($chunk, Uuid::fromString(self::CHUNK_ID));
        $this->storedChunks[] = $chunk;
    }

    private function creation(User $owner, string $chunkId, string $visibility = 'corporation'): CreateMiningOperationInput
    {
        $characterName = $owner === $this->aria ? 'Aria II' : 'Dorn Achar';
        $input = new CreateMiningOperationInput();
        $input->label = self::SPODUMAIN_LABEL;
        $input->plannedStartAt = new \DateTimeImmutable(self::PLANNED_START);
        $input->visibility = $visibility;
        $input->splitMode = 'value_prorata';
        $input->characterIds = $this->idsOf([$characterName]);
        $input->chunkId = $chunkId;

        return $input;
    }
}
