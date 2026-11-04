<?php

declare(strict_types=1);

namespace App\State\Processor\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\MiningSite\MiningSiteResource;
use App\Enum\MiningSiteStatus;
use App\Repository\MiningSiteRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningSite\MiningSiteRequest;
use App\State\Provider\MiningSite\MiningSiteResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<EmptyInput, MiningSiteResource>
 */
final readonly class CloseMiningSiteProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningSiteRepository $siteRepository,
        private EntityManagerInterface $entityManager,
        private MiningSiteResourceMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningSiteResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $site = MiningSiteRequest::siteOf($this->siteRepository, $user, $uriVariables['id']);
        $site->setStatus(MiningSiteStatus::Closed);
        $this->entityManager->flush();

        return $this->mapper->siteToResource($site);
    }
}
