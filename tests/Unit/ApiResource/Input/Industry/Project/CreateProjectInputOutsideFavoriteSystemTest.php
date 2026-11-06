<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource\Input\Industry\Project;

use App\ApiResource\Input\Industry\Project\CreateProjectInput;
use App\Entity\IndustryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(CreateProjectInput::class)]
#[Group('issue-341')]
final class CreateProjectInputOutsideFavoriteSystemTest extends TestCase
{
    use PropertyPathsOfViolations;

    private const int SABRE_TYPE_ID = 22456;
    private const float NEGATIVE_MINIMUM_OUTSIDE_SAVING = -0.01;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testInputRefusesAStructureOutsideTheFavoriteSystemWithTheDefaultMinimumOutsideSavingWhenNothingIsSent(): void
    {
        $input = new CreateProjectInput();

        self::assertSame([false, IndustryProject::DEFAULT_MINIMUM_OUTSIDE_SAVING], [$input->acceptsOutsideFavoriteSystem, $input->minimumOutsideSaving]);
    }

    public function testInputAcceptingAStructureOutsideTheFavoriteSystemWithAMinimumOutsideSavingOfZeroIsValid(): void
    {
        $input = $this->sabreInput();
        $input->acceptsOutsideFavoriteSystem = true;
        $input->minimumOutsideSaving = 0.0;

        self::assertSame([], $this->uniquePropertyPathsOf($this->validator->validate($input)));
    }

    public function testNegativeMinimumOutsideSavingIsRejectedOnTheMinimumOutsideSaving(): void
    {
        $input = $this->sabreInput();
        $input->acceptsOutsideFavoriteSystem = true;
        $input->minimumOutsideSaving = self::NEGATIVE_MINIMUM_OUTSIDE_SAVING;

        self::assertSame(['minimumOutsideSaving'], $this->uniquePropertyPathsOf($this->validator->validate($input)));
    }

    private function sabreInput(): CreateProjectInput
    {
        $input = new CreateProjectInput();
        $input->items = [['typeId' => self::SABRE_TYPE_ID, 'typeName' => 'Sabre', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 40]];

        return $input;
    }
}
