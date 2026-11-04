<?php

declare(strict_types=1);

namespace App\ApiResource\Input\MiningSite;

use Symfony\Component\Validator\Constraints as Assert;

class RecordMiningSiteScanInput
{
    /** @var list<array{typeId: int, units: int}> */
    #[Assert\All([
        new Assert\Sequentially([
            new Assert\NotNull(),
            new Assert\Type('array'),
            new Assert\Collection(
                fields: [
                    'typeId' => new Assert\Required([new Assert\NotNull(), new Assert\Type('int'), new Assert\Positive()]),
                    'units' => new Assert\Required([new Assert\NotNull(), new Assert\Type('int'), new Assert\Positive()]),
                ],
            ),
        ]),
    ])]
    public array $lines = [];
}
