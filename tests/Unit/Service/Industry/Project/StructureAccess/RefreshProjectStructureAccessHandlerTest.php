<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\StructureAccess;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Message\RefreshProjectStructureAccess;
use App\MessageHandler\RefreshProjectStructureAccessHandler;
use App\Repository\IndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RefreshProjectStructureAccessHandler::class)]
#[Group('issue-1299')]
final class RefreshProjectStructureAccessHandlerTest extends TestCase
{
    use BuildsStructureAccessRefresher;

    private const string UNKNOWN_PROJECT_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e52';

    private array $publishedUpdates = [];

    public function testHandlerRefreshesTheProjectThenPublishesTheReportOnTheProjectTopic(): void
    {
        $owner = $this->pilotOf($this->characterOf());
        $project = $this->projectPlannedAt($owner, [self::KEEPSTAR_STRUCTURE_ID]);

        $this->handlerFor($project, [$owner, $this->pilotOf($this->characterOf())])(new RefreshProjectStructureAccess($project->getId()->toRfc4122()));

        self::assertCount(2, $this->requestedUrls);
        self::assertCount(1, $this->publishedUpdates);
        $update = $this->publishedUpdates[0];
        self::assertSame(['/group-project/'.$project->getId()->toRfc4122().'/events'], $update->getTopics());
        $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('structure_access_changed', $payload['action']);
        self::assertSame($project->getId()->toRfc4122(), $payload['projectId']);
        self::assertSame(['probed' => 2, 'skipped' => 0, 'stoppedForBudget' => false, 'capped' => false], $payload['data']);
    }

    public function testHandlerDoesNothingWhenTheProjectIsGone(): void
    {
        $owner = $this->pilotOf($this->characterOf());
        $project = $this->projectPlannedAt($owner, [self::KEEPSTAR_STRUCTURE_ID]);

        $this->handlerFor($project, [$owner])(new RefreshProjectStructureAccess(self::UNKNOWN_PROJECT_ID));

        self::assertSame([], $this->requestedUrls);
        self::assertSame([], $this->publishedUpdates);
        self::assertSame([], $this->persistedRows);
    }

    /**
     * @param list<User> $acceptedMembers
     */
    private function handlerFor(IndustryProject $project, array $acceptedMembers): RefreshProjectStructureAccessHandler
    {
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?IndustryProject => (string) $id === $project->getId()->toRfc4122() ? $project : null,
        );

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:'.Uuid::v4()->toRfc4122();
        });

        return new RefreshProjectStructureAccessHandler(
            $projectRepository,
            $this->refresherFor($project, $acceptedMembers),
            new MercurePublisherService($hub, new NullLogger()),
        );
    }
}
