<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectActivity;
use App\Entity\User;
use App\Enum\ProjectActivityKind;
use App\Event\GroupProjectEventPublished;
use App\EventListener\ProjectActivityRecorder;
use App\Repository\IndustryProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

#[CoversClass(ProjectActivityRecorder::class)]
#[Group('issue-321')]
final class ProjectActivityRecorderTest extends TestCase
{
    private const string OCCURRED_AT = '2026-11-09T21:15:00+00:00';

    private IndustryProject $project;
    private ?IndustryProject $foundProject;
    private BufferingLogger $logger;
    private array $persisted = [];
    private int $flushes = 0;
    private ProjectActivityRecorder $recorder;

    protected function setUp(): void
    {
        $this->project = (new IndustryProject())->setOwner(new User())->setName('Ishtar batch');
        $this->foundProject = $this->project;
        $this->logger = new BufferingLogger();

        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(fn (): ?IndustryProject => $this->foundProject);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $this->recorder = new ProjectActivityRecorder($projectRepository, $entityManager, $this->logger);
    }

    public function testASaleRecordedEventIsStoredOnTheProjectWithItsDataAndItsTimestamp(): void
    {
        $data = ['saleId' => '0196f6a2-7c1e-7d3a-9b40-321000000001', 'typeName' => 'Ishtar', 'quantity' => 2, 'unitPrice' => 1_500_000.0];

        ($this->recorder)($this->event('sale_recorded', $data));

        self::assertCount(1, $this->persisted);
        $activity = $this->persisted[0];
        self::assertInstanceOf(IndustryProjectActivity::class, $activity);
        self::assertSame($this->project, $activity->getProject());
        self::assertSame(ProjectActivityKind::SaleRecorded, $activity->getKind());
        self::assertSame($data, $activity->getData());
        self::assertSame(self::OCCURRED_AT, $activity->getOccurredAt()->format(\DateTimeInterface::ATOM));
        self::assertSame(1, $this->flushes);
    }

    #[DataProvider('provideActionsThatAreNoise')]
    public function testActionsThatAreNoiseLeaveNoActivity(string $action): void
    {
        ($this->recorder)($this->event($action, ['lineId' => 'x']));

        self::assertSame([], $this->persisted);
        self::assertSame(0, $this->flushes);
    }

    public static function provideActionsThatAreNoise(): iterable
    {
        yield 'line_reserved' => ['line_reserved'];
        yield 'contribution_synced' => ['contribution_synced'];
        yield 'transfer_received' => ['transfer_received'];
    }

    public function testAnUnknownProjectIsLoggedAsAWarningAndNothingIsStoredNorThrown(): void
    {
        $this->foundProject = null;

        ($this->recorder)($this->event('sale_recorded', ['typeName' => 'Ishtar']));

        self::assertSame([], $this->persisted);
        $warnings = array_values(array_filter($this->logger->cleanLogs(), static fn (array $record): bool => 'warning' === $record[0]));
        self::assertCount(1, $warnings);
    }

    private function event(string $action, array $data): GroupProjectEventPublished
    {
        return new GroupProjectEventPublished(
            $this->project->getId()->toRfc4122(),
            $action,
            $data,
            new \DateTimeImmutable(self::OCCURRED_AT),
        );
    }
}
