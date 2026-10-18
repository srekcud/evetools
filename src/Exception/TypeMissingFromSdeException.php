<?php

declare(strict_types=1);

namespace App\Exception;

final class TypeMissingFromSdeException extends \RuntimeException
{
    /** @var list<int> */
    private readonly array $missingTypeIds;

    /** @param list<int> $missingTypeIds */
    public function __construct(array $missingTypeIds)
    {
        $uniqueTypeIds = array_values(array_unique($missingTypeIds));
        sort($uniqueTypeIds);
        $this->missingTypeIds = $uniqueTypeIds;

        parent::__construct('Types missing from the SDE: '.implode(', ', $uniqueTypeIds));
    }

    /** @return list<int> */
    public function getMissingTypeIds(): array
    {
        return $this->missingTypeIds;
    }
}
