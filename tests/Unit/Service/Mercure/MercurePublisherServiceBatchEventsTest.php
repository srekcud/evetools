<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mercure;

use App\Event\GroupProjectEventPublished;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[CoversClass(MercurePublisherService::class)]
#[Group('issue-1152')]
final class MercurePublisherServiceBatchEventsTest extends TestCase
{
    private const string PROJECT_ID = '0196f6a2-7c1e-7d3a-9b40-115200000002';

    private array $dispatched = [];
    private array $updates = [];
    private EventDispatcher $dispatcher;
    private HubInterface $hub;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(GroupProjectEventPublished::class, function (GroupProjectEventPublished $event): void {
            $this->dispatched[] = [$event->projectId, $event->action, $event->data];
        });
        $this->hub = $this->createStub(HubInterface::class);
        $this->hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->updates[] = [$update->getTopics(), json_decode($update->getData(), true), $update->isPrivate()];

            return 'update-id';
        });
    }

    public function testEveryActivityIsDispatchedForTheJournalAndTheHubReceivesASingleUpdateCarryingTheSummary(): void
    {
        $this->service()->publishGroupProjectEvents(
            self::PROJECT_ID,
            'sale_recorded',
            [['typeName' => 'Sabre', 'quantity' => 3], ['typeName' => 'Sabre', 'quantity' => 12]],
            ['count' => 2, 'quantity' => 15],
        );

        self::assertSame(
            [
                [[self::PROJECT_ID, 'sale_recorded', ['typeName' => 'Sabre', 'quantity' => 3]], [self::PROJECT_ID, 'sale_recorded', ['typeName' => 'Sabre', 'quantity' => 12]]],
                1,
                [['/group-project/' . self::PROJECT_ID . '/events'], 'sale_recorded', self::PROJECT_ID, ['count' => 2, 'quantity' => 15], true],
            ],
            [
                $this->dispatched,
                count($this->updates),
                [$this->updates[0][0], $this->updates[0][1]['action'], $this->updates[0][1]['projectId'], $this->updates[0][1]['data'], $this->updates[0][2]],
            ],
        );
    }

    public function testWithoutActivityNothingIsDispatchedAndNothingIsPublished(): void
    {
        $this->service()->publishGroupProjectEvents(self::PROJECT_ID, 'sale_recorded', [], ['count' => 0]);

        self::assertSame([[], []], [$this->dispatched, $this->updates]);
    }

    public function testAHubFailureDoesNotEscapeAfterTheActivitiesWereDispatched(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new \RuntimeException('hub down'));

        (new MercurePublisherService($hub, new NullLogger(), $this->dispatcher))->publishGroupProjectEvents(
            self::PROJECT_ID,
            'sale_recorded',
            [['quantity' => 1]],
            ['count' => 1],
        );

        self::assertCount(1, $this->dispatched);
    }

    private function service(): MercurePublisherService
    {
        return new MercurePublisherService($this->hub, new NullLogger(), $this->dispatcher);
    }
}
