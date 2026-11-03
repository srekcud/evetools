<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Enum\MiningBoostShip;
use App\Enum\MiningCompressorKind;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

final readonly class MiningBoostFit
{
    public function __construct(private MountableCompressorCatalog $catalog)
    {
    }

    /**
     * @param list<MiningCompressorKind> $kinds
     */
    public function assertConsistent(?MiningBoostShip $ship, array $kinds): void
    {
        if (null === $ship) {
            if ([] !== $kinds) {
                $message = 'Compressors can only be mounted on a declared boost ship';

                throw new ValidationException(new ConstraintViolationList([
                    new ConstraintViolation($message, $message, [], $kinds, 'mountedCompressorKinds', $kinds),
                ]));
            }

            return;
        }

        $this->assertMountable($ship, $kinds);
    }

    /**
     * @param list<MiningCompressorKind> $kinds
     */
    private function assertMountable(MiningBoostShip $ship, array $kinds): void
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
