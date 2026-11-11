<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineReceivedStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\MessageDecodingFailedStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class FailedMessageService
{
    public const FAILED_MESSAGES_PAGE_SIZE = 100;
    public const EXCEPTION_MESSAGE_MAX_LENGTH = 500;
    public const CLASS_ACTION_LIMIT = 500;

    private const INCOMPLETE_CLASS_NAME_KEY = '__PHP_Incomplete_Class_Name';

    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly TransportInterface&ListableReceiverInterface $failedTransport,
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ContainerInterface $transports,
        #[Autowire(service: 'messenger.transport.native_php_serializer')]
        private readonly PhpSerializer $serializer,
    ) {
    }

    public function list(?string $messageClass): FailedMessageList
    {
        $envelopes = $this->readAll();

        $classCounts = array_count_values(array_map($this->messageClassOf(...), $envelopes));
        $classes = [];
        foreach ($classCounts as $class => $count) {
            $classes[] = new MessageClassCount((string) $class, $count);
        }
        usort($classes, static fn (MessageClassCount $left, MessageClassCount $right): int => [$right->count, $left->messageClass] <=> [$left->count, $right->messageClass]);

        $listed = null === $messageClass
            ? $envelopes
            : array_filter($envelopes, fn (Envelope $envelope): bool => $this->messageClassOf($envelope) === $messageClass);
        usort($listed, fn (Envelope $left, Envelope $right): int => (int) $this->idOf($right) <=> (int) $this->idOf($left));

        return new FailedMessageList(
            \count($envelopes),
            $classes,
            array_map($this->toFailedMessage(...), \array_slice($listed, 0, self::FAILED_MESSAGES_PAGE_SIZE)),
        );
    }

    public function retry(string $id): void
    {
        $envelope = $this->findOrFail($id);

        if (null !== $envelope->last(MessageDecodingFailedStamp::class)) {
            throw new FailedMessageNotRedeliverable(sprintf('The message class "%s" no longer exists.', $this->messageClassOf($envelope)));
        }

        $sentToFailure = $envelope->last(SentToFailureTransportStamp::class);
        if (!$sentToFailure instanceof SentToFailureTransportStamp) {
            throw new FailedMessageNotRedeliverable('The original transport of the message is unknown.');
        }

        $originalTransportName = $sentToFailure->getOriginalReceiverName();
        $originalTransport = $this->transports->has($originalTransportName) ? $this->transports->get($originalTransportName) : null;
        if (!$originalTransport instanceof SenderInterface) {
            throw new FailedMessageNotRedeliverable(sprintf('The original transport "%s" cannot be sent to.', $originalTransportName));
        }

        $originalTransport->send($envelope->withoutAll(ReceivedStamp::class)
            ->withoutAll(TransportMessageIdStamp::class)
            ->withoutAll(DoctrineReceivedStamp::class)
            ->withoutAll(RedeliveryStamp::class)
            ->withoutAll(SentToFailureTransportStamp::class));

        $this->onFailedTransport(fn () => $this->failedTransport->reject($envelope));
    }

    public function remove(string $id): void
    {
        $envelope = $this->findOrFail($id);

        $this->onFailedTransport(fn () => $this->failedTransport->reject($envelope));
    }

    public function retryClass(string $messageClass): FailedMessageClassOutcome
    {
        return $this->applyToClass($messageClass, $this->retry(...));
    }

    public function removeClass(string $messageClass): FailedMessageClassOutcome
    {
        return $this->applyToClass($messageClass, $this->remove(...));
    }

    /**
     * @param callable(string): void $action
     */
    private function applyToClass(string $messageClass, callable $action): FailedMessageClassOutcome
    {
        $classIds = array_map(
            $this->idOf(...),
            array_filter($this->readAll(), fn (Envelope $envelope): bool => $this->messageClassOf($envelope) === $messageClass),
        );
        usort($classIds, static fn (string $left, string $right): int => (int) $left <=> (int) $right);

        $batch = \array_slice($classIds, 0, self::CLASS_ACTION_LIMIT);
        foreach ($batch as $id) {
            $action($id);
        }

        return new FailedMessageClassOutcome(\count($batch), \count($classIds) - \count($batch));
    }

    /**
     * @return list<Envelope>
     */
    private function readAll(): array
    {
        return $this->onFailedTransport(fn (): array => array_values(iterator_to_array($this->failedTransport->all())));
    }

    private function findOrFail(string $id): Envelope
    {
        $envelope = $this->onFailedTransport(fn (): ?Envelope => $this->failedTransport->find($id));

        return $envelope ?? throw new FailedMessageNotFound(sprintf('Failed message "%s" not found.', $id));
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function onFailedTransport(callable $operation): mixed
    {
        $this->serializer->acceptPhpIncompleteClass();

        try {
            return $operation();
        } catch (TransportException $exception) {
            throw new FailedMessagesUnavailable('The failed messages transport is unreachable.', 0, $exception);
        } finally {
            $this->serializer->rejectPhpIncompleteClass();
        }
    }

    private function toFailedMessage(Envelope $envelope): FailedMessage
    {
        $errorDetails = $envelope->last(ErrorDetailsStamp::class);
        $redeliveries = $envelope->all(RedeliveryStamp::class);
        $lastRedelivery = $envelope->last(RedeliveryStamp::class);

        return new FailedMessage(
            $this->idOf($envelope),
            $this->messageClassOf($envelope),
            $errorDetails?->getExceptionClass(),
            null === $errorDetails ? null : mb_substr($errorDetails->getExceptionMessage(), 0, self::EXCEPTION_MESSAGE_MAX_LENGTH),
            null === $lastRedelivery ? null : \DateTimeImmutable::createFromInterface($lastRedelivery->getRedeliveredAt()),
            [] === $redeliveries ? null : max(array_map(static fn (RedeliveryStamp $stamp): int => $stamp->getRetryCount(), $redeliveries)),
        );
    }

    private function idOf(Envelope $envelope): string
    {
        $idStamp = $envelope->last(TransportMessageIdStamp::class);
        if (!$idStamp instanceof TransportMessageIdStamp) {
            throw new \LogicException('A failed message envelope carries no transport message id.');
        }

        return (string) $idStamp->getId();
    }

    private function messageClassOf(Envelope $envelope): string
    {
        $message = $envelope->getMessage();
        if (!$message instanceof \__PHP_Incomplete_Class) {
            return $message::class;
        }

        $vanishedClass = ((array) $message)[self::INCOMPLETE_CLASS_NAME_KEY];
        if (!\is_string($vanishedClass)) {
            throw new \UnexpectedValueException('The class of an undecodable failed message cannot be read.');
        }

        return $vanishedClass;
    }
}
