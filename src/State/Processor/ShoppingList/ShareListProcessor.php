<?php

declare(strict_types=1);

namespace App\State\Processor\ShoppingList;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\ShoppingList\ShareListInput;
use App\ApiResource\ShoppingList\SharedShoppingListResource;
use App\Entity\SharedShoppingList;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProcessorInterface<ShareListInput, SharedShoppingListResource>
 */
class ShareListProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SharedShoppingListResource
    {
        /** @var ShareListInput $data */
        $user = $this->security->getUser();

        $sharedList = new SharedShoppingList();
        $sharedList->setData([
            'items' => $data->items,
            'notFound' => $data->notFound,
            'totals' => $data->totals,
            'transportCostPerM3' => $data->transportCostPerM3,
            'structureId' => $data->structureId,
            'structureName' => $data->structureName,
        ]);

        if ($user instanceof User) {
            $sharedList->setCreatedBy($user);
        }

        $this->entityManager->persist($sharedList);
        $this->entityManager->flush();

        // Build share URL
        $request = $this->requestStack->getCurrentRequest();
        $baseUrl = $request ? $request->getSchemeAndHttpHost() : '';
        $shareUrl = $baseUrl . '/appraisal/shared/' . $sharedList->getToken();

        $resource = new SharedShoppingListResource();
        $resource->token = $sharedList->getToken();
        $resource->items = $data->items;
        $resource->notFound = $data->notFound;
        $resource->totals = $data->totals;
        $resource->transportCostPerM3 = $data->transportCostPerM3;
        $resource->structureId = $data->structureId;
        $resource->structureName = $data->structureName;
        $resource->createdAt = $sharedList->getCreatedAt()->format('c');
        $resource->expiresAt = $sharedList->getExpiresAt()->format('c');
        $resource->shareUrl = $shareUrl;

        return $resource;
    }
}
