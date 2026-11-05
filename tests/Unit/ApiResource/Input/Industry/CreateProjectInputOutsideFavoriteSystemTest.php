<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry;

use App\ApiResource\Input\Industry\CreateProjectInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(CreateProjectInput::class)]
final class CreateProjectInputOutsideFavoriteSystemTest extends TestCase
{
    private const float DEFAULT_MINIMUM_OUTSIDE_SAVING_IN_ISK = 500000.0;
    private const float NEGATIVE_MINIMUM_OUTSIDE_SAVING_IN_ISK = -1.0;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testProjectInputRefusesAStructureOutsideTheFavoriteSystemByDefault(): void
    {
        $input = new CreateProjectInput();

        $this->assertFalse($input->acceptsOutsideFavoriteSystem);
        $this->assertSame(self::DEFAULT_MINIMUM_OUTSIDE_SAVING_IN_ISK, $input->minimumOutsideSaving);
    }

    public function testMinimumOutsideSavingOfZeroIsAccepted(): void
    {
        $violations = $this->validator->validatePropertyValue(new CreateProjectInput(), 'minimumOutsideSaving', 0.0);

        $this->assertCount(0, $violations);
    }

    public function testNegativeMinimumOutsideSavingIsRejected(): void
    {
        $violations = $this->validator->validatePropertyValue(new CreateProjectInput(), 'minimumOutsideSaving', self::NEGATIVE_MINIMUM_OUTSIDE_SAVING_IN_ISK);

        $this->assertCount(1, $violations);
        $this->assertSame('minimumOutsideSaving', $violations[0]->getPropertyPath());
    }
}
