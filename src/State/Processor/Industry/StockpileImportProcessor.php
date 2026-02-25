<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\StockpileImportResource;
use App\ApiResource\Input\Industry\StockpileImportInput;
use App\Entity\User;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\Industry\StockpileService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<StockpileImportInput, StockpileImportResource>
 */
class StockpileImportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly StockpileService $stockpileService,
        private readonly IndustryStockpileTargetRepository $targetRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockpileImportResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof StockpileImportInput);

        $this->stockpileService->importFromBlueprint($user, $data->typeId, $data->runs, $data->me, $data->te, $data->mode);

        $resource = new StockpileImportResource();
        $resource->status = 'success';
        $resource->importedCount = count($this->targetRepository->findByUser($user));

        return $resource;
    }
}
