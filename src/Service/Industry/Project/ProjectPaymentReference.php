<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use Symfony\Component\Uid\Uuid;

final readonly class ProjectPaymentReference
{
    private const string PREFIX = 'IP-';
    private const int HEX_LENGTH = 6;

    public static function of(Uuid $projectId): string
    {
        return self::PREFIX.strtoupper(substr(str_replace('-', '', $projectId->toRfc4122()), 0, self::HEX_LENGTH));
    }
}
