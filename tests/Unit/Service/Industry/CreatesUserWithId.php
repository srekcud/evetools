<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\User;
use Symfony\Component\Uid\Uuid;

trait CreatesUserWithId
{
    private function userWithId(?string $uuid = null): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, null === $uuid ? Uuid::v4() : Uuid::fromString($uuid));

        return $user;
    }
}
