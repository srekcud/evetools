<?php

declare(strict_types=1);

namespace App\Exception;

class EveAuthRequiredException extends \Exception
{
    public function __construct(
        public readonly string $characterId,
        string $message = 'EVE authentication required',
    ) {
        parent::__construct($message);
    }
}
