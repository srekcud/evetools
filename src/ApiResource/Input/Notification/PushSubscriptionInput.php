<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Notification;

class PushSubscriptionInput
{
    public string $endpoint;

    public string $publicKey;

    public string $authToken;
}
