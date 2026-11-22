<?php

declare(strict_types=1);

namespace App\Exception;

final class InventionDataMissingException extends \RuntimeException
{
    public function __construct(int $productTypeId)
    {
        parent::__construct(\sprintf('No invention data found for product type ID %d', $productTypeId));
    }
}
