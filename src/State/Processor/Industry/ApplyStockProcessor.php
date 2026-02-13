<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\ApplyStockInput;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\InvTypeRepository;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<ApplyStockInput, array>
 */
class ApplyStockProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return ProjectStepResource[]
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        assert($data instanceof ApplyStockInput);

        // Resolve type names to type IDs
        $stockByTypeId = [];
        foreach ($data->items as $item) {
            $typeName = $item['typeName'];
            $quantity = $item['quantity'];

            if ($typeName === '' || $quantity <= 0) {
                continue;
            }

            $type = $this->invTypeRepository->findOneBy(['typeName' => $typeName]);
            if ($type !== null) {
                $typeId = $type->getTypeId();
                $stockByTypeId[$typeId] = ($stockByTypeId[$typeId] ?? 0) + $quantity;
            }
        }

        // Apply stock to matching steps
        $updatedSteps = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getDepth() === 0 || $step->getActivityType() === 'copy') {
                continue;
            }

            $typeId = $step->getProductTypeId();
            if (isset($stockByTypeId[$typeId])) {
                $step->setInStockQuantity($stockByTypeId[$typeId]);
                $updatedSteps[] = $step;
            }
        }

        $this->entityManager->flush();

        return array_map(fn ($s) => $this->mapper->stepToResource($s), $updatedSteps);
    }
}
