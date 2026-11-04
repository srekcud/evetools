<?php

declare(strict_types=1);

namespace App\Enum;

enum MoonOreRarity: string
{
    case R4 = 'r4';
    case R8 = 'r8';
    case R16 = 'r16';
    case R32 = 'r32';
    case R64 = 'r64';

    public static function fromGroupId(int $groupId): ?self
    {
        return match ($groupId) {
            1884 => self::R4,
            1920 => self::R8,
            1921 => self::R16,
            1922 => self::R32,
            1923 => self::R64,
            default => null,
        };
    }
}
