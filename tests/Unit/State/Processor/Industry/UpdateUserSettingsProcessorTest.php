<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry;

use ApiPlatform\Metadata\Patch;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Industry\UserSettingsResource;
use App\ApiResource\Input\Industry\UpdateUserSettingsInput;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\State\Processor\Industry\UpdateUserSettingsProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(UpdateUserSettingsProcessor::class)]
#[Group('issue-200')]
final class UpdateUserSettingsProcessorTest extends TestCase
{
    private const int JITA_SYSTEM_ID = 30000142;
    private const int RENS_SYSTEM_ID = 30002510;
    private const int AMARR_SYSTEM_ID = 30002187;
    private const int PERIMETER_SYSTEM_ID = 30000144;
    private const int SYSTEM_ID_ABSENT_FROM_THE_SDE = 30099999;
    private const int OTHER_SYSTEM_ID_ABSENT_FROM_THE_SDE = 30088888;

    private User $user;
    private IndustryUserSettings $settings;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->settings = (new IndustryUserSettings())
            ->setUser($this->user)
            ->setBrokerFeeRate(0.025)
            ->setSalesTaxRate(0.045)
            ->setExportCostPerM3(1200.0)
            ->setFavoriteManufacturingSystemId(self::AMARR_SYSTEM_ID)
            ->setFavoriteReactionSystemId(self::PERIMETER_SYSTEM_ID);
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
            [0.025, 0.036, 1200.0, self::AMARR_SYSTEM_ID, self::PERIMETER_SYSTEM_ID],
            [
                $this->settings->getBrokerFeeRate(),
                $this->settings->getSalesTaxRate(),
                $this->settings->getExportCostPerM3(),
                $this->settings->getFavoriteManufacturingSystemId(),
                $this->settings->getFavoriteReactionSystemId(),
            ],
        );
    }

    public function testReturnedResourceCarriesTheStoredSettingsAndTheFavoriteSystemNames(): void
    {
        $input = new UpdateUserSettingsInput();
        $input->favoriteManufacturingSystemId = self::JITA_SYSTEM_ID;
        $input->favoriteReactionSystemId = self::RENS_SYSTEM_ID;
        $input->brokerFeeRate = 0.0125;
        $input->salesTaxRate = 0.0337;
        $input->exportCostPerM3 = 850.0;

        $resource = $this->patch($input);

        self::assertSame(
            [
                'favoriteManufacturingSystemId' => self::JITA_SYSTEM_ID,
                'favoriteManufacturingSystemName' => 'Jita',
                'favoriteReactionSystemId' => self::RENS_SYSTEM_ID,
                'favoriteReactionSystemName' => 'Rens',
                'brokerFeeRate' => 0.0125,
                'salesTaxRate' => 0.0337,
                'exportCostPerM3' => 850.0,
            ],
            get_object_vars($resource),
        );
    }

    public function testUnchangedFavoriteSystemsAreReturnedWithTheirNames(): void
    {
        $input = new UpdateUserSettingsInput();
        $input->exportCostPerM3 = 640.0;

        $resource = $this->patch($input);

        self::assertSame(
            [self::AMARR_SYSTEM_ID, 'Amarr', self::PERIMETER_SYSTEM_ID, 'Perimeter'],
            [
                $resource->favoriteManufacturingSystemId,
                $resource->favoriteManufacturingSystemName,
                $resource->favoriteReactionSystemId,
                $resource->favoriteReactionSystemName,
            ],
        );
    }

    #[DataProvider('favoriteSystemsSent')]
    public function testFavoriteSystemIdIsStoredWhenPositiveAndClearedOtherwise(
        ?int $sentManufacturingSystemId,
        ?int $sentReactionSystemId,
        ?int $storedManufacturingSystemId,
        ?int $storedReactionSystemId,
    ): void {
        $input = new UpdateUserSettingsInput();
        $input->favoriteManufacturingSystemId = $sentManufacturingSystemId;
        $input->favoriteReactionSystemId = $sentReactionSystemId;

        $resource = $this->patch($input);

        self::assertSame(
            [$storedManufacturingSystemId, $storedReactionSystemId, $storedManufacturingSystemId, $storedReactionSystemId],
            [
                $this->settings->getFavoriteManufacturingSystemId(),
                $this->settings->getFavoriteReactionSystemId(),
                $resource->favoriteManufacturingSystemId,
                $resource->favoriteReactionSystemId,
            ],
        );
    }

    public static function favoriteSystemsSent(): iterable
    {
        yield 'positive ids are stored' => [self::JITA_SYSTEM_ID, self::RENS_SYSTEM_ID, self::JITA_SYSTEM_ID, self::RENS_SYSTEM_ID];
        yield 'zero clears the favorite system' => [0, 0, null, null];
        yield 'negative id clears the favorite system' => [-1, -30000142, null, null];
        yield 'absent ids leave the favorite systems unchanged' => [null, null, self::AMARR_SYSTEM_ID, self::PERIMETER_SYSTEM_ID];
        yield 'manufacturing cleared, reaction stored' => [0, self::RENS_SYSTEM_ID, null, self::RENS_SYSTEM_ID];
        yield 'manufacturing stored, reaction cleared' => [self::JITA_SYSTEM_ID, -7, self::JITA_SYSTEM_ID, null];
    }

    public function testClearedFavoriteSystemsAreReturnedWithoutName(): void
    {
        $input = new UpdateUserSettingsInput();
        $input->favoriteManufacturingSystemId = 0;
        $input->favoriteReactionSystemId = -1;

        $resource = $this->patch($input);

        self::assertSame(
            [null, null, null, null],
            [
                $resource->favoriteManufacturingSystemId,
                $resource->favoriteManufacturingSystemName,
                $resource->favoriteReactionSystemId,
                $resource->favoriteReactionSystemName,
            ],
        );
    }

    public function testFavoriteManufacturingSystemAbsentFromTheSdeIsRefusedAndNothingIsStored(): void
    {
        $input = new UpdateUserSettingsInput();
        $input->favoriteManufacturingSystemId = self::SYSTEM_ID_ABSENT_FROM_THE_SDE;
        $input->favoriteReactionSystemId = self::RENS_SYSTEM_ID;
        $input->brokerFeeRate = 0.0175;

        $refusal = $this->patchExpectingRefusal($input, $this->settings);

        self::assertSame(
            [422, ['favoriteManufacturingSystemId' => self::SYSTEM_ID_ABSENT_FROM_THE_SDE]],
            [$refusal->getStatusCode(), $this->refusedValuesByPropertyPath($refusal)],
        );
        self::assertSame(
            [self::AMARR_SYSTEM_ID, self::PERIMETER_SYSTEM_ID, 0.025, 0.045, 1200.0],
            $this->storedSettings(),
        );
    }

    public function testFavoriteReactionSystemAbsentFromTheSdeIsRefusedAndNothingIsStored(): void
    {
        $input = new UpdateUserSettingsInput();
        $input->favoriteManufacturingSystemId = self::JITA_SYSTEM_ID;
        $input->favoriteReactionSystemId = self::OTHER_SYSTEM_ID_ABSENT_FROM_THE_SDE;
        $input->salesTaxRate = 0.0288;

        $refusal = $this->patchExpectingRefusal($input, $this->settings);

        self::assertSame(
            [422, ['favoriteReactionSystemId' => self::OTHER_SYSTEM_ID_ABSENT_FROM_THE_SDE]],
            [$refusal->getStatusCode(), $this->refusedValuesByPropertyPath($refusal)],
        );
        self::assertSame(
            [self::AMARR_SYSTEM_ID, self::PERIMETER_SYSTEM_ID, 0.025, 0.045, 1200.0],
            $this->storedSettings(),
        );
    }

    public function testFavoriteSystemAbsentFromTheSdeIsRefusedWithoutWritingTheSettingsOfANewUser(): void
    {
        $input = new UpdateUserSettingsInput();
        $input->favoriteReactionSystemId = self::SYSTEM_ID_ABSENT_FROM_THE_SDE;
        $input->exportCostPerM3 = 975.0;

        $refusal = $this->patchExpectingRefusal($input, null);

        self::assertSame(
            [422, ['favoriteReactionSystemId' => self::SYSTEM_ID_ABSENT_FROM_THE_SDE]],
            [$refusal->getStatusCode(), $this->refusedValuesByPropertyPath($refusal)],
        );
    }

    private function patchExpectingRefusal(UpdateUserSettingsInput $input, ?IndustryUserSettings $storedSettings): ValidationException
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->user);
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn($storedSettings);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            (new UpdateUserSettingsProcessor($security, $settingsRepository, $this->solarSystemRepository(), $entityManager))
                ->process($input, new Patch());
        } catch (ValidationException $refusal) {
            return $refusal;
        }

        self::fail('The settings update was not refused.');
    }

    private function refusedValuesByPropertyPath(ValidationException $refusal): array
    {
        $refusedValues = [];
        foreach ($refusal->getConstraintViolationList() as $violation) {
            $refusedValues[$violation->getPropertyPath()] = $violation->getInvalidValue();
        }

        return $refusedValues;
    }

    private function storedSettings(): array
    {
        return [
            $this->settings->getFavoriteManufacturingSystemId(),
            $this->settings->getFavoriteReactionSystemId(),
            $this->settings->getBrokerFeeRate(),
            $this->settings->getSalesTaxRate(),
            $this->settings->getExportCostPerM3(),
        ];
    }

    private function patch(UpdateUserSettingsInput $input): UserSettingsResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->user);
        $settingsRepository = $this->createMock(IndustryUserSettingsRepository::class);
        $settingsRepository->expects($this->once())
            ->method('findOneBy')
            ->with($this->identicalTo(['user' => $this->user]))
            ->willReturn($this->settings);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        return (new UpdateUserSettingsProcessor($security, $settingsRepository, $this->solarSystemRepository(), $entityManager))
            ->process($input, new Patch());
    }

    private function solarSystemRepository(): MapSolarSystemRepository
    {
        $solarSystemsInTheSde = [
            self::JITA_SYSTEM_ID => (new MapSolarSystem())->setSolarSystemId(self::JITA_SYSTEM_ID)->setSolarSystemName('Jita'),
            self::RENS_SYSTEM_ID => (new MapSolarSystem())->setSolarSystemId(self::RENS_SYSTEM_ID)->setSolarSystemName('Rens'),
            self::AMARR_SYSTEM_ID => (new MapSolarSystem())->setSolarSystemId(self::AMARR_SYSTEM_ID)->setSolarSystemName('Amarr'),
            self::PERIMETER_SYSTEM_ID => (new MapSolarSystem())->setSolarSystemId(self::PERIMETER_SYSTEM_ID)->setSolarSystemName('Perimeter'),
        ];
        $solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $solarSystemRepository->method('find')
            ->willReturnCallback(static fn (mixed $solarSystemId): ?MapSolarSystem => $solarSystemsInTheSde[$solarSystemId] ?? null);

        return $solarSystemRepository;
    }
}
