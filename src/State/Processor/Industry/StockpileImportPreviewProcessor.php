<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\StockpileImportPreviewResource;
use App\ApiResource\Input\Industry\StockpileImportInput;
use App\Entity\User;
use App\Service\Industry\StockpileService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<StockpileImportInput, StockpileImportPreviewResource>
 */
class StockpileImportPreviewProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly StockpileService $stockpileService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockpileImportPreviewResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof StockpileImportInput);

        $preview = $this->stockpileService->previewImport($user, $data->typeId, $data->runs, $data->me, $data->te);

        $resource = new StockpileImportPreviewResource();
        $resource->stages = $preview['stages'];
        $resource->totalItems = $preview['totalItems'];
        $resource->estimatedCost = $preview['estimatedCost'];

        return $resource;
    }
}
