<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class UuidOrNotFound
{
    public static function of(string $identifier, string $notFoundMessage): Uuid
    {
        if (!Uuid::isValid($identifier)) {
            throw new NotFoundHttpException($notFoundMessage);
        }

        return Uuid::fromString($identifier);
    }
}
