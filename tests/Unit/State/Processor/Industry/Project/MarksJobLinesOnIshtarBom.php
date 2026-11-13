<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectBomLineMark;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\BomLineMarkState;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\Mercure\MercurePublisherService;
use App\Tests\Unit\State\Processor\Industry\TracksJobsAndPurchasesOnIshtarBom;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

trait MarksJobLinesOnIshtarBom
{
    use TracksJobsAndPurchasesOnIshtarBom;

    private const string INITIAL_MARK_DATE = '2026-11-01T08:00:00+00:00';

    private User $pendingUser;

    private array $publishedUpdates = [];

    private int $flushCount = 0;

    private function setUpMarkableIshtarProject(): void
    {
        $this->setUpIshtarProject();
        $this->pendingUser = new User();
        foreach ([$this->owner, $this->admin, $this->pendingUser] as $user) {
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::v7());
        }
        $this->ishtarProject->addMember(
            (new IndustryProjectParticipant())->setUser($this->pendingUser)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Pending),
        );
    }

    private function markOn(IndustryProjectBomLine $line, BomLineMarkState $state, int $runs): IndustryProjectBomLineMark
    {
        $stepMark = new IndustryProjectBomLineMark($state, $runs, new \DateTimeImmutable(self::INITIAL_MARK_DATE));
        $line->setMark($stepMark);

        return $stepMark;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushCount;
        });

        return $entityManager;
    }

    private function mercurePublisher(): MercurePublisherService
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:published';
        });

        return new MercurePublisherService($hub, new NullLogger());
    }

    private function publishedIndustryProjectEvents(): array
    {
        $events = array_map(
            static fn (Update $update): array => [$update->getTopics()[0], json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR)['data']],
            $this->publishedUpdates,
        );
        sort($events);

        return $events;
    }

    private function expectedIndustryProjectEvents(IndustryProjectBomLine $line): array
    {
        $data = ['projectId' => $this->ishtarProject->getId()->toRfc4122(), 'lineId' => $line->getId()->toRfc4122()];
        $events = [
            ['/user/'.$this->owner->getId()->toRfc4122().'/sync/industry-project', $data],
            ['/user/'.$this->admin->getId()->toRfc4122().'/sync/industry-project', $data],
        ];
        sort($events);

        return $events;
    }

    private function statusOf(callable $operation): ?int
    {
        try {
            $operation();
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode();
        }

        return null;
    }
}
