<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use App\ApiResource\Industry\Project\ProjectSettlementResource;
use App\Entity\User;
use App\Service\Industry\Project\ProjectSettlement;
use App\Service\Industry\Project\SettlementTransfer;
use App\Service\Industry\Project\UnsoldProduct;

final readonly class ProjectSettlementResourceMapper
{
    private const float CENTS_PER_ISK = 100.0;

    public function map(string $projectId, ProjectSettlement $settlement, User $viewer): ProjectSettlementResource
    {
        $viewerId = $viewer->getId()?->toRfc4122();

        $resource = new ProjectSettlementResource();
        $resource->projectId = $projectId;
        $resource->reference = $settlement->reference;
        $resource->transfers = $settlement->transfers === null ? null : array_map(
            static fn (SettlementTransfer $transfer): array => [
                'fromParticipantId' => $transfer->fromParticipantId,
                'fromName' => $transfer->fromName,
                'toParticipantId' => $transfer->toParticipantId,
                'toName' => $transfer->toName,
                'amount' => $transfer->amountCents / self::CENTS_PER_ISK,
                'received' => $transfer->isReceived(),
                'journalVerified' => $transfer->journalVerified,
                'receivedAt' => $transfer->receivedAt?->format(\DateTimeInterface::ATOM),
                'canConfirm' => $viewerId !== null && $transfer->toUserId === $viewerId,
            ],
            $settlement->transfers,
        );
        $resource->transfersUnavailableReason = $settlement->transfersUnavailableReason?->value;
        $resource->roundingGap = $settlement->roundingGapCents / self::CENTS_PER_ISK;
        $resource->checks = [
            'pendingContributionCount' => $settlement->checks->pendingContributionCount,
            'unsoldProducts' => array_map(
                static fn (UnsoldProduct $product): array => ['typeId' => $product->typeId, 'typeName' => $product->typeName, 'quantity' => $product->unsoldQuantity],
                $settlement->checks->unsoldProducts,
            ),
            'marginPercent' => $settlement->checks->marginPercent,
            'transfersTotal' => $settlement->checks->transfersTotal,
            'transfersReceived' => $settlement->checks->transfersReceived,
        ];

        return $resource;
    }
}
