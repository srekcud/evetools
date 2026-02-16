<?php

declare(strict_types=1);

namespace App\State\Provider\Notification;

use App\ApiResource\Notification\NotificationResource;
use App\Entity\Notification;

class NotificationResourceMapper
{
    public static function toResource(Notification $notification): NotificationResource
    {
        $resource = new NotificationResource();
        $resource->id = $notification->getId()?->toRfc4122() ?? '';
        $resource->category = $notification->getCategory();
        $resource->level = $notification->getLevel();
        $resource->title = $notification->getTitle();
        $resource->message = $notification->getMessage();
        $resource->data = $notification->getData();
        $resource->route = $notification->getRoute();
        $resource->isRead = $notification->isRead();
        $resource->createdAt = $notification->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
