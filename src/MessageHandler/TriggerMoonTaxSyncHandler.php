<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TriggerMoonTaxSync;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TriggerMoonTaxSyncHandler
{
    public function __invoke(TriggerMoonTaxSync $message): void
    {
    }
}
