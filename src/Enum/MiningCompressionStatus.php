<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningCompressionStatus: string
{
    case OnSite = 'on_site';
    case NoHullCompressor = 'no_hull_compressor';
    case NotMounted = 'not_mounted';
    case UnknownOreKind = 'unknown_ore_kind';
    case NoBoostShip = 'no_boost_ship';
}
