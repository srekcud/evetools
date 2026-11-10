<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Mining;

use App\ApiResource\Input\Mining\MiningValuationProfileInput;
use App\Tests\Unit\ApiResource\Input\Industry\Project\PropertyPathsOfViolations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(MiningValuationProfileInput::class)]
#[Group('issue-532')]
final class MiningValuationProfileInputValidationTest extends TestCase
{
    use PropertyPathsOfViolations;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[DataProvider('fieldsSentAlone')]
    public function testFieldSentAloneIsValidatedOnItsOwnPath(string $field, string|int|float $value, array $expectedPropertyPaths): void
    {
        $input = new MiningValuationProfileInput();
        $input->{$field} = $value;

        self::assertSame($expectedPropertyPaths, $this->uniquePropertyPathsOf($this->validator->validate($input)));
    }

    public static function fieldsSentAlone(): iterable
    {
        yield 'negative export tax' => ['exportTax', -1.0, ['exportTax']];
        yield 'export tax of zero' => ['exportTax', 0.0, []];
        yield 'unknown market' => ['market', 'hisec', ['market']];
        yield 'structure market' => ['market', 'structure', []];
        yield 'unknown side' => ['side', 'both', ['side']];
        yield 'sell side' => ['side', 'sell', []];
        yield 'unknown form' => ['form', 'refined', ['form']];
        yield 'reprocessed form' => ['form', 'reprocessed', []];
        yield 'structure id of zero' => ['structureId', 0, ['structureId']];
        yield 'negative structure id' => ['structureId', -60003760, ['structureId']];
        yield 'structure id above int4' => ['structureId', 1_022_734_985_679, []];
        yield 'structure name of 256 characters' => ['structureName', str_repeat('K', 256), ['structureName']];
        yield 'structure name of 255 characters' => ['structureName', str_repeat('K', 255), []];
    }

    public function testAnEmptyPatchIsValid(): void
    {
        self::assertCount(0, $this->validator->validate(new MiningValuationProfileInput()));
    }
}
