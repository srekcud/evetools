<?php

declare(strict_types=1);

namespace App\Industry\Application;

enum ResearchVerdict: string
{
    case Worth = 'worth';
    case NotWorth = 'not_worth';
    case Unknown = 'unknown';
}
