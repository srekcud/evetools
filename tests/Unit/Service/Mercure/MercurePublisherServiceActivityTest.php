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
#[Group('issue-321')]
final class MercurePublisherServiceActivityTest extends TestCase
{
    private const string PROJECT_ID = '0196f6a2-7c1e-7d3a-9b40-321000000002';

    private array $steps = [];
    private EventDispatcher $dispatcher;
    private HubInterface $hub;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->hub = $this->createStub(HubInterface::class);
        $this->hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->steps[] = ['hub', json_decode($update->getData(), true)['action']];

            return 'update-id';
        });
    }

    public function testTheGroupProjectEventIsDispatchedWithItsProjectActionAndDataBeforeTheHubIsPublished(): void
    {
        $received = [];
        $this->dispatcher->addListener(GroupProjectEventPublished::class, function (GroupProjectEventPublished $event) use (&$received): void {
            $this->steps[] = ['dispatched', $event->action];
            $received[] = $event;
        });
        $before = new \DateTimeImmutable();

        $this->service()->publishGroupProjectEvent(self::PROJECT_ID, 'sale_recorded', ['typeName' => 'Ishtar', 'quantity' => 2]);

        self::assertSame([['dispatched', 'sale_recorded'], ['hub', 'sale_recorded']], $this->steps);
        self::assertCount(1, $received);
        self::assertSame([self::PROJECT_ID, 'sale_recorded', ['typeName' => 'Ishtar', 'quantity' => 2]], [$received[0]->projectId, $received[0]->action, $received[0]->data]);
        self::assertGreaterThanOrEqual($before, $received[0]->occurredAt);
    }

    public function testADispatcherThatThrowsStillLetsTheHubReceiveTheUpdateAndNothingEscapes(): void
    {
        $this->dispatcher->addListener(GroupProjectEventPublished::class, static function (): void {
            throw new \RuntimeException('recording failed');
        });

        $this->service()->publishGroupProjectEvent(self::PROJECT_ID, 'jobs_detected', ['jobCount' => 2]);

        self::assertSame([['hub', 'jobs_detected']], $this->steps);
    }

    public function testTheServiceBuiltWithTheHubAndTheLoggerOnlyStillPublishesToTheHub(): void
    {
        $service = new MercurePublisherService($this->hub, new NullLogger());

        $service->publishGroupProjectEvent(self::PROJECT_ID, 'status_changed', ['newStatus' => 'selling']);

        self::assertSame([['hub', 'status_changed']], $this->steps);
    }

    private function service(): MercurePublisherService
    {
        return new MercurePublisherService($this->hub, new NullLogger(), $this->dispatcher);
    }
}
