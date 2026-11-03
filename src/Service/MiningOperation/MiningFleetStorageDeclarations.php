<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Enum\MiningFleetStorageDelivery;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningSplitMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class MiningFleetStorageDeclarations
{
    use LocksMiningOperation;

    private const string EACH_FOR_THEMSELVES_MESSAGE = 'An each-for-themselves operation has no fleet storage';
    private const string FROZEN_MESSAGE = 'The shares of this operation are frozen';
    private const string RECIPIENT_NOT_ACCEPTED_MESSAGE = 'The fleet storage recipient must be an accepted participant';
    private const string MEMBER_NOT_ACCEPTED_MESSAGE = 'Only an accepted participant declares a fleet storage';
    private const string NO_RECIPIENT_MESSAGE = 'Choose the fleet storage recipient first';
    private const string RECIPIENT_KEEPS_MESSAGE = 'The fleet storage recipient keeps the ore delivered to them';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function assertEditable(MiningOperation $operation): void
    {
        if ($operation->getSplitMode() === MiningSplitMode::EachForThemselves) {
            throw new BadRequestHttpException(self::EACH_FOR_THEMSELVES_MESSAGE);
        }
        if (!$operation->getStatus()->isOpen()) {
            throw new BadRequestHttpException(self::FROZEN_MESSAGE);
        }
    }

    public function assign(MiningOperation $operation, ?MiningOperationMember $recipient, ?string $containerName): void
    {
        $this->underLockOf($operation, function () use ($operation, $recipient, $containerName): void {
            $this->assertEditable($operation);
            if ($recipient !== null && $recipient !== $operation->getFleetStorageRecipient() && ($recipient->getOperation() !== $operation || $recipient->getStatus() !== MiningOperationMemberStatus::Accepted)) {
                throw new BadRequestHttpException(self::RECIPIENT_NOT_ACCEPTED_MESSAGE);
            }

            $operation->assignFleetStorage($recipient, $containerName);
            $this->entityManager->flush();
        });
    }

    /**
     * @param array<int, int>|null $quantitiesByTypeId
     */
    public function declare(MiningOperationMember $member, MiningFleetStorageDelivery $delivery, ?array $quantitiesByTypeId): void
    {
        $operation = $member->getOperation();
        $this->underLockOf($operation, function () use ($operation, $member, $delivery, $quantitiesByTypeId): void {
            $this->assertEditable($operation);
            if ($member->getStatus() !== MiningOperationMemberStatus::Accepted) {
                throw new BadRequestHttpException(self::MEMBER_NOT_ACCEPTED_MESSAGE);
            }
            if ($delivery !== MiningFleetStorageDelivery::None) {
                $recipient = $operation->getFleetStorageRecipient() ?? throw new BadRequestHttpException(self::NO_RECIPIENT_MESSAGE);
                if ($recipient === $member) {
                    throw new BadRequestHttpException(self::RECIPIENT_KEEPS_MESSAGE);
                }
            }

            $member->declareFleetStorage($delivery, $quantitiesByTypeId);
            $this->entityManager->flush();
        });
    }
}
