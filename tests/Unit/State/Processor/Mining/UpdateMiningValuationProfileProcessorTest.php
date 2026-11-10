<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Mining;

use ApiPlatform\Metadata\Patch;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Mining\MiningValuationProfileInput;
use App\Entity\MiningValuationProfile;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\MiningValuationProfileRepository;
use App\State\Processor\Mining\UpdateMiningValuationProfileProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(UpdateMiningValuationProfileProcessor::class)]
#[Group('issue-532')]
final class UpdateMiningValuationProfileProcessorTest extends TestCase
{
    private const int STRUCTURE_ID = 60003760;
    private const int OTHER_STRUCTURE_ID = 1_022_734_985_679;
    private const string STRUCTURE_NAME = 'Refinery B-1';
    private const string STRUCTURE_REQUIRED_MESSAGE = 'A structure is required for the structure market.';

    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    public function testAFirstPatchCreatesTheProfileOfTheUserWithEverySentFieldAndFlushesOnce(): void
    {
        $input = new MiningValuationProfileInput();
        $input->market = 'structure';
        $input->side = 'sell';
        $input->form = 'raw';
        $input->structureId = self::STRUCTURE_ID;
        $input->structureName = self::STRUCTURE_NAME;
        $input->exportTax = 1250.0;
        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $entityManager->expects($this->once())->method('flush');

        $resource = $this->processor(null, $entityManager)->process($input, new Patch());

        $this->assertCount(1, $persisted);
        $this->assertInstanceOf(MiningValuationProfile::class, $persisted[0]);
        $this->assertSame($this->user, $persisted[0]->getUser());
        $this->assertSame(
            [MiningValuationMarket::Structure, MarketSide::Sell, MiningValuationForm::Raw, self::STRUCTURE_ID, self::STRUCTURE_NAME, 1250.0],
            [$persisted[0]->getMarket(), $persisted[0]->getSide(), $persisted[0]->getForm(), $persisted[0]->getStructureId(), $persisted[0]->getStructureName(), $persisted[0]->getExportTax()],
        );
        $this->assertSame(
            ['market' => 'structure', 'side' => 'sell', 'form' => 'raw', 'structureId' => self::STRUCTURE_ID, 'structureName' => self::STRUCTURE_NAME, 'exportTax' => 1250.0, 'persisted' => true],
            get_object_vars($resource),
        );
    }

    public function testAFirstPatchWithOnlyTheTaxStartsFromTheDefaults(): void
    {
        $input = new MiningValuationProfileInput();
        $input->exportTax = 400.0;
        $persisted = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $resource = $this->processor(null, $entityManager)->process($input, new Patch());

        $this->assertCount(1, $persisted);
        $this->assertSame(
            ['market' => 'jita', 'side' => 'buy', 'form' => 'compressed', 'structureId' => null, 'structureName' => null, 'exportTax' => 400.0, 'persisted' => true],
            get_object_vars($resource),
        );
    }

    public function testAPartialPatchLeavesTheAbsentFieldsUntouchedAndStoresATaxOfZero(): void
    {
        $stored = $this->storedProfileOnStructure();
        $input = new MiningValuationProfileInput();
        $input->exportTax = 0.0;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $resource = $this->processor($stored, $entityManager)->process($input, new Patch());

        $this->assertSame(
            [MiningValuationMarket::Structure, MarketSide::Sell, MiningValuationForm::Reprocessed, self::STRUCTURE_ID, self::STRUCTURE_NAME, 0.0],
            [$stored->getMarket(), $stored->getSide(), $stored->getForm(), $stored->getStructureId(), $stored->getStructureName(), $stored->getExportTax()],
        );
        $this->assertSame(
            ['market' => 'structure', 'side' => 'sell', 'form' => 'reprocessed', 'structureId' => self::STRUCTURE_ID, 'structureName' => self::STRUCTURE_NAME, 'exportTax' => 0.0, 'persisted' => true],
            get_object_vars($resource),
        );
    }

    public function testAnEmptyPatchChangesNothingOfTheStoredProfile(): void
    {
        $stored = $this->storedProfileOnStructure();

        $resource = $this->processor($stored, $this->createStub(EntityManagerInterface::class))->process(new MiningValuationProfileInput(), new Patch());

        $this->assertSame(
            ['market' => 'structure', 'side' => 'sell', 'form' => 'reprocessed', 'structureId' => self::STRUCTURE_ID, 'structureName' => self::STRUCTURE_NAME, 'exportTax' => 850.0, 'persisted' => true],
            get_object_vars($resource),
        );
    }

    public function testSwitchingToJitaKeepsTheRememberedStructure(): void
    {
        $stored = $this->storedProfileOnStructure();
        $input = new MiningValuationProfileInput();
        $input->market = 'jita';

        $resource = $this->processor($stored, $this->createStub(EntityManagerInterface::class))->process($input, new Patch());

        $this->assertSame(
            [MiningValuationMarket::Jita, self::STRUCTURE_ID, self::STRUCTURE_NAME],
            [$stored->getMarket(), $stored->getStructureId(), $stored->getStructureName()],
        );
        $this->assertSame(['jita', self::STRUCTURE_ID, self::STRUCTURE_NAME], [$resource->market, $resource->structureId, $resource->structureName]);
    }

    public function testComingBackToTheStructureMarketReusesTheRememberedStructure(): void
    {
        $stored = $this->storedProfileOnStructure()->setMarket(MiningValuationMarket::Jita);
        $input = new MiningValuationProfileInput();
        $input->market = 'structure';

        $resource = $this->processor($stored, $this->createStub(EntityManagerInterface::class))->process($input, new Patch());

        $this->assertSame(['structure', self::STRUCTURE_ID, self::STRUCTURE_NAME], [$resource->market, $resource->structureId, $resource->structureName]);
    }

    public function testPickingAnotherStructureReplacesIdAndName(): void
    {
        $stored = $this->storedProfileOnStructure();
        $input = new MiningValuationProfileInput();
        $input->structureId = self::OTHER_STRUCTURE_ID;
        $input->structureName = 'Keepstar Z-9';

        $this->processor($stored, $this->createStub(EntityManagerInterface::class))->process($input, new Patch());

        $this->assertSame([self::OTHER_STRUCTURE_ID, 'Keepstar Z-9'], [$stored->getStructureId(), $stored->getStructureName()]);
    }

    public function testTheStructureMarketWithoutAnyStructureIsRefusedAndNothingIsWrittenForANewUser(): void
    {
        $input = new MiningValuationProfileInput();
        $input->market = 'structure';
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        $refusal = $this->refusalOf($this->processor(null, $entityManager), $input);

        $this->assertSame(
            [422, [['structureId', self::STRUCTURE_REQUIRED_MESSAGE]]],
            [$refusal->getStatusCode(), $this->violationsOf($refusal)],
        );
    }

    public function testTheStructureMarketWithoutAnyStructureIsRefusedForAStoredProfileOnJitaAndItStaysUnchanged(): void
    {
        $stored = $this->storedProfileOnStructure()
            ->setMarket(MiningValuationMarket::Jita)
            ->setStructureId(null)
            ->setStructureName(null);
        $input = new MiningValuationProfileInput();
        $input->market = 'structure';
        $input->exportTax = 900.0;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $refusal = $this->refusalOf($this->processor($stored, $entityManager), $input);

        $this->assertSame([422, [['structureId', self::STRUCTURE_REQUIRED_MESSAGE]]], [$refusal->getStatusCode(), $this->violationsOf($refusal)]);
        $this->assertSame([MiningValuationMarket::Jita, 850.0], [$stored->getMarket(), $stored->getExportTax()]);
    }

    public function testClearingTheStructureOfAStoredJitaProfileForgetsIdAndName(): void
    {
        $stored = $this->storedProfileOnStructure()->setMarket(MiningValuationMarket::Jita);
        $input = new MiningValuationProfileInput();
        $input->clearStructure = true;

        $resource = $this->processor($stored, $this->createStub(EntityManagerInterface::class))->process($input, new Patch());

        $this->assertSame([null, null], [$stored->getStructureId(), $stored->getStructureName()]);
        $this->assertSame(['jita', null, null], [$resource->market, $resource->structureId, $resource->structureName]);
    }

    public function testClearingTheStructureWhileStayingOnTheStructureMarketIsRefusedAndNothingIsWritten(): void
    {
        $stored = $this->storedProfileOnStructure();
        $input = new MiningValuationProfileInput();
        $input->clearStructure = true;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $refusal = $this->refusalOf($this->processor($stored, $entityManager), $input);

        $this->assertSame([422, [['structureId', self::STRUCTURE_REQUIRED_MESSAGE]]], [$refusal->getStatusCode(), $this->violationsOf($refusal)]);
        $this->assertSame([self::STRUCTURE_ID, self::STRUCTURE_NAME], [$stored->getStructureId(), $stored->getStructureName()]);
    }

    public function testClearingTheStructureWhileSwitchingToJitaInTheSamePatchIsAccepted(): void
    {
        $stored = $this->storedProfileOnStructure();
        $input = new MiningValuationProfileInput();
        $input->market = 'jita';
        $input->clearStructure = true;

        $resource = $this->processor($stored, $this->createStub(EntityManagerInterface::class))->process($input, new Patch());

        $this->assertSame(['jita', null, null], [$resource->market, $resource->structureId, $resource->structureName]);
    }

    public function testAnAnonymousVisitorIsUnauthorized(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $processor = new UpdateMiningValuationProfileProcessor($security, $this->createStub(MiningValuationProfileRepository::class), $this->createStub(EntityManagerInterface::class));

        $this->expectException(UnauthorizedHttpException::class);

        $processor->process(new MiningValuationProfileInput(), new Patch());
    }

    private function storedProfileOnStructure(): MiningValuationProfile
    {
        return (new MiningValuationProfile())
            ->setUser($this->user)
            ->setMarket(MiningValuationMarket::Structure)
            ->setSide(MarketSide::Sell)
            ->setForm(MiningValuationForm::Reprocessed)
            ->setStructureId(self::STRUCTURE_ID)
            ->setStructureName(self::STRUCTURE_NAME)
            ->setExportTax(850.0);
    }

    private function processor(?MiningValuationProfile $stored, EntityManagerInterface $entityManager): UpdateMiningValuationProfileProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->user);
        $repository = $this->createStub(MiningValuationProfileRepository::class);
        $repository->method('findOneBy')->willReturn($stored);

        return new UpdateMiningValuationProfileProcessor($security, $repository, $entityManager);
    }

    private function refusalOf(UpdateMiningValuationProfileProcessor $processor, MiningValuationProfileInput $input): ValidationException
    {
        try {
            $processor->process($input, new Patch());
        } catch (ValidationException $refusal) {
            return $refusal;
        }

        self::fail('The profile update was not refused.');
    }

    private function violationsOf(ValidationException $refusal): array
    {
        $violations = [];
        foreach ($refusal->getConstraintViolationList() as $violation) {
            $violations[] = [$violation->getPropertyPath(), (string) $violation->getMessage()];
        }

        return $violations;
    }
}
