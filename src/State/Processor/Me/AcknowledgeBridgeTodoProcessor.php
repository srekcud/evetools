<?php

declare(strict_types=1);

namespace App\State\Processor\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Me\CreateBridgeAcknowledgementInput;
use App\ApiResource\Me\BridgeAcknowledgementResource;
use App\Entity\BridgeAcknowledgement;
use App\Entity\User;
use App\Enum\BridgeTodoKey;
use App\Repository\BridgeAcknowledgementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/** @implements ProcessorInterface<CreateBridgeAcknowledgementInput, BridgeAcknowledgementResource> */
class AcknowledgeBridgeTodoProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly BridgeAcknowledgementRepository $acknowledgementRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BridgeAcknowledgementResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateBridgeAcknowledgementInput);

        $itemKey = BridgeTodoKey::from($data->itemKey);
        $acknowledgement = $this->acknowledgementRepository->findByUserAndItemKey($user, $itemKey);

        if ($acknowledgement === null) {
            $acknowledgement = (new BridgeAcknowledgement())
                ->setUser($user)
                ->setItemKey($itemKey)
                ->setAcknowledgedCount($data->count);
            $this->em->persist($acknowledgement);
        } else {
            $acknowledgement->setAcknowledgedCount($data->count);
        }

        $this->em->flush();

        $resource = new BridgeAcknowledgementResource();
        $resource->itemKey = $acknowledgement->getItemKey()->value;
        $resource->acknowledgedCount = $acknowledgement->getAcknowledgedCount();

        return $resource;
    }
}
