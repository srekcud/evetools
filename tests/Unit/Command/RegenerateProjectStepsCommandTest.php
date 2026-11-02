<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\RegenerateProjectStepsCommand;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryProjectFactory;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RegenerateProjectStepsCommand::class)]
#[Group('issue-115')]
final class RegenerateProjectStepsCommandTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private const string CUSTOM_PROJECT_NAME = 'Vexor Navy Issue Run of Aurel';
    private const string PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-115000009848';

    public function testCommandNamesAProjectWhoseProductIsMissingFromTheSdeByItsTypeId(): void
    {
        $output = $this->regenerateStepsOf($this->projectWithoutCustomName(self::ROBOTICS_TYPE_ID, 5));

        self::assertStringContainsString('Regenerating steps for: type 9848 (name missing from the SDE)', $output);
    }

    public function testCommandNamesAProjectWhoseProductIsKnownByItsSdeName(): void
    {
        $output = $this->regenerateStepsOf($this->projectWithoutCustomName(self::NITROGEN_FUEL_BLOCK_TYPE_ID, 40));

        self::assertStringContainsString('Regenerating steps for: Nitrogen Fuel Block', $output);
    }

    #[Group('issue-303')]
    public function testCommandNamesAProjectWithACustomNameByTheSdeNameOfItsProduct(): void
    {
        $output = $this->regenerateStepsOf($this->projectWithCustomName(self::NITROGEN_FUEL_BLOCK_TYPE_ID, 12));

        self::assertStringContainsString('Regenerating steps for: Nitrogen Fuel Block', $output);
        self::assertStringNotContainsString(self::CUSTOM_PROJECT_NAME, $output);
    }

    #[Group('issue-303')]
    public function testCommandNamesAProjectWithACustomNameWhoseProductIsMissingFromTheSdeByItsTypeId(): void
    {
        $output = $this->regenerateStepsOf($this->projectWithCustomName(self::ROBOTICS_TYPE_ID, 3));

        self::assertStringContainsString('Regenerating steps for: type 9848 (name missing from the SDE)', $output);
        self::assertStringNotContainsString(self::CUSTOM_PROJECT_NAME, $output);
    }

    private function regenerateStepsOf(IndustryProject $project): string
    {
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($project);

        $tester = new CommandTester(new RegenerateProjectStepsCommand(
            $projectRepository,
            $this->createStub(IndustryProjectFactory::class),
            $this->calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock(),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute(['project-id' => self::PROJECT_ID]));

        return $tester->getDisplay();
    }

    private function projectWithoutCustomName(int $productTypeId, int $runs): IndustryProject
    {
        return (new IndustryProject())
            ->setUser(new User())
            ->setProductTypeId($productTypeId)
            ->setRuns($runs);
    }

    private function projectWithCustomName(int $productTypeId, int $runs): IndustryProject
    {
        return $this->projectWithoutCustomName($productTypeId, $runs)->setName(self::CUSTOM_PROJECT_NAME);
    }
}
