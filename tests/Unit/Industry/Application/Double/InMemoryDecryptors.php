<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\Decryptors;
use App\Industry\Domain\Decryptor;
use App\Industry\Domain\Multiplier;

final readonly class InMemoryDecryptors implements Decryptors
{
    public const int ATTAINMENT = 34202;
    public const int AUGMENTATION = 34203;
    public const int OPTIMIZED_ATTAINMENT = 34207;

    /**
     * @param list<Decryptor> $decryptors
     */
    public function __construct(private array $decryptors = [])
    {
    }

    public static function none(): self
    {
        return new self();
    }

    public static function ofTheSde(): self
    {
        return new self([
            new Decryptor(self::ATTAINMENT, new Multiplier(1.8), 4, -1, 4),
            new Decryptor(self::AUGMENTATION, new Multiplier(0.6), 9, -2, 2),
            new Decryptor(self::OPTIMIZED_ATTAINMENT, new Multiplier(1.9), 2, 1, -2),
        ]);
    }

    public function all(): array
    {
        return $this->decryptors;
    }

    public function ofType(int $typeId): ?Decryptor
    {
        foreach ($this->decryptors as $decryptor) {
            if ($decryptor->typeId === $typeId) {
                return $decryptor;
            }
        }

        return null;
    }
}
