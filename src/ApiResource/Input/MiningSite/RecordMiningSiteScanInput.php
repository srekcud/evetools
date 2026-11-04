<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningSite;

use Symfony\Component\Validator\Constraints as Assert;

class RecordMiningSiteScanInput
{
    private const int MAX_LINES = 100;
    private const int MAX_INTEGER_VALUE = 2_147_483_647;

    /** @var list<array{typeId: int, units: int}> */
    #[Assert\Count(max: self::MAX_LINES)]
    #[Assert\All([
        new Assert\Sequentially([
            new Assert\NotNull(),
            new Assert\Type('array'),
            new Assert\Collection(
                fields: [
                    'typeId' => new Assert\Required([new Assert\NotNull(), new Assert\Type('int'), new Assert\Positive(), new Assert\LessThanOrEqual(self::MAX_INTEGER_VALUE)]),
                    'units' => new Assert\Required([new Assert\NotNull(), new Assert\Type('int'), new Assert\Positive(), new Assert\LessThanOrEqual(self::MAX_INTEGER_VALUE)]),
                ],
            ),
        ]),
    ])]
    public array $lines = [];
}
