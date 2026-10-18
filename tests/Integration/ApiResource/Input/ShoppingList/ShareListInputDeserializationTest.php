<?php

declare(strict_types=1);

namespace App\Tests\Integration\ApiResource\Input\ShoppingList;

use App\ApiResource\Input\ShoppingList\ShareListInput;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

final class ShareListInputDeserializationTest extends KernelTestCase
{
    private SerializerInterface $serializer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->serializer = self::getContainer()->get('serializer');
    }

    #[Test]
    public function itAcceptsAnUnknownVolumeTotalAsNull(): void
    {
        $shareListInput = $this->deserialize([
            'items' => [['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 10000]],
            'totals' => ['volume' => null, 'jita' => 50000.0],
        ]);

        self::assertSame(['volume' => null, 'jita' => 50000.0], $shareListInput->totals);
    }

    #[Test]
    public function itAcceptsUnknownVolumeImportAndJitaWithImportTotalsAsNull(): void
    {
        $shareListInput = $this->deserialize([
            'items' => [['typeId' => 35, 'typeName' => 'Pyerite', 'quantity' => 2500]],
            'totals' => ['jita' => 31250.5, 'volume' => null, 'import' => null, 'jitaWithImport' => null],
        ]);

        self::assertSame(
            ['jita' => 31250.5, 'volume' => null, 'import' => null, 'jitaWithImport' => null],
            $shareListInput->totals,
        );
    }

    #[Test]
    public function itKeepsNumericTotalsUnchanged(): void
    {
        $shareListInput = $this->deserialize([
            'items' => [['typeId' => 36, 'typeName' => 'Mexallon', 'quantity' => 400]],
            'totals' => ['jita' => 28000.0, 'volume' => 4.0, 'import' => 4800.0, 'jitaWithImport' => 32800.0],
        ]);

        self::assertSame(
            ['jita' => 28000.0, 'volume' => 4.0, 'import' => 4800.0, 'jitaWithImport' => 32800.0],
            $shareListInput->totals,
        );
    }

    private function deserialize(array $payload): ShareListInput
    {
        $shareListInput = $this->serializer->deserialize(json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), ShareListInput::class, 'json', [DenormalizerInterface::COLLECT_DENORMALIZATION_ERRORS => true]);
        self::assertInstanceOf(ShareListInput::class, $shareListInput);

        return $shareListInput;
    }
}
