<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/** A revoked or missing ESI grant never comes back by itself: Messenger must not retry it */
class EveAuthRequiredException extends \Exception implements UnrecoverableExceptionInterface
{
    public function __construct(
        public readonly string $characterId,
        string $message = 'EVE authentication required',
    ) {
        parent::__construct($message);
    }
}
