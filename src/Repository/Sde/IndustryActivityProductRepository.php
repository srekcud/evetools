<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\IndustryActivityProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * @extends ServiceEntityRepository<IndustryActivityProduct>
 */
class IndustryActivityProductRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($registry, IndustryActivityProduct::class);
    }

    /**
     * Find blueprint that produces a given type.
     * If multiple blueprints exist (test blueprints in SDE), logs a warning
     * and returns the one with the highest output per run.
     */
    public function findBlueprintForProduct(int $productTypeId, int $activityId = 1): ?IndustryActivityProduct
    {
        $results = $this->findBy(
            ['productTypeId' => $productTypeId, 'activityId' => $activityId],
            ['quantity' => 'DESC'],
        );

        if (count($results) === 0) {
            return null;
        }

        if (count($results) > 1) {
            $this->logger->warning('Multiple blueprints found for same product, using highest output (likely test blueprints in SDE)', [
                'productTypeId' => $productTypeId,
                'activityId' => $activityId,
                'count' => count($results),
                'selectedQuantity' => $results[0]->getQuantity(),
            ]);
        }

        return $results[0];
    }
}
