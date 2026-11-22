<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Sde;

use App\Constant\EveConstants;
use App\Industry\Application\ShipProducts;
use App\Repository\Sde\InvTypeRepository;

final readonly class SdeShipProducts implements ShipProducts
{
    public function __construct(private InvTypeRepository $invTypeRepository)
    {
    }

    public function isShip(int $typeId): bool
    {
        $type = $this->invTypeRepository->find($typeId)
            ?? throw new \UnexpectedValueException(\sprintf('Type %d is missing from the SDE.', $typeId));

        return EveConstants::SHIP_CATEGORY_ID === $type->getGroup()->getCategory()->getCategoryId();
    }
}
