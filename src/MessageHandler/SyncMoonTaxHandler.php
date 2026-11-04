<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncMoonTax;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncMoonTaxHandler
{
    public function __invoke(SyncMoonTax $message): void
    {
    }
}
