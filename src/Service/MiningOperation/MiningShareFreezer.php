<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningFrozenUnitPrice;
use App\Entity\MiningOperation;
use App\Enum\MinedQuantityState;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningShareBlocker;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class MiningShareFreezer
{
    private const string NOT_CLOSING_MESSAGE = 'Only a closing operation can have its shares frozen';
    private const string EACH_FOR_THEMSELVES_MESSAGE = 'An each-for-themselves operation has no shares';
    private const string QUANTITIES_NOT_KNOWN_MESSAGE = 'Every mined quantity must be known before freezing the shares: %s';
    private const string PRICES_MISSING_MESSAGE = 'Some ores have no price on the valuation basis: %s';
    private const string BOOSTER_MISSING_MESSAGE = 'Choose the booster who receives the booster cut';

    public function __construct(
        private MiningOperationValuator $valuator,
        private TypeNameResolver $typeNameResolver,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function freeze(MiningOperation $operation): void
    {
        if ($operation->getStatus() !== MiningOperationStatus::Closing) {
            throw new BadRequestHttpException(self::NOT_CLOSING_MESSAGE);
        }

        $valuation = $this->valuator->value($operation);
        $blocker = $valuation->freezeBlockers[0] ?? null;
        if ($blocker !== null) {
            throw new BadRequestHttpException($this->refusalOf($blocker, $valuation));
        }

        $unitPrices = [];
        foreach ($valuation->unitPricesByTypeId as $typeId => $unitPrice) {
            $unitPrices[] = new MiningFrozenUnitPrice($operation, $typeId, $unitPrice ?? throw new \LogicException('A price is known once no price is missing'));
        }

        $minedValues = [];
        foreach ($valuation->participants as $position => $participant) {
            $minedValues[] = new MiningFrozenMinedValue(
                $operation,
                $participant->member,
                $participant->participantName,
                $position,
                (float) ($participant->minedValue ?? throw new \LogicException('A mined value is known once every quantity and price is known')),
            );
        }

        $operation->freezeShares($this->clock->now(), $valuation->pricesSyncedAt, $unitPrices, $minedValues);
        $this->entityManager->flush();
    }

    private function refusalOf(MiningShareBlocker $blocker, MiningOperationValuation $valuation): string
    {
        return match ($blocker) {
            MiningShareBlocker::NotClosing => self::NOT_CLOSING_MESSAGE,
            MiningShareBlocker::EachForThemselves => self::EACH_FOR_THEMSELVES_MESSAGE,
            MiningShareBlocker::QuantitiesNotKnown => sprintf(self::QUANTITIES_NOT_KNOWN_MESSAGE, implode(', ', $this->unknownParticipantNamesOf($valuation))),
            MiningShareBlocker::PricesMissing => sprintf(self::PRICES_MISSING_MESSAGE, implode(', ', $this->missingTypeNamesOf($valuation))),
            MiningShareBlocker::BoosterMissing => self::BOOSTER_MISSING_MESSAGE,
        };
    }

    /**
     * @return list<string>
     */
    private function unknownParticipantNamesOf(MiningOperationValuation $valuation): array
    {
        $names = [];
        foreach ($valuation->participants as $participant) {
            if ($participant->quantity?->state !== MinedQuantityState::Known) {
                $names[] = (string) $participant->participantName;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function missingTypeNamesOf(MiningOperationValuation $valuation): array
    {
        $names = $this->typeNameResolver->findNames($valuation->missingPriceTypeIds);

        return array_map(
            static fn (int $typeId): string => $names[$typeId] ?? sprintf('Type %d', $typeId),
            $valuation->missingPriceTypeIds,
        );
    }
}
