<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Input\Industry\CreateProjectInput;
use App\Entity\User;
use App\Service\Industry\IndustryProjectService;
use App\State\Provider\Industry\IndustryResourceMapper;
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
        private readonly IndustryProjectService $projectService,
        private readonly IndustryResourceMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        if (!$data instanceof CreateProjectInput) {
            throw new BadRequestHttpException('Invalid input');
        }

        try {
            $project = $this->projectService->createProject(
                $user,
                $data->typeId,
                $data->runs,
                $data->meLevel,
                $data->maxJobDurationDays,
                $data->teLevel,
                $data->name
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return $this->mapper->projectToResource($project);
    }
}
