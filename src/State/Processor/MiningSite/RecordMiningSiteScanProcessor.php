<?php

declare(strict_types=1);

namespace App\State\Processor\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\MiningSite\MiningSiteScanRecorder;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class RecordMiningSiteScanProcessor implements ProcessorInterface
{
    public function __construct(private MiningSiteScanRecorder $recorder)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        return null;
    }
}
