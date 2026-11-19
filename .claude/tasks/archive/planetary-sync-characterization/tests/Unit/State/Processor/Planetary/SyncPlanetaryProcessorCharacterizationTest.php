<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Planetary;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\User;
use App\Message\TriggerPlanetarySync;
use App\State\Processor\Planetary\SyncPlanetaryProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(SyncPlanetaryProcessor::class)]
final class SyncPlanetaryProcessorCharacterizationTest extends TestCase
{
    private array $dispatchedMessages = [];

    public function testAnonymousRequestIsUnauthorizedAndDispatchesNothing(): void
    {
        $caught = null;
        try {
            $this->processorFor(null)->process(null, new Post());
        } catch (UnauthorizedHttpException $exception) {
            $caught = $exception;
        }

        $this->assertSame('Unauthorized', $caught?->getMessage());
        $this->assertSame(['WWW-Authenticate' => 'Bearer'], $caught?->getHeaders());
        $this->assertSame([], $this->dispatchedMessages);
    }

    public function testNonApplicationUserIsUnauthorizedAndDispatchesNothing(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        try {
            $this->processorFor(new InMemoryUser('kaelis', null))->process(null, new Post());
        } finally {
            $this->assertSame([], $this->dispatchedMessages);
        }
    }

    #[Group('pi-audit-4')]
    public function testSyncRequestCurrentlyDispatchesInstanceWideTriggerCarryingNoUserOrCharacterPiAudit4(): void
    {
        $user = new User();
        $user->addCharacter((new Character())->setEveCharacterId(2119876543)->setName('Selene Arkwright'));

        $this->processorFor($user)->process(null, new Post());

        $this->assertEquals([new TriggerPlanetarySync()], $this->dispatchedMessages);
        $this->assertSame([], get_object_vars($this->dispatchedMessages[0]));
    }

    private function processorFor(?object $authenticatedUser): SyncPlanetaryProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($authenticatedUser);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatchedMessages[] = $message;

            return new Envelope($message);
        });

        return new SyncPlanetaryProcessor($security, $messageBus);
    }
}
