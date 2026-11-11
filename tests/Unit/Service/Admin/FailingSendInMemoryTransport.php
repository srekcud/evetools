<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class FailingSendInMemoryTransport extends InMemoryTransport
{
    private ?\Throwable $failure = null;

    public function failWith(\Throwable $failure): void
    {
        $this->failure = $failure;
    }

    public function send(Envelope $envelope): Envelope
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return parent::send($envelope);
    }
}
