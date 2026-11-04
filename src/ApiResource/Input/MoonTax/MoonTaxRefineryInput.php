<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MoonTax;

use Symfony\Component\Validator\Constraints as Assert;

class MoonTaxRefineryInput
{
    private const int MIN_RATE_PERCENT = 0;
    private const int MAX_RATE_PERCENT = 100;
    private const int FIRST_WALLET_DIVISION = 1;
    private const int LAST_WALLET_DIVISION = 7;

    #[Assert\NotNull]
    public ?bool $openToAlliance = null;

    #[Assert\NotNull]
    #[Assert\Choice(choices: ['weekly', 'monthly'])]
    public ?string $period = null;

    #[Assert\NotNull]
    #[Assert\Range(min: self::MIN_RATE_PERCENT, max: self::MAX_RATE_PERCENT)]
    public ?float $rateR4 = null;

    #[Assert\NotNull]
    #[Assert\Range(min: self::MIN_RATE_PERCENT, max: self::MAX_RATE_PERCENT)]
    public ?float $rateR8 = null;

    #[Assert\NotNull]
    #[Assert\Range(min: self::MIN_RATE_PERCENT, max: self::MAX_RATE_PERCENT)]
    public ?float $rateR16 = null;

    #[Assert\NotNull]
    #[Assert\Range(min: self::MIN_RATE_PERCENT, max: self::MAX_RATE_PERCENT)]
    public ?float $rateR32 = null;

    #[Assert\NotNull]
    #[Assert\Range(min: self::MIN_RATE_PERCENT, max: self::MAX_RATE_PERCENT)]
    public ?float $rateR64 = null;

    #[Assert\NotNull]
    #[Assert\Range(min: self::FIRST_WALLET_DIVISION, max: self::LAST_WALLET_DIVISION)]
    public ?int $walletDivision = null;
}
