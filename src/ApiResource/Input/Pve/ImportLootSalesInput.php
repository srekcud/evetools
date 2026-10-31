<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Pve;

use Symfony\Component\Validator\Constraints as Assert;

class ImportLootSalesInput
{
    private const int MAXIMUM_ITEMS = 500;
    private const int MAXIMUM_PROJECT_ID_LENGTH = 36;
    private const int MAXIMUM_TYPE_NAME_LENGTH = 255;
    private const float MAXIMUM_PRICE = 1e15;
    private const int MAXIMUM_QUANTITY = 2147483647;

    /** @var array<array{typeName: string, price: float, dateIssued: string, type?: string, transactionId?: int, projectId?: string, characterId?: int, quantity?: int, accountedQuantity?: int}> */
    #[Assert\Count(max: self::MAXIMUM_ITEMS)]
    #[Assert\All([
        new Assert\Sequentially([
            new Assert\NotNull(),
            new Assert\Type('array'),
            new Assert\Collection(
                fields: [
                    'typeName' => new Assert\Required([new Assert\NotNull(), new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: self::MAXIMUM_TYPE_NAME_LENGTH)]),
                    'price' => new Assert\Required([new Assert\NotNull(), new Assert\Type(['int', 'float']), new Assert\Positive(), new Assert\LessThanOrEqual(self::MAXIMUM_PRICE)]),
                    'dateIssued' => new Assert\Required([new Assert\NotNull(), new Assert\Type('string'), new Assert\NotBlank(), new Assert\Date()]),
                    'type' => new Assert\Optional([new Assert\Type('string')]),
                    'transactionId' => new Assert\Optional([new Assert\Type('int')]),
                    'projectId' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: self::MAXIMUM_PROJECT_ID_LENGTH)]),
                    'characterId' => new Assert\Optional([new Assert\Type('int')]),
                    'quantity' => new Assert\Optional([new Assert\Type('int'), new Assert\LessThanOrEqual(self::MAXIMUM_QUANTITY)]),
                    'accountedQuantity' => new Assert\Optional([new Assert\Type('int'), new Assert\PositiveOrZero()]),
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
                    'quantity' => new Assert\Optional([new Assert\Type('int'), new Assert\LessThanOrEqual(self::MAXIMUM_QUANTITY)]),
                    'accountedQuantity' => new Assert\Optional([new Assert\Type('int'), new Assert\PositiveOrZero()]),
                ],
                allowExtraFields: true,
            ),
        ]),
    ])]
    public array $declined = [];
}
