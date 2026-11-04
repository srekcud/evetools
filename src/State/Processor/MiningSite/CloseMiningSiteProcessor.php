<?php

declare(strict_types=1);

namespace App\State\Processor\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\MiningSite\MiningSiteResource;
use App\Entity\MiningSite;
use App\Entity\User;
use App\Enum\MiningSiteStatus;
use App\Repository\MiningSiteRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningSite\MiningSiteRequest;
use App\State\Provider\MiningSite\MiningSiteResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @implements ProcessorInterface<EmptyInput, MiningSiteResource>
 */
final readonly class CloseMiningSiteProcessor implements ProcessorInterface
{
    private const string NOT_OWNER_NOR_ADMINISTRATOR_MESSAGE = 'Only the site owner or an operation admin can close this site';

    public function __construct(
        private Security $security,
        private MiningOperationAccess $access,
        private MiningSiteRepository $siteRepository,
        private EntityManagerInterface $entityManager,
        private MiningSiteResourceMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningSiteResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $site = MiningSiteRequest::siteOf($this->siteRepository, $this->access, $user, $uriVariables['id']);
        $this->assertCanClose($user, $site);
        $site->setStatus(MiningSiteStatus::Closed);
        $this->entityManager->flush();

        return $this->mapper->siteToResource($site);
    }

    private function assertCanClose(User $user, MiningSite $site): void
    {
        $operation = $site->getOperation();
        if ($site->getOwner() !== $user && ($operation === null || !$this->access->canAdminister($user, $operation))) {
            throw new AccessDeniedHttpException(self::NOT_OWNER_NOR_ADMINISTRATOR_MESSAGE);
        }
    }
}
