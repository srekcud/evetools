<?php

declare(strict_types=1);

namespace App\State\Processor\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Enum\BridgeTodoKey;
use App\Repository\BridgeAcknowledgementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/** @implements ProcessorInterface<mixed, void> */
class RemoveBridgeAcknowledgementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly BridgeAcknowledgementRepository $acknowledgementRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $itemKey = BridgeTodoKey::tryFrom((string) $uriVariables['itemKey']);
        $acknowledgement = $itemKey === null ? null : $this->acknowledgementRepository->findByUserAndItemKey($user, $itemKey);

        if ($acknowledgement === null) {
            throw new NotFoundHttpException('Acknowledgement not found');
        }

        $this->em->remove($acknowledgement);
        $this->em->flush();
    }
}
