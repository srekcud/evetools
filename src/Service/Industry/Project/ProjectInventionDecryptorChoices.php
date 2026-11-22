<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Enum\ProjectStatus;
use App\Industry\Application\Decryptors;
use App\Industry\Application\InventionCatalog;

final readonly class ProjectInventionDecryptorChoices
{
    public function __construct(
        private IndustryProjectService $projectService,
        private InventionCatalog $inventions,
        private Decryptors $decryptors,
    ) {
    }

    /**
     * @param array<int|string, int|null> $requested
     *
     * @throws \InvalidArgumentException when a choice is refused: nothing is changed
     */
    public function change(IndustryProject $project, array $requested): void
    {
        $productsByTypeId = [];
        foreach ($project->getItems() as $product) {
            $productsByTypeId[$product->getTypeId()][] = $product;
        }

        $previous = $project->inventionDecryptorsByProductTypeId();
        $changes = [];
        foreach ($requested as $productTypeId => $decryptorTypeId) {
            $products = $productsByTypeId[(int) $productTypeId]
                ?? throw new \InvalidArgumentException(\sprintf('Type %d is not a final product of the project.', $productTypeId));
            $this->assertCanBeChosen($products[0], $decryptorTypeId);
            if (($previous[(int) $productTypeId] ?? null) !== $decryptorTypeId) {
                $changes[(int) $productTypeId] = $decryptorTypeId;
            }
        }

        if ([] === $changes) {
            return;
        }

        $this->apply($project, $changes + $previous);

        try {
            $this->projectService->buildBom($project, $project->getOwner(), refuseToDropWorkedLines: ProjectStatus::Draft !== $project->getStatus());
        } catch (\RuntimeException $e) {
            $this->apply($project, $previous);

            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<int, int|null> $decryptorTypeIdByProductTypeId
     */
    private function apply(IndustryProject $project, array $decryptorTypeIdByProductTypeId): void
    {
        foreach ($project->getItems() as $product) {
            $product->setInventionDecryptorTypeId($decryptorTypeIdByProductTypeId[$product->getTypeId()] ?? null);
        }
    }

    private function assertCanBeChosen(IndustryProjectProduct $product, ?int $decryptorTypeId): void
    {
        if (null === $this->inventions->inventionOf($product->getTypeId())) {
            throw new \InvalidArgumentException(\sprintf('%s has no invention: it has no decryptor.', $product->getTypeName()));
        }

        if (null !== $decryptorTypeId && null === $this->decryptors->ofType($decryptorTypeId)) {
            throw new \InvalidArgumentException(\sprintf('Type %d is not a decryptor.', $decryptorTypeId));
        }
    }
}
