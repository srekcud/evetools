<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckMiningSiteDepletionAlerts;
use App\Service\MiningSite\MiningSiteDepletionAlertSender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckMiningSiteDepletionAlertsHandler
{
    public function __construct(private MiningSiteDepletionAlertSender $sender)
    {
    }

    public function __invoke(CheckMiningSiteDepletionAlerts $message): void
    {
        $this->sender->sendDueAlerts();
    }
}
