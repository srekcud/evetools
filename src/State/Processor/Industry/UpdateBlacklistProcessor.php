<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\BlacklistResource;
use App\ApiResource\Input\Industry\UpdateBlacklistInput;
use App\Entity\User;
use App\Service\Industry\IndustryBlacklistService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<UpdateBlacklistInput, BlacklistResource>
 */
class UpdateBlacklistProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryBlacklistService $blacklistService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BlacklistResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        if (!$data instanceof UpdateBlacklistInput) {
            throw new BadRequestHttpException('Invalid input');
        }

        if ($data->groupIds !== null) {
            $user->setIndustryBlacklistGroupIds(array_map('intval', $data->groupIds));
        }
        if ($data->typeIds !== null) {
            $user->setIndustryBlacklistTypeIds(array_map('intval', $data->typeIds));
        }

        $this->entityManager->flush();

        $resource = new BlacklistResource();
        $resource->categories = $this->blacklistService->getCategories($user);
        $resource->items = $this->blacklistService->getBlacklistedItems($user);

        return $resource;
    }
}
