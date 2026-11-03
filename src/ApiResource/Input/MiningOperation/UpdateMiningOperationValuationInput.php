<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningOperation;

use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class UpdateMiningOperationValuationInput
{
    #[Assert\Choice(choices: ['raw', 'compressed', 'reprocessed'])]
    public ?string $valuationForm;

    #[Assert\Choice(choices: ['jita', 'structure'])]
    public ?string $valuationMarket;

    #[Assert\Choice(choices: ['buy', 'sell'])]
    public ?string $valuationSide;

    #[Assert\Positive]
    public ?int $structureId;

    #[Assert\Length(min: 1, max: 255)]
    public ?string $structureName;

    #[Assert\Positive]
    #[Assert\LessThanOrEqual(1)]
    public ?float $reprocessYield;

    #[Assert\Range(min: 0, max: 100)]
    public ?int $boosterCutPercent;

    #[Assert\Range(min: 0, max: 100)]
    public ?int $corporationCutPercent;

    #[Assert\Uuid]
    public ?string $boosterMemberId;

    #[Assert\Choice(callback: [MiningBoostShip::class, 'values'])]
    public ?int $boostShipTypeId;

    /** @var list<string> */
    #[Assert\Unique]
    #[Assert\All([new Assert\Choice(callback: [MiningCompressorKind::class, 'values'])])]
    public array $mountedCompressorKinds;

    #[Assert\Callback]
    public function validateMountedCompressorKinds(ExecutionContextInterface $context): void
    {
        $boostShipProvided = (new \ReflectionProperty($this, 'boostShipTypeId'))->isInitialized($this);
        $kindsProvided = (new \ReflectionProperty($this, 'mountedCompressorKinds'))->isInitialized($this);

        if ($boostShipProvided && null !== $this->boostShipTypeId && !$kindsProvided) {
            $context->buildViolation('The mounted compressors are required with a boost ship')->atPath('mountedCompressorKinds')->addViolation();
        }
    }
}
