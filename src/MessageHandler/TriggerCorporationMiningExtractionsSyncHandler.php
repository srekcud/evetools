<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TriggerCorporationMiningExtractionsSync;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TriggerCorporationMiningExtractionsSyncHandler
{
    public function __invoke(TriggerCorporationMiningExtractionsSync $message): void
    {
    }
}
