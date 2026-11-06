<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Sde;

use App\Industry\Application\ItemVolumes;
use App\Repository\Sde\InvTypeRepository;

final readonly class SdeItemVolumes implements ItemVolumes
{
    public function __construct(private InvTypeRepository $invTypeRepository)
    {
    }

    public function volumeOf(int $typeId): ?float
    {
        return $this->invTypeRepository->find($typeId)?->getVolume();
    }
}
