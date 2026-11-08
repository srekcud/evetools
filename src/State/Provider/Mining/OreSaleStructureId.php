<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class OreSaleStructureId
{
    /**
     * @param InputBag<string> $query
     */
    public static function of(InputBag $query): ?int
    {
        if (!$query->has('structureId')) {
            return null;
        }

        $structureId = filter_var($query->get('structureId'), FILTER_VALIDATE_INT);
        if ($structureId === false || $structureId <= 0) {
            throw new BadRequestHttpException('structureId must be a positive integer.');
        }

        return $structureId;
    }
}
