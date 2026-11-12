<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Why a cost is unknown (spec R10): the data that was absent, and the typeId it belongs to when there is one.
 */
final readonly class MissingData
{
    private const string ADJUSTED_PRICE = 'adjusted_price';
    private const string COPY_BASE_TIME = 'copy_base_time';
    private const string COPY_SKILLS = 'copy_skills';
    private const string COPY_STRUCTURE_NOT_PLANNED = 'copy_structure_not_planned';
    private const string COST_INDEX = 'cost_index';
    private const string FACILITY_TAX = 'facility_tax';
    private const string INVENTION_PROBABILITY = 'invention_probability';
    private const string ITEM_VOLUME = 'item_volume';
    private const string JUMP_ROUTE = 'jump_route';
    private const string INVENTOR_SKILLS = 'inventor_skills';
    private const string MARKET_PRICE = 'market_price';
    private const string NO_COPY_STRUCTURE = 'no_copy_structure';
    private const string PLANNED_STRUCTURE_DELETED = 'planned_structure_deleted';
    private const string RESEARCH_BASE_TIME = 'research_base_time';
    private const string RESEARCH_SKILL = 'research_skill';
    private const string RESEARCHER = 'researcher';
    private const string STRUCTURE_PRICE = 'structure_price';

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

    public static function structurePrice(int $typeId): self
    {
        return new self(self::STRUCTURE_PRICE, $typeId);
    }

    public static function costIndex(): self
    {
        return new self(self::COST_INDEX, null);
    }

    public static function facilityTax(): self
    {
        return new self(self::FACILITY_TAX, null);
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

    public static function researchSkill(int $skillTypeId): self
    {
        return new self(self::RESEARCH_SKILL, $skillTypeId);
    }

    public static function researchBaseTime(int $productTypeId): self
    {
        return new self(self::RESEARCH_BASE_TIME, $productTypeId);
    }

    public static function researcher(): self
    {
        return new self(self::RESEARCHER, null);
    }

    public static function inventorSkills(int $blueprintTypeId): self
    {
        return new self(self::INVENTOR_SKILLS, $blueprintTypeId);
    }

    public static function copyBaseTime(int $productTypeId): self
    {
        return new self(self::COPY_BASE_TIME, $productTypeId);
    }

    public static function copySkills(): self
    {
        return new self(self::COPY_SKILLS, null);
    }

    public static function copyStructureNotPlanned(): self
    {
        return new self(self::COPY_STRUCTURE_NOT_PLANNED, null);
    }

    public static function noCopyStructure(): self
    {
        return new self(self::NO_COPY_STRUCTURE, null);
    }

    public static function plannedStructureDeleted(): self
    {
        return new self(self::PLANNED_STRUCTURE_DELETED, null);
    }
}
