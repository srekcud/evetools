<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use App\Entity\User;
use App\Message\SyncUserBlueprints;
use App\State\Processor\Industry\RefreshBlueprintsProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RefreshBlueprintsProcessor::class)]
#[Group('issue-357')]
final class RefreshBlueprintsProcessorTest extends TestCase
{
    private const string USER_ID = '0199a1f0-0000-7000-8000-000000357301';

    public function testQueuesTheBlueprintsSyncOfTheUserAndAnswersPending(): void
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString(self::USER_ID));
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $dispatchedMessages = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $message;

                return new Envelope($message);
            },
        );

        $result = (new RefreshBlueprintsProcessor($security, $messageBus))->process(null, $this->createStub(Operation::class));

        self::assertEquals([new SyncUserBlueprints(self::USER_ID)], $dispatchedMessages);
        self::assertSame('pending', $result->status);
    }
}
