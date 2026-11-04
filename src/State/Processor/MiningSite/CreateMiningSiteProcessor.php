<?php

declare(strict_types=1);

namespace App\State\Processor\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class CreateMiningSiteProcessor implements ProcessorInterface
{
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        return null;
    }
}
