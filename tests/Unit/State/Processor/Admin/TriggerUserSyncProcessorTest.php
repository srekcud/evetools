<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Admin;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Admin\TriggerUserSyncInput;
use App\Entity\Character;
use App\Entity\User;
use App\Message\SyncUserBlueprints;
use App\Repository\UserRepository;
use App\Security\AdminAccessGuard;
use App\Security\AdminChecker;
use App\State\Processor\Admin\TriggerUserSyncProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(TriggerUserSyncProcessor::class)]
#[Group('issue-647')]
final class TriggerUserSyncProcessorTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;
    private const OTHER_EVE_CHARACTER_ID = 90000002;
    private const TARGET_USER_ID = '0192f0c4-6d2e-7a1b-9c3d-4e5f6a7b8c9d';

    /** @var list<object> */
    private array $dispatchedMessages = [];

    private ?User $targetUser;

    protected function setUp(): void
    {
        $this->dispatchedMessages = [];
        $this->targetUser = new User();
    }

    public function testAnAdminTriggersAUserSyncThatTargetsTheUserOfTheUrl(): void
    {
        $result = $this->process(self::ADMIN_EVE_CHARACTER_ID, 'blueprints');

        self::assertTrue($result->success);
        self::assertSame('Sync triggered: blueprints', $result->message);
        self::assertEquals([new SyncUserBlueprints(self::TARGET_USER_ID)], $this->dispatchedMessages);
    }

    public function testATypeWithoutUserMessageDispatchesNothing(): void
    {
        $result = $this->process(self::ADMIN_EVE_CHARACTER_ID, 'assets');

        self::assertFalse($result->success);
        self::assertSame('Unknown sync type', $result->message);
        self::assertSame([], $this->dispatchedMessages);
    }

    public function testAnUnknownUserIsNotFoundAndDispatchesNothing(): void
    {
        $this->targetUser = null;

        try {
            $this->process(self::ADMIN_EVE_CHARACTER_ID, 'blueprints');
            self::fail('A NotFoundHttpException was expected');
        } catch (NotFoundHttpException) {
            self::assertSame([], $this->dispatchedMessages);
        }
    }

    public function testAMalformedUserIdIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->process(self::ADMIN_EVE_CHARACTER_ID, 'blueprints', 'not-a-uuid');
    }

    public function testANonAdminDispatchesNothing(): void
    {
        try {
            $this->process(self::OTHER_EVE_CHARACTER_ID, 'blueprints');
            self::fail('An AccessDeniedHttpException was expected');
        } catch (AccessDeniedHttpException) {
            self::assertSame([], $this->dispatchedMessages);
        }
    }

    private function process(int $callerEveCharacterId, string $type, string $userId = self::TARGET_USER_ID): \App\ApiResource\Admin\ActionResultResource
    {
        $caller = new User();
        $character = (new Character())->setEveCharacterId($callerEveCharacterId)->setName('Pilot');
        $caller->addCharacter($character);
        $caller->setMainCharacter($character);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($caller);

        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturnCallback(fn (): ?User => $this->targetUser);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatchedMessages[] = $message;

            return new Envelope($message);
        });

        $input = new TriggerUserSyncInput();
        $input->type = $type;

        return (new TriggerUserSyncProcessor(
            new AdminAccessGuard($security, new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID)),
            $users,
            $messageBus,
            new NullLogger(),
        ))->process($input, new Post(name: 'trigger_user_sync'), ['userId' => $userId]);
    }
}
