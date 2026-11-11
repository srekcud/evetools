<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Admin;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\User;
use App\Security\AdminChecker;
use App\Service\Admin\MessengerQueueService;
use App\State\Processor\Admin\PurgeFailedProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;

#[CoversClass(PurgeFailedProcessor::class)]
class PurgeFailedProcessorTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;
    private const FAILED_MESSAGE_COUNT = 4;

    public function testPurgingReportsTheNumberOfFailedMessagesRemoved(): void
    {
        $failedTransport = new InMemoryTransport();
        foreach (range(1, self::FAILED_MESSAGE_COUNT) as $unused) {
            $failedTransport->send(new Envelope(new \stdClass()));
        }

        $result = $this->processorFor($failedTransport)->process(null, new Post());

        $this->assertTrue($result->success);
        $this->assertSame(4, $result->deleted);
        $this->assertSame('Purged 4 failed messages', $result->message);
    }

    public function testPurgingReportsTheErrorWhenTheTransportFails(): void
    {
        $failedTransport = $this->createStub(TransportInterface::class);
        $failedTransport->method('get')->willThrowException(new \RuntimeException('AMQP connection lost'));

        $result = $this->processorFor($failedTransport)->process(null, new Post());

        $this->assertFalse($result->success);
        $this->assertNull($result->deleted);
        $this->assertSame('Error: AMQP connection lost', $result->message);
    }

    private function processorFor(TransportInterface $failedTransport): PurgeFailedProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->adminUser());

        return new PurgeFailedProcessor(
            $security,
            new MessengerQueueService(new InMemoryTransport(), $failedTransport),
            new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID),
        );
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
