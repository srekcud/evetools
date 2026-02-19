<?php

declare(strict_types=1);

namespace App\State\Processor\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Market\CreateFavoriteInput;
use App\ApiResource\Market\MarketFavoriteResource;
use App\Entity\MarketFavorite;
use App\Entity\User;
use App\Repository\MarketFavoriteRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateFavoriteInput, MarketFavoriteResource>
 */
class AddFavoriteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MarketFavoriteRepository $favoriteRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly JitaMarketService $jitaMarketService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MarketFavoriteResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateFavoriteInput);

        // Verify type exists
        $invType = $this->invTypeRepository->findByTypeId($data->typeId);
        if ($invType === null) {
            throw new BadRequestHttpException('Invalid type ID');
        }

        // Check for duplicate
        $existing = $this->favoriteRepository->findByUserAndType($user, $data->typeId);
        if ($existing !== null) {
            throw new ConflictHttpException('Already in favorites');
        }

        $favorite = new MarketFavorite();
        $favorite->setUser($user);
        $favorite->setTypeId($data->typeId);

        $this->em->persist($favorite);
        $this->em->flush();

        $resource = new MarketFavoriteResource();
        $resource->typeId = $invType->getTypeId();
        $resource->typeName = $invType->getTypeName();
        $resource->jitaSell = $this->jitaMarketService->getPricesWithFallback([$data->typeId])[$data->typeId] ?? null;
        $resource->jitaBuy = $this->jitaMarketService->getBuyPricesWithFallback([$data->typeId])[$data->typeId] ?? null;
        $resource->createdAt = $favorite->getCreatedAt()->format('c');

        return $resource;
    }
}
