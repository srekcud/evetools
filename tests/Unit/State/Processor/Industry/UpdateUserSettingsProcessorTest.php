<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\Industry\UpdateUserSettingsInput;
use App\Entity\IndustryUserSettings;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\State\Processor\Industry\UpdateUserSettingsProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(UpdateUserSettingsProcessor::class)]
#[Group('issue-200')]
final class UpdateUserSettingsProcessorTest extends TestCase
{
    private IndustryUserSettings $settings;

    protected function setUp(): void
    {
        $user = new User();
        $this->settings = (new IndustryUserSettings())
            ->setUser($user)
            ->setBrokerFeeRate(0.025)
            ->setSalesTaxRate(0.045)
            ->setExportCostPerM3(1200.0);
    }

    public function testValidatedBoundaryValuesAreStoredAsSentAndFlushedOnce(): void
    {
        $input = new UpdateUserSettingsInput();
        $input->brokerFeeRate = 0.0;
        $input->salesTaxRate = 1.0;
        $input->exportCostPerM3 = 0.0;

        $this->patch($input);

        self::assertSame(
            [0.0, 1.0, 0.0],
            [$this->settings->getBrokerFeeRate(), $this->settings->getSalesTaxRate(), $this->settings->getExportCostPerM3()],
        );
    }

    public function testSettingsAbsentFromThePatchAreUnchanged(): void
    {
        $input = new UpdateUserSettingsInput();
        $input->salesTaxRate = 0.036;

        $this->patch($input);

        self::assertSame(
            [0.025, 0.036, 1200.0],
            [$this->settings->getBrokerFeeRate(), $this->settings->getSalesTaxRate(), $this->settings->getExportCostPerM3()],
        );
    }

    private function patch(UpdateUserSettingsInput $input): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->settings->getUser());
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn($this->settings);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        (new UpdateUserSettingsProcessor($security, $settingsRepository, $this->createStub(MapSolarSystemRepository::class), $entityManager))
            ->process($input, new Patch());
    }
}
