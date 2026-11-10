<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use Symfony\Component\Uid\Uuid;

final readonly class ProjectPaymentReference
{
    private const string PREFIX = 'IP-';
    private const int HEX_LENGTH = 6;

    private const string ANY_REFERENCE_PATTERN = '/\bIP-[0-9A-F]{6}\b/i';

    public static function of(Uuid $projectId): string
    {
        return self::PREFIX.strtoupper(substr(str_replace('-', '', $projectId->toRfc4122()), 0, self::HEX_LENGTH));
    }

    public static function appearsIn(string $text): bool
    {
        return preg_match(self::ANY_REFERENCE_PATTERN, $text) === 1;
    }

    public static function isMentionedIn(Uuid $projectId, ?string $text): bool
    {
        return $text !== null && str_contains(strtoupper($text), self::of($projectId));
    }
}
