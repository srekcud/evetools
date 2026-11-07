<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry\Project;

use App\ApiResource\Input\Industry\Project\CreateProjectTemplateInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

#[CoversClass(CreateProjectTemplateInput::class)]
#[Group('issue-355')]
final class CreateProjectTemplateInputValidationTest extends TestCase
{
    use PropertyPathsOfViolations;

    public function testTemplateWithoutANameOrWithAnEmptyNameIsRefused(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $absentName = new CreateProjectTemplateInput();
        $absentName->projectId = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e51';
        $emptyName = new CreateProjectTemplateInput();
        $emptyName->projectId = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e51';
        $emptyName->name = '';

        self::assertSame(['name'], $this->uniquePropertyPathsOf($validator->validate($absentName)));
        self::assertSame(['name'], $this->uniquePropertyPathsOf($validator->validate($emptyName)));
    }
}
