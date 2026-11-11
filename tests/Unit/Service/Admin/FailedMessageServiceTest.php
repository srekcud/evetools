<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Message\SyncBlueprints;
use App\Message\SyncCharacterAssets;
use App\Message\SyncCostIndices;
use App\Service\Admin\FailedMessage;
use App\Service\Admin\FailedMessageClassOutcome;
use App\Service\Admin\FailedMessageNotFound;
use App\Service\Admin\FailedMessageNotRedeliverable;
use App\Service\Admin\FailedMessageService;
use App\Service\Admin\FailedMessagesUnavailable;
use App\Service\Admin\MessageClassCount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

#[CoversClass(FailedMessageService::class)]
#[Group('issue-644')]
final class FailedMessageServiceTest extends TestCase
{
    private const string ASSETS_CLASS = SyncCharacterAssets::class;
    private const string BLUEPRINTS_CLASS = SyncBlueprints::class;
    private const string COST_INDICES_CLASS = SyncCostIndices::class;
    private const string RENAMED_CLASS = 'App\Message\RenamedMessage';
    private const string LOW_PRIORITY_TRANSPORT = 'low_priority';

    private PhpSerializer $serializer;
    private ListableInMemoryTransport $failedTransport;
    private InMemoryTransport $asyncTransport;
    private InMemoryTransport $lowPriorityTransport;
    private FailedMessageService $service;

    protected function setUp(): void
    {
        $this->serializer = new PhpSerializer();
        $this->failedTransport = new ListableInMemoryTransport($this->serializer);
        $this->asyncTransport = new InMemoryTransport();
        $this->lowPriorityTransport = new InMemoryTransport();
        $this->service = new FailedMessageService(
            $this->failedTransport,
            new ServiceLocator([
                'async' => fn (): InMemoryTransport => $this->asyncTransport,
                self::LOW_PRIORITY_TRANSPORT => fn (): InMemoryTransport => $this->lowPriorityTransport,
            ]),
            $this->serializer,
        );
    }

    public function testListCountsEveryMessageAndGroupsThemByMessageClassMostFrequentFirst(): void
    {
        $this->seedFailure(new SyncCharacterAssets('char-1'));
        $this->seedFailure(new SyncBlueprints());
        $this->seedFailure(new SyncCharacterAssets('char-2'));
        $this->seedFailure(new SyncCharacterAssets('char-3'));

        $list = $this->service->list(null);

        self::assertSame(4, $list->total);
        self::assertEquals(
            [new MessageClassCount(self::ASSETS_CLASS, 3), new MessageClassCount(self::BLUEPRINTS_CLASS, 1)],
            $list->classes,
        );
        self::assertCount(4, $list->messages);
    }

    public function testClassesWithTheSameCountAreSortedByName(): void
    {
        $this->seedFailure(new SyncCostIndices());
        $this->seedFailure(new SyncCostIndices());
        $this->seedFailure(new SyncBlueprints());
        $this->seedFailure(new SyncBlueprints());
        $this->seedFailure(new SyncCharacterAssets('char-1'));

        $list = $this->service->list(null);

        self::assertEquals(
            [
                new MessageClassCount(self::BLUEPRINTS_CLASS, 2),
                new MessageClassCount(self::COST_INDICES_CLASS, 2),
                new MessageClassCount(self::ASSETS_CLASS, 1),
            ],
            $list->classes,
        );
    }

    public function testAListedMessageCarriesItsIdClassExceptionFailureDateAndRetryCount(): void
    {
        $assetsFailedAt = new \DateTimeImmutable('2026-11-10 08:15:00', new \DateTimeZone('UTC'));
        $blueprintsFailedAt = new \DateTimeImmutable('2026-11-09 21:40:30', new \DateTimeZone('UTC'));
        $assetsId = $this->seed(
            new SyncCharacterAssets('char-1'),
            new SentToFailureTransportStamp('async'),
            new ErrorDetailsStamp(\RuntimeException::class, 0, 'ESI timeout for assets'),
            new RedeliveryStamp(2, $assetsFailedAt),
        );
        $blueprintsId = $this->seed(
            new SyncBlueprints(),
            new SentToFailureTransportStamp('async'),
            new ErrorDetailsStamp(\LogicException::class, 0, 'Blueprint page missing'),
            new RedeliveryStamp(3, $blueprintsFailedAt),
        );

        $list = $this->service->list(null);

        self::assertEquals(
            [
                new FailedMessage((string) $blueprintsId, self::BLUEPRINTS_CLASS, \LogicException::class, 'Blueprint page missing', $blueprintsFailedAt, 3),
                new FailedMessage((string) $assetsId, self::ASSETS_CLASS, \RuntimeException::class, 'ESI timeout for assets', $assetsFailedAt, 2),
            ],
            $list->messages,
        );
    }

    public function testMessagesPageHoldsTheOneHundredMostRecentFirstWhileTotalAndClassesCoverEveryMessage(): void
    {
        $blueprintsId = $this->seedFailure(new SyncBlueprints());
        foreach (range(1, 119) as $characterNumber) {
            $this->seedFailure(new SyncCharacterAssets('char-' . $characterNumber));
        }

        $list = $this->service->list(null);

        self::assertSame(120, $list->total);
        self::assertCount(100, $list->messages);
        self::assertSame('120', $list->messages[0]->id);
        self::assertSame('21', $list->messages[99]->id);
        self::assertEquals(
            [new MessageClassCount(self::ASSETS_CLASS, 119), new MessageClassCount(self::BLUEPRINTS_CLASS, 1)],
            $list->classes,
        );

        $filtered = $this->service->list(self::BLUEPRINTS_CLASS);

        self::assertSame(120, $filtered->total);
        self::assertSame([(string) $blueprintsId], array_map(static fn (FailedMessage $message): string => $message->id, $filtered->messages));
        self::assertEquals($list->classes, $filtered->classes);
    }

    public function testFilteringOnAClassWithoutMessageListsNothingButKeepsTheTotal(): void
    {
        $this->seedFailure(new SyncCharacterAssets('char-1'));
        $this->seedFailure(new SyncCharacterAssets('char-2'));

        $list = $this->service->list(self::COST_INDICES_CLASS);

        self::assertSame(2, $list->total);
        self::assertSame([], $list->messages);
        self::assertEquals([new MessageClassCount(self::ASSETS_CLASS, 2)], $list->classes);
    }

    public function testAnEnvelopeWithoutRedeliveryStampHasNullRetryCountAndFailureDate(): void
    {
        $this->seed(
            new SyncBlueprints(),
            new SentToFailureTransportStamp('async'),
            new ErrorDetailsStamp(\RuntimeException::class, 0, 'boom'),
        );

        $message = $this->onlyListedMessage();

        self::assertNull($message->retryCount);
        self::assertNull($message->failedAt);
        self::assertSame('boom', $message->exceptionMessage);
    }

    public function testAnEnvelopeWithoutErrorDetailsHasNullExceptionFields(): void
    {
        $this->seed(
            new SyncBlueprints(),
            new SentToFailureTransportStamp('async'),
            new RedeliveryStamp(1, new \DateTimeImmutable('2026-11-10 08:15:00', new \DateTimeZone('UTC'))),
        );

        $message = $this->onlyListedMessage();

        self::assertNull($message->exceptionClass);
        self::assertNull($message->exceptionMessage);
        self::assertSame(1, $message->retryCount);
    }

    public function testRetryCountIsTheHighestCounterAndFailureDateThatOfTheLastRedeliveryStamp(): void
    {
        $lastRedeliveredAt = new \DateTimeImmutable('2026-11-10 12:00:00', new \DateTimeZone('UTC'));
        $this->seed(
            new SyncBlueprints(),
            new SentToFailureTransportStamp('async'),
            new RedeliveryStamp(2, new \DateTimeImmutable('2026-11-10 10:00:00', new \DateTimeZone('UTC'))),
            new RedeliveryStamp(3, new \DateTimeImmutable('2026-11-10 11:00:00', new \DateTimeZone('UTC'))),
            new RedeliveryStamp(0, $lastRedeliveredAt),
        );

        $message = $this->onlyListedMessage();

        self::assertSame(3, $message->retryCount);
        self::assertEquals($lastRedeliveredAt, $message->failedAt);
    }

    public function testExceptionMessageIsTruncatedToFiveHundredCharacters(): void
    {
        $this->seed(
            new SyncBlueprints(),
            new SentToFailureTransportStamp('async'),
            new ErrorDetailsStamp(\RuntimeException::class, 0, str_repeat('é', 800)),
        );

        $message = $this->onlyListedMessage();

        self::assertSame(str_repeat('é', 500), $message->exceptionMessage);
    }

    public function testExceptionMessageOfExactlyFiveHundredCharactersIsKept(): void
    {
        $exactMessage = str_repeat('x', 499) . 'y';
        $this->seed(
            new SyncBlueprints(),
            new SentToFailureTransportStamp('async'),
            new ErrorDetailsStamp(\RuntimeException::class, 0, $exactMessage),
        );

        self::assertSame($exactMessage, $this->onlyListedMessage()->exceptionMessage);
    }

    public function testListingAMessageOfAVanishedClassNamesItsClassAndKeepsItInTheTransport(): void
    {
        $this->seed($this->vanishedClassMessage(), new SentToFailureTransportStamp('async'));
        $this->seedFailure(new SyncBlueprints());

        $list = $this->service->list(null);

        self::assertSame(2, $list->total);
        self::assertEquals(
            [new MessageClassCount(self::RENAMED_CLASS, 1), new MessageClassCount(self::BLUEPRINTS_CLASS, 1)],
            $list->classes,
        );
        self::assertSame(self::RENAMED_CLASS, $list->messages[1]->messageClass);
        self::assertSame(2, $this->failedMessageCount());
    }

    public function testListingRestoresTheStrictDecodingOfTheSerializer(): void
    {
        $this->seedFailure(new SyncBlueprints());
        $encodedVanishedMessage = $this->serializer->encode(new Envelope($this->vanishedClassMessage()));

        $this->service->list(null);

        $this->expectException(MessageDecodingFailedException::class);
        $this->serializer->decode($encodedVanishedMessage);
    }

    public function testRetryResendsTheMessageToItsOriginalTransportWithoutFailureStampsAndRemovesItFromFailed(): void
    {
        foreach (range(1, 6) as $characterNumber) {
            $this->seedFailure(new SyncCostIndices());
        }
        $retriedId = $this->seed(
            new SyncCharacterAssets('char-7'),
            new SentToFailureTransportStamp('async'),
            new ErrorDetailsStamp(\RuntimeException::class, 0, 'boom'),
            new RedeliveryStamp(3),
        );

        $this->service->retry((string) $retriedId);

        $sent = $this->asyncTransport->getSent();
        self::assertCount(1, $sent);
        self::assertEquals(new SyncCharacterAssets('char-7'), $sent[0]->getMessage());
        self::assertSame([], $sent[0]->all(SentToFailureTransportStamp::class));
        self::assertSame([], $sent[0]->all(RedeliveryStamp::class));
        self::assertCount(1, $sent[0]->all(TransportMessageIdStamp::class));
        self::assertSame(1, $sent[0]->last(TransportMessageIdStamp::class)?->getId());
        self::assertNull($this->failedTransport->find($retriedId));
        self::assertSame(6, $this->failedMessageCount());
    }

    public function testRetryReturnsEachMessageToTheTransportItCameFrom(): void
    {
        $asyncId = $this->seed(new SyncCharacterAssets('char-1'), new SentToFailureTransportStamp('async'));
        $lowPriorityId = $this->seed(new SyncBlueprints(), new SentToFailureTransportStamp(self::LOW_PRIORITY_TRANSPORT));

        $this->service->retry((string) $asyncId);
        $this->service->retry((string) $lowPriorityId);

        self::assertEquals([new SyncCharacterAssets('char-1')], $this->messagesOf($this->asyncTransport));
        self::assertEquals([new SyncBlueprints()], $this->messagesOf($this->lowPriorityTransport));
    }

    public function testRetryKeepsTheMessageInFailedWhenTheResendFails(): void
    {
        $retriedId = $this->seedFailure(new SyncBlueprints());
        $brokenTransport = new class() extends InMemoryTransport {
            public function send(Envelope $envelope): Envelope
            {
                throw new \RuntimeException('broker down');
            }
        };
        $service = new FailedMessageService(
            $this->failedTransport,
            new ServiceLocator(['async' => static fn (): InMemoryTransport => $brokenTransport]),
            $this->serializer,
        );

        $resendFailureReachedTheCaller = false;
        try {
            $service->retry((string) $retriedId);
        } catch (\RuntimeException) {
            $resendFailureReachedTheCaller = true;
        }

        self::assertTrue($resendFailureReachedTheCaller);
        self::assertNotNull($this->failedTransport->find($retriedId));
    }

    public function testRemoveDeletesTheMessageFromFailedWithoutSendingAnything(): void
    {
        $this->seedFailure(new SyncCostIndices());
        $removedId = $this->seedFailure(new SyncBlueprints());

        $this->service->remove((string) $removedId);

        self::assertNull($this->failedTransport->find($removedId));
        self::assertSame(1, $this->failedMessageCount());
        self::assertSame([], $this->asyncTransport->getSent());
    }

    public function testRetryAndRemoveOfAnUnknownIdAreNotFoundAndTouchNothing(): void
    {
        $this->seedFailure(new SyncBlueprints());

        foreach (['retry', 'remove'] as $action) {
            try {
                $this->service->{$action}('999');
                self::fail(sprintf('%s of an unknown id must be refused.', $action));
            } catch (FailedMessageNotFound) {
            }
        }

        self::assertSame(1, $this->failedMessageCount());
        self::assertSame([], $this->asyncTransport->getSent());
    }

    public function testRetryClassResendsEveryMessageOfTheClassAndLeavesTheOtherClasses(): void
    {
        $this->seedFailure(new SyncCharacterAssets('char-1'));
        $this->seedFailure(new SyncBlueprints());
        $this->seedFailure(new SyncCharacterAssets('char-2'));
        $this->seedFailure(new SyncCharacterAssets('char-3'));

        $outcome = $this->service->retryClass(self::ASSETS_CLASS);

        self::assertEquals(new FailedMessageClassOutcome(3, 0), $outcome);
        self::assertEquals(
            [new SyncCharacterAssets('char-1'), new SyncCharacterAssets('char-2'), new SyncCharacterAssets('char-3')],
            $this->messagesOf($this->asyncTransport),
        );
        self::assertEquals([new MessageClassCount(self::BLUEPRINTS_CLASS, 1)], $this->service->list(null)->classes);
    }

    public function testRemoveClassProcessesAtMostFiveHundredMessagesAndReportsTheRemainder(): void
    {
        foreach (range(1, 600) as $characterNumber) {
            $this->seedFailure(new SyncCharacterAssets('char-' . $characterNumber));
        }

        $outcome = $this->service->removeClass(self::ASSETS_CLASS);

        self::assertEquals(new FailedMessageClassOutcome(500, 100), $outcome);
        self::assertSame(100, $this->failedMessageCount());
        self::assertSame([], $this->asyncTransport->getSent());
    }

    public function testRemoveClassKeepsTheOtherClasses(): void
    {
        $this->seedFailure(new SyncCharacterAssets('char-1'));
        $this->seedFailure(new SyncBlueprints());
        $this->seedFailure(new SyncCharacterAssets('char-2'));

        $outcome = $this->service->removeClass(self::ASSETS_CLASS);

        self::assertEquals(new FailedMessageClassOutcome(2, 0), $outcome);
        self::assertEquals([new MessageClassCount(self::BLUEPRINTS_CLASS, 1)], $this->service->list(null)->classes);
    }

    public function testAClassWithoutMessageProcessesNothing(): void
    {
        $this->seedFailure(new SyncBlueprints());

        self::assertEquals(new FailedMessageClassOutcome(0, 0), $this->service->retryClass(self::COST_INDICES_CLASS));
        self::assertEquals(new FailedMessageClassOutcome(0, 0), $this->service->removeClass(self::COST_INDICES_CLASS));
        self::assertSame(1, $this->failedMessageCount());
        self::assertSame([], $this->asyncTransport->getSent());
    }

    public function testRetryOfAMessageOfAVanishedClassIsRefusedAndKeepsTheMessage(): void
    {
        $vanishedId = $this->seed($this->vanishedClassMessage(), new SentToFailureTransportStamp('async'));

        try {
            $this->service->retry((string) $vanishedId);
            self::fail('A message whose class vanished cannot be redelivered.');
        } catch (FailedMessageNotRedeliverable) {
        }

        self::assertSame(1, $this->failedMessageCount());
        self::assertSame([], $this->asyncTransport->getSent());
    }

    public function testRemoveOfAMessageOfAVanishedClassSucceeds(): void
    {
        $vanishedId = $this->seed($this->vanishedClassMessage(), new SentToFailureTransportStamp('async'));

        $this->service->remove((string) $vanishedId);

        self::assertSame(0, $this->failedMessageCount());
    }

    public function testListingAnUnreachableFailedTransportIsAnExplicitError(): void
    {
        $this->seedFailure(new SyncBlueprints());
        $this->failedTransport->failWith(new TransportException('connection refused'));

        $this->expectException(FailedMessagesUnavailable::class);

        $this->service->list(null);
    }

    public function testRemovingFromAnUnreachableFailedTransportIsAnExplicitError(): void
    {
        $removedId = $this->seedFailure(new SyncBlueprints());
        $this->failedTransport->failWith(new TransportException('connection refused'));

        $this->expectException(FailedMessagesUnavailable::class);

        $this->service->remove((string) $removedId);
    }

    private function failedMessageCount(): int
    {
        $this->serializer->acceptPhpIncompleteClass();

        try {
            return $this->failedTransport->getMessageCount();
        } finally {
            $this->serializer->rejectPhpIncompleteClass();
        }
    }

    private function onlyListedMessage(): FailedMessage
    {
        $messages = $this->service->list(null)->messages;
        self::assertCount(1, $messages);

        return $messages[0];
    }

    private function seedFailure(object $message): int
    {
        return $this->seed(
            $message,
            new SentToFailureTransportStamp('async'),
            new ErrorDetailsStamp(\RuntimeException::class, 0, 'boom'),
            new RedeliveryStamp(0),
        );
    }

    private function seed(object $message, StampInterface ...$stamps): int
    {
        $sent = $this->failedTransport->send(new Envelope($message, $stamps));
        $idStamp = $sent->last(TransportMessageIdStamp::class);
        self::assertInstanceOf(TransportMessageIdStamp::class, $idStamp);

        return (int) $idStamp->getId();
    }

    private function vanishedClassMessage(): object
    {
        $message = unserialize(sprintf('O:%d:"%s":0:{}', strlen(self::RENAMED_CLASS), self::RENAMED_CLASS), ['allowed_classes' => false]);
        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $message);

        return $message;
    }

    /**
     * @return list<object>
     */
    private function messagesOf(InMemoryTransport $transport): array
    {
        return array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent());
    }
}
