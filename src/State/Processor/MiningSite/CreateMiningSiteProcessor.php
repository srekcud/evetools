<?php

declare(strict_types=1);

namespace App\State\Processor\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MiningSite\CreateMiningSiteInput;
use App\ApiResource\MiningSite\MiningSiteResource;
use App\Entity\MiningSite;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningSite\MiningSiteSolarSystemGuard;
use App\State\Provider\MiningSite\MiningSiteResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;

/**
 * @implements ProcessorInterface<CreateMiningSiteInput, MiningSiteResource>
 */
final readonly class CreateMiningSiteProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private EntityManagerInterface $entityManager,
        private MiningSiteSolarSystemGuard $solarSystemGuard,
        private MiningSiteResourceMapper $mapper,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningSiteResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        \assert($data instanceof CreateMiningSiteInput);

        $this->solarSystemGuard->assertKnown($data->solarSystemId);

        $linkedOperation = $data->operationId === null
            ? null
            : MiningOperationRequest::participatedOperationOf($this->operationRepository, $this->access, $user, $data->operationId);
        if ($linkedOperation !== null) {
            MiningOperationRequest::assertNotArchived($linkedOperation);
        }

        $site = (new MiningSite())
            ->setOwner($user)
            ->setOperation($linkedOperation)
            ->setLabel(trim($data->label))
            ->setSolarSystemId($data->solarSystemId)
            ->setCreatedAt($this->clock->now());
        $this->entityManager->persist($site);
        $this->entityManager->flush();

        return $this->mapper->siteToResource($site);
    }
}
