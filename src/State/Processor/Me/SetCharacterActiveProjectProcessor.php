<?php

declare(strict_types=1);

namespace App\State\Processor\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Me\SetCharacterActiveProjectInput;
use App\ApiResource\Me\CharacterActiveProjectResource;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\CharacterActiveProject;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<SetCharacterActiveProjectInput, CharacterActiveProjectResource>
 */
class SetCharacterActiveProjectProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CharacterRepository $characterRepository,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly CharacterActiveProject $characterActiveProject,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CharacterActiveProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $characterId = (string) $uriVariables['id'];

        if (!Uuid::isValid($characterId)) {
            throw new NotFoundHttpException('Character not found');
        }

        $character = $this->characterRepository->find($characterId);

        if ($character === null || $character->getUser() !== $user) {
            throw new NotFoundHttpException('Character not found');
        }

        if (!$data instanceof SetCharacterActiveProjectInput || !$this->projectIdIsProvided($data)) {
            throw new BadRequestHttpException('The projectId key is required');
        }

        $this->characterActiveProject->dedicate($user, $character, $this->findProject($data->projectId));

        $project = $character->getActiveProject();
        $resource = new CharacterActiveProjectResource();
        $resource->id = $characterId;
        $resource->activeProjectId = $project?->getId()->toRfc4122();
        $resource->activeProjectName = $project?->getName();

        return $resource;
    }

    private function projectIdIsProvided(SetCharacterActiveProjectInput $input): bool
    {
        return (new \ReflectionProperty($input, 'projectId'))->isInitialized($input);
    }

    private function findProject(?string $projectId): ?IndustryProject
    {
        if ($projectId === null) {
            return null;
        }

        if (!Uuid::isValid($projectId)) {
            throw new NotFoundHttpException('Project not found');
        }

        return $this->projectRepository->find($projectId)
            ?? throw new NotFoundHttpException('Project not found');
    }
}
