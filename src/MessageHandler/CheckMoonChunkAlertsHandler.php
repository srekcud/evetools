<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckMoonChunkAlerts;
use App\Service\MoonChunk\MoonChunkAlertSender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckMoonChunkAlertsHandler
{
    public function __construct(private MoonChunkAlertSender $sender)
    {
    }

    public function __invoke(CheckMoonChunkAlerts $message): void
    {
        $this->sender->sendDueAlerts();
    }
}
