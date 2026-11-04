<?php

declare(strict_types=1);

namespace App\State\Processor\Mining;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mining\MoonCompositionResource;
use App\Service\MoonChunk\MoonCompositionRecorder;

/**
 * @implements ProcessorInterface<MoonCompositionResource, MoonCompositionResource>
 */
final readonly class RecordMoonCompositionProcessor implements ProcessorInterface
{
    public function __construct(private MoonCompositionRecorder $recorder)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MoonCompositionResource
    {
        \assert($data instanceof MoonCompositionResource);

        return $data;
    }
}
