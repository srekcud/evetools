<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry;

use App\ApiResource\Input\Industry\StockpileImportInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

#[CoversClass(StockpileImportInput::class)]
#[Group('issue-297')]
final class StockpileImportInputDeserializationTest extends TestCase
{
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int RUNS = 7;
    private const int ME = 8;
    private const int TE = 20;

    private Serializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new Serializer([new ObjectNormalizer(null, null, null, new ReflectionExtractor())], [new JsonEncoder()]);
    }

    public function testAPayloadWithoutTeCarriesOnlyTheTypeTheRunsTheMeAndTheMode(): void
    {
        $readParameters = $this->readParameters(['typeId' => self::NITROGEN_FUEL_BLOCK, 'runs' => self::RUNS, 'me' => self::ME, 'mode' => 'merge']);

        self::assertSame(['typeId' => self::NITROGEN_FUEL_BLOCK, 'runs' => self::RUNS, 'me' => self::ME, 'mode' => 'merge'], $readParameters);
    }

    public function testTheTeOfAPayloadIsIgnored(): void
    {
        $readParameters = $this->readParameters(['typeId' => self::NITROGEN_FUEL_BLOCK, 'runs' => self::RUNS, 'me' => self::ME, 'te' => self::TE, 'mode' => 'replace']);

        self::assertSame(['typeId' => self::NITROGEN_FUEL_BLOCK, 'runs' => self::RUNS, 'me' => self::ME, 'mode' => 'replace'], $readParameters);
    }

    private function readParameters(array $payload): array
    {
        $input = $this->serializer->deserialize(json_encode($payload, \JSON_THROW_ON_ERROR), StockpileImportInput::class, 'json');
        self::assertInstanceOf(StockpileImportInput::class, $input);

        return $this->serializer->normalize($input);
    }
}
