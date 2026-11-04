<?php

declare(strict_types=1);

namespace App\State\Processor\MiningSite;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\MiningSite\CreateMiningSiteInput;
use App\ApiResource\MiningSite\MiningSiteResource;
use App\Entity\MiningSite;
use App\Repository\MiningOperationRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use App\State\Provider\MiningSite\MiningSiteResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<CreateMiningSiteInput, MiningSiteResource>
 */
final readonly class CreateMiningSiteProcessor implements ProcessorInterface
{
    private const string UNKNOWN_SOLAR_SYSTEM_MESSAGE = 'Unknown solar system';

    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private EntityManagerInterface $entityManager,
        private MapSolarSystemRepository $solarSystemRepository,
        private MiningSiteResourceMapper $mapper,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningSiteResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        \assert($data instanceof CreateMiningSiteInput);

        if ($data->solarSystemId !== null && $this->solarSystemRepository->findBySolarSystemId($data->solarSystemId) === null) {
            throw new ValidationException(new ConstraintViolationList([
                new ConstraintViolation(self::UNKNOWN_SOLAR_SYSTEM_MESSAGE, self::UNKNOWN_SOLAR_SYSTEM_MESSAGE, [], $data->solarSystemId, 'solarSystemId', $data->solarSystemId),
            ]));
        }

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
