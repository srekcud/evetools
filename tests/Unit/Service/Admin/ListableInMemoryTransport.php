<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

final class ListableInMemoryTransport extends InMemoryTransport implements ListableReceiverInterface, MessageCountAwareInterface
{
    private ?\Throwable $failure = null;
    private ?int $idRemovedRightAfterTheNextListing = null;

    public function failWith(\Throwable $failure): void
    {
        $this->failure = $failure;
    }

    public function removeRightAfterTheNextListing(int $id): void
    {
        $this->idRemovedRightAfterTheNextListing = $id;
    }

    public function get(): iterable
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return parent::get();
    }

    public function reject(Envelope $envelope): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        parent::reject($envelope);
    }

    public function all(?int $limit = null): iterable
    {
        $envelopes = $this->get();

        if (null !== $this->idRemovedRightAfterTheNextListing) {
            $removed = $this->find($this->idRemovedRightAfterTheNextListing);
            $this->idRemovedRightAfterTheNextListing = null;
            if (null !== $removed) {
                parent::reject($removed);
            }
        }

        return null === $limit ? $envelopes : \array_slice($envelopes, 0, $limit);
    }

    public function find(mixed $id): ?Envelope
    {
        foreach ($this->get() as $envelope) {
            $idStamp = $envelope->last(TransportMessageIdStamp::class);
            if ($idStamp instanceof TransportMessageIdStamp && (string) $idStamp->getId() === (string) $id) {
                return $envelope;
            }
        }

        return null;
    }

    public function getMessageCount(): int
    {
        return \count($this->get());
    }
}
