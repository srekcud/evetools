<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Input\Industry\CreateProjectInput;
use App\Entity\User;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\ProjectEstimatesRefresher;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateProjectInput, ProjectResource>
 */
class CreateProjectProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectFactory $projectFactory,
        private readonly IndustryResourceMapper $mapper,
        private readonly ProjectEstimatesRefresher $estimatesRefresher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateProjectInput);

        try {
            $project = $this->projectFactory->createProject(
                $user,
                $data->typeId,
                $data->runs,
                $data->meLevel,
                $data->maxJobDurationDays,
                $data->teLevel,
                $data->name,
                $data->excludedTypeIds,
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $this->estimatesRefresher->refresh($project);

        $this->entityManager->flush();

        return $this->mapper->projectToResource($project);
    }
}
