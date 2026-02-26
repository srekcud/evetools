<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ScannerFavoriteResource;
use App\ApiResource\Input\Industry\CreateScannerFavoriteInput;
use App\Entity\IndustryScannerFavorite;
use App\Entity\User;
use App\Repository\IndustryScannerFavoriteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateScannerFavoriteInput, ScannerFavoriteResource>
 */
class CreateScannerFavoriteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryScannerFavoriteRepository $favoriteRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ScannerFavoriteResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateScannerFavoriteInput);

        // Idempotent: return existing if already favorited
        $existing = $this->favoriteRepository->findByUserAndTypeId($user, $data->typeId);
        if ($existing !== null) {
            $resource = new ScannerFavoriteResource();
            $resource->typeId = $existing->getTypeId();
            $resource->createdAt = $existing->getCreatedAt()->format(\DateTimeInterface::ATOM);

            return $resource;
        }

        $entity = new IndustryScannerFavorite();
        $entity->setUser($user);
        $entity->setTypeId($data->typeId);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        $resource = new ScannerFavoriteResource();
        $resource->typeId = $entity->getTypeId();
        $resource->createdAt = $entity->getCreatedAt()->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
