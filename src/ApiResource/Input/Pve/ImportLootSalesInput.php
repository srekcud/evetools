<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Pve;

use Symfony\Component\Validator\Constraints as Assert;

class ImportLootSalesInput
{
    private const int MAXIMUM_ITEMS = 500;
    private const int MAXIMUM_PROJECT_ID_LENGTH = 36;

    /** @var array<array{typeName: string, price: float, dateIssued: string, type?: string, transactionId?: int, projectId?: string, characterId?: int, quantity?: int, accountedQuantity?: int}> */
    #[Assert\Count(max: self::MAXIMUM_ITEMS)]
    #[Assert\All([
        new Assert\Sequentially([
            new Assert\NotNull(),
            new Assert\Type('array'),
            new Assert\Collection(
                fields: [
                    'typeName' => new Assert\Required([new Assert\NotNull(), new Assert\Type('string'), new Assert\NotBlank()]),
                    'price' => new Assert\Required([new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Positive()]),
                    'dateIssued' => new Assert\Required([new Assert\NotNull(), new Assert\Type('string'), new Assert\NotBlank(), new Assert\Date()]),
                    'type' => new Assert\Optional([new Assert\Type('string')]),
                    'transactionId' => new Assert\Optional([new Assert\Type('int')]),
                    'projectId' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: self::MAXIMUM_PROJECT_ID_LENGTH)]),
                    'characterId' => new Assert\Optional([new Assert\Type('int')]),
                    'quantity' => new Assert\Optional([new Assert\Type('int')]),
                    'accountedQuantity' => new Assert\Optional([new Assert\Type('int')]),
                ],
                allowExtraFields: true,
            ),
        ]),
    ])]
    public array $sales = [];

    /** @var array<array{transactionId?: int, type?: string, projectId?: string, characterId?: int, quantity?: int, accountedQuantity?: int}> */
    #[Assert\Count(max: self::MAXIMUM_ITEMS)]
    #[Assert\All([
        new Assert\Sequentially([
            new Assert\NotNull(),
            new Assert\Type('array'),
            new Assert\Collection(
                fields: [
                    'type' => new Assert\Optional([new Assert\Type('string')]),
                    'transactionId' => new Assert\Optional([new Assert\Type('int')]),
                    'projectId' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: self::MAXIMUM_PROJECT_ID_LENGTH)]),
                    'characterId' => new Assert\Optional([new Assert\Type('int')]),
                    'quantity' => new Assert\Optional([new Assert\Type('int')]),
                    'accountedQuantity' => new Assert\Optional([new Assert\Type('int')]),
                ],
                allowExtraFields: true,
            ),
        ]),
    ])]
    public array $declined = [];
}
