<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class MiningBoostFit
{
    public function __construct(private MountableCompressorCatalog $catalog)
    {
    }

    /**
     * @param list<MiningCompressorKind> $kinds
     */
    public function assertMountable(MiningBoostShip $ship, array $kinds): void
    {
        if ([] === $kinds) {
            return;
        }

        $mountable = $this->catalog->kindsMountableOn($ship);
        foreach ($kinds as $kind) {
            if (!in_array($kind, $mountable, true)) {
                throw new BadRequestHttpException(sprintf('The %s compressor cannot be mounted on a %s', $kind->value, $ship->name));
            }
        }
    }
}
