<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectWalletRefreshResource;
use App\ApiResource\Input\EmptyInput;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Sync\ContributorWalletRefresher;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<EmptyInput, ProjectWalletRefreshResource>
 */
final readonly class RefreshProjectWalletProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ContributorWalletRefresher $walletRefresher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectWalletRefreshResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of((string) ($uriVariables['projectId'] ?? ''), 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        if ($project->isCompleted()) {
            throw new BadRequestHttpException('The project is completed, its wallet cannot be refreshed');
        }

        $refresh = $this->walletRefresher->request($user);

        $result = new ProjectWalletRefreshResource();
        $result->projectId = $project->getId()->toRfc4122();
        $result->queuedCharacters = $refresh->queuedCharacters;
        $result->notDueCharacters = $refresh->notDueCharacters;

        return $result;
    }
}
