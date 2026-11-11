<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Admin;

use ApiPlatform\Metadata\Get;
use App\Entity\Character;
use App\Entity\User;
use App\Security\AdminChecker;
use App\Service\Admin\MessengerQueueService;
use App\State\Provider\Admin\QueueStatusProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

#[CoversClass(QueueStatusProvider::class)]
class QueueStatusProviderTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;
    private const ASYNC_MESSAGE_COUNT = 5;

    public function testQueuesExposeTheAsyncDepthAndAnUnknownFailedDepth(): void
    {
        $asyncTransport = new class(self::ASYNC_MESSAGE_COUNT) extends InMemoryTransport implements MessageCountAwareInterface {
            public function __construct(private readonly int $messageCount)
            {
                parent::__construct();
            }

            public function getMessageCount(): int
            {
                return $this->messageCount;
            }
        };
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->adminUser());
        $provider = new QueueStatusProvider(
            $security,
            new MessengerQueueService($asyncTransport, new InMemoryTransport()),
            new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID),
        );

        $resource = $provider->provide(new Get());

        $this->assertSame(['async' => 5, 'failed' => null], $resource->queues);
    }

    private function adminUser(): User
    {
        $mainCharacter = (new Character())
            ->setEveCharacterId(self::ADMIN_EVE_CHARACTER_ID)
            ->setName('Admin Pilot');

        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($mainCharacter);

        return $user;
    }
}
