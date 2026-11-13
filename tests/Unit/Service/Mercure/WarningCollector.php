<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mercure;

use Psr\Log\AbstractLogger;

final class WarningCollector extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level === 'warning') {
            $this->warnings[] = (string) $message;
        }
    }
}
