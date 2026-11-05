<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry\Project;

use Symfony\Component\Validator\ConstraintViolationListInterface;

trait PropertyPathsOfViolations
{
    private function uniquePropertyPathsOf(ConstraintViolationListInterface $violations): array
    {
        $propertyPaths = [];
        foreach ($violations as $violation) {
            $propertyPaths[] = $violation->getPropertyPath();
        }

        return array_values(array_unique($propertyPaths));
    }
}
