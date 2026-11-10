<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Mining;

use App\ApiResource\Input\Mining\MiningValuationProfileInput;
use App\Tests\Unit\ApiResource\Input\Industry\Project\PropertyPathsOfViolations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(MiningValuationProfileInput::class)]
#[Group('issue-532')]
final class MiningValuationProfileInputExportTaxCeilingTest extends TestCase
{
    use PropertyPathsOfViolations;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testAnExportTaxAboveTheCeilingIsRejectedOnItsOwnPath(): void
    {
        $input = new MiningValuationProfileInput();
        $input->exportTax = MiningValuationProfileInput::MAX_EXPORT_TAX + 1.0;

        self::assertSame(['exportTax'], $this->uniquePropertyPathsOf($this->validator->validate($input)));
    }

    public function testAnExportTaxEqualToTheCeilingIsAccepted(): void
    {
        $input = new MiningValuationProfileInput();
        $input->exportTax = MiningValuationProfileInput::MAX_EXPORT_TAX;

        self::assertCount(0, $this->validator->validate($input));
    }
}
