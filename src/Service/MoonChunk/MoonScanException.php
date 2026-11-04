<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

final class MoonScanException extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly ?int $lineNumber = null)
    {
        parent::__construct($message);
    }
}
