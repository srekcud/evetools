<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Admin;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Admin\FailedMessageClassInput;
use App\Entity\Character;
use App\Entity\User;
use App\Message\SyncBlueprints;
use App\Message\SyncCostIndices;
use App\Security\AdminAccessGuard;
use App\Security\AdminChecker;
use App\Service\Admin\FailedMessageHttpExceptionMapper;
use App\Service\Admin\FailedMessageService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\Admin\AbstractFailedMessageProcessor;
use App\State\Processor\Admin\RemoveFailedMessageClassProcessor;
use App\State\Processor\Admin\RemoveFailedMessageProcessor;
use App\Tests\Unit\Service\Admin\ListableInMemoryTransport;
use App\Tests\Unit\Service\Mercure\RecordsAdminHealthUpdates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

#[CoversClass(AbstractFailedMessageProcessor::class)]
#[Group('issue-813')]
final class FailedMessageProcessorPublicationTest extends TestCase
{
    use RecordsAdminHealthUpdates;

    private const int ADMIN_EVE_CHARACTER_ID = 90000001;
    private const int OTHER_EVE_CHARACTER_ID = 90000002;

    private ListableInMemoryTransport $failedTransport;
    private FailedMessageService $failedMessages;

    protected function setUp(): void
    {
        $serializer = new PhpSerializer();
        $this->failedTransport = new ListableInMemoryTransport($serializer);
        $this->failedMessages = new FailedMessageService($this->failedTransport, new ServiceLocator([]), $serializer);
    }

    public function testRemovingAMessagePublishesTheFailedMessagesScopeOnce(): void
    {
        $removedId = $this->seedFailure(new SyncBlueprints());

        (new RemoveFailedMessageProcessor(...$this->dependenciesOf(self::ADMIN_EVE_CHARACTER_ID)))
            ->process(null, new Delete(), ['id' => (string) $removedId]);

        self::assertSame(['failed-messages'], $this->publishedScopes());
    }

    public function testRemovingAClassOfThreeMessagesPublishesOnceNotPerMessage(): void
    {
        $this->seedFailure(new SyncBlueprints());
        $this->seedFailure(new SyncBlueprints());
        $this->seedFailure(new SyncBlueprints());
        $this->seedFailure(new SyncCostIndices());
        $input = new FailedMessageClassInput();
        $input->messageClass = SyncBlueprints::class;

        $result = (new RemoveFailedMessageClassProcessor(...$this->dependenciesOf(self::ADMIN_EVE_CHARACTER_ID)))
            ->process($input, new Post());

        self::assertSame(3, $result->processed);
        self::assertSame(['failed-messages'], $this->publishedScopes());
    }

    public function testRemovingAnUnknownMessagePublishesNothing(): void
    {
        $this->seedFailure(new SyncBlueprints());

        try {
            (new RemoveFailedMessageProcessor(...$this->dependenciesOf(self::ADMIN_EVE_CHARACTER_ID)))
                ->process(null, new Delete(), ['id' => '999']);
            self::fail('An unknown failed message must be answered by a 404.');
        } catch (NotFoundHttpException) {
        }

        self::assertSame([], $this->publishedScopes());
    }

    public function testANonAdminActionIsRefusedAndPublishesNothing(): void
    {
        $removedId = $this->seedFailure(new SyncBlueprints());

        try {
            (new RemoveFailedMessageProcessor(...$this->dependenciesOf(self::OTHER_EVE_CHARACTER_ID)))
                ->process(null, new Delete(), ['id' => (string) $removedId]);
            self::fail('A non-admin must be refused.');
        } catch (AccessDeniedHttpException) {
        }

        self::assertSame([], $this->publishedScopes());
    }

    /**
     * @return array{AdminAccessGuard, FailedMessageService, FailedMessageHttpExceptionMapper, MercurePublisherService}
     */
    private function dependenciesOf(int $eveCharacterId): array
    {
        $mainCharacter = (new Character())->setEveCharacterId($eveCharacterId)->setName('Pilot ' . $eveCharacterId);
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($mainCharacter);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return [
            new AdminAccessGuard($security, new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID)),
            $this->failedMessages,
            new FailedMessageHttpExceptionMapper(),
            $this->adminHealthPublisher(),
        ];
    }

    private function seedFailure(object $message): int
    {
        $sent = $this->failedTransport->send(new Envelope($message));
        $idStamp = $sent->last(TransportMessageIdStamp::class);
        self::assertInstanceOf(TransportMessageIdStamp::class, $idStamp);

        return (int) $idStamp->getId();
    }
}
