<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Why a cost is unknown (spec R10): the data that was absent, and the typeId it belongs to when there is one.
 */
final readonly class MissingData
{
    private const string ADJUSTED_PRICE = 'adjusted_price';
    private const string COST_INDEX = 'cost_index';
    private const string INVENTION_PROBABILITY = 'invention_probability';
    private const string ITEM_VOLUME = 'item_volume';
    private const string JUMP_ROUTE = 'jump_route';
    private const string INVENTOR_SKILLS = 'inventor_skills';
    private const string MARKET_PRICE = 'market_price';

    private function __construct(public string $reason, public ?int $typeId)
    {
    }

    public static function adjustedPrice(int $typeId): self
    {
        return new self(self::ADJUSTED_PRICE, $typeId);
    }

    public static function marketPrice(int $typeId): self
    {
        return new self(self::MARKET_PRICE, $typeId);
    }

    public static function costIndex(): self
    {
        return new self(self::COST_INDEX, null);
    }

    public static function itemVolume(int $typeId): self
    {
        return new self(self::ITEM_VOLUME, $typeId);
    }

    public static function jumpRoute(): self
    {
        return new self(self::JUMP_ROUTE, null);
    }

    /**
     * @param int $blueprintTypeId the T1 blueprint the invention starts from, the SDE row that lacks the probability
     */
    public static function inventionProbability(int $blueprintTypeId): self
    {
        return new self(self::INVENTION_PROBABILITY, $blueprintTypeId);
    }

    public static function inventorSkills(int $blueprintTypeId): self
    {
        return new self(self::INVENTOR_SKILLS, $blueprintTypeId);
    }
}
