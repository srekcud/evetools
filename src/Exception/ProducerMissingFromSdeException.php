<?php

declare(strict_types=1);

namespace App\Exception;

final class ProducerMissingFromSdeException extends \RuntimeException
{
    public function __construct(int $productTypeId)
    {
        parent::__construct(\sprintf('No blueprint or reaction found for product type ID %d', $productTypeId));
    }
}
