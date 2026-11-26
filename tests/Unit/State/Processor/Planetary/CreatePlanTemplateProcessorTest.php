<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Planetary;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Planetary\CreatePlanTemplateInput;
use App\ApiResource\Planetary\PlanetaryPlanTemplateResource;
use App\Entity\PlanetaryPlanTemplate;
use App\Entity\User;
use App\Repository\PlanetaryPlanTemplateRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\State\Processor\Planetary\CreatePlanTemplateProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Validator\Validation;

#[CoversClass(CreatePlanTemplateProcessor::class)]
#[Group('issue-1405')]
final class CreatePlanTemplateProcessorTest extends TestCase
{
    private const int PLASMOIDS = 2_389;
    private const int COOLANT = 9_832;
    private const int ROBOTICS = 9_848;
    private const int BROADCAST_NODE = 2_867;
    private const int AQUEOUS_LIQUIDS = 2_268;
    private const int TRITANIUM = 34;
    private const int NAME_LIMIT = 100;
    private const int TEMPLATE_LIMIT = 50;

    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    public function testAnAnonymousCallerIsRefusedAndNothingIsStored(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');
        $processor = $this->processor($entityManager, 0, false);

        $this->expectException(UnauthorizedHttpException::class);

        $processor->process($this->validInput(), new Post());
    }

    public function testItStoresTheTemplateOfTheConnectedUserWithItsProductionsChoicesAndSettingsAndReturnsThem(): void
    {
        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $entityManager->expects($this->once())->method('flush');
        $input = $this->validInput();
        $input->name = 'Barren coolant line';
        $input->productions = [['typeId' => self::COOLANT, 'targetPerDay' => 120], ['typeId' => self::ROBOTICS, 'targetPerDay' => 36.5]];
        $input->choices = [self::PLASMOIDS => 'buy', self::ROBOTICS => 'produce'];
        $input->settings = ['priceSource' => 'jita-buy', 'taxPercent' => 7.5, 'daysBetweenRounds' => 6];

        $resource = $this->processor($entityManager)->process($input, new Post());

        $this->assertCount(1, $persisted);
        $this->assertInstanceOf(PlanetaryPlanTemplate::class, $persisted[0]);
        $this->assertSame($this->user, $persisted[0]->getUser());
        $this->assertSame('Barren coolant line', $persisted[0]->getName());
        $this->assertSame([['typeId' => self::COOLANT, 'targetPerDay' => 120], ['typeId' => self::ROBOTICS, 'targetPerDay' => 36.5]], $persisted[0]->getProductions());
        $this->assertSame([self::PLASMOIDS => 'buy', self::ROBOTICS => 'produce'], $persisted[0]->getChoices());
        $this->assertSame(['priceSource' => 'jita-buy', 'taxPercent' => 7.5, 'daysBetweenRounds' => 6], $persisted[0]->getSettings());
        $this->assertInstanceOf(PlanetaryPlanTemplateResource::class, $resource);
        $this->assertSame('Barren coolant line', $resource->name);
        $this->assertSame([['typeId' => self::COOLANT, 'targetPerDay' => 120], ['typeId' => self::ROBOTICS, 'targetPerDay' => 36.5]], $resource->productions);
        $this->assertSame([self::PLASMOIDS => 'buy', self::ROBOTICS => 'produce'], $resource->choices);
        $this->assertSame(['priceSource' => 'jita-buy', 'taxPercent' => 7.5, 'daysBetweenRounds' => 6], $resource->settings);
        $this->assertSame($persisted[0]->getCreatedAt()->format('c'), $resource->createdAt);
    }

    public function testItStoresProductionsGivenWithArbitraryKeysAsAListInTheirOrder(): void
    {
        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $input = $this->validInput();
        $input->productions = ['first' => ['typeId' => self::COOLANT, 'targetPerDay' => 120], 7 => ['typeId' => self::ROBOTICS, 'targetPerDay' => 36]];

        $resource = $this->processor($entityManager)->process($input, new Post());

        $this->assertSame([['typeId' => self::COOLANT, 'targetPerDay' => 120], ['typeId' => self::ROBOTICS, 'targetPerDay' => 36]], $persisted[0]->getProductions());
        $this->assertSame([['typeId' => self::COOLANT, 'targetPerDay' => 120], ['typeId' => self::ROBOTICS, 'targetPerDay' => 36]], $resource->productions);
    }

    #[DataProvider('acceptedBoundaries')]
    public function testItAcceptsTheValuesOnTheEdgeOfEachRule(callable $edit): void
    {
        $input = $this->validInput();
        $edit($input);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $resource = $this->processor($entityManager)->process($input, new Post());

        $this->assertInstanceOf(PlanetaryPlanTemplateResource::class, $resource);
    }

    /**
     * @return iterable<string, array{callable(CreatePlanTemplateInput): void}>
     */
    public static function acceptedBoundaries(): iterable
    {
        yield 'a name of 100 characters' => [static function (CreatePlanTemplateInput $input): void {
            $input->name = str_repeat('a', self::NAME_LIMIT);
        }];
        yield 'a name of 100 multibyte characters' => [static function (CreatePlanTemplateInput $input): void {
            $input->name = str_repeat('é', self::NAME_LIMIT);
        }];
        yield 'a single production' => [static function (CreatePlanTemplateInput $input): void {
            $input->productions = [['typeId' => self::BROADCAST_NODE, 'targetPerDay' => 0.25]];
        }];
        yield 'twenty productions' => [static function (CreatePlanTemplateInput $input): void {
            $input->productions = array_map(static fn (int $position): array => ['typeId' => self::COOLANT, 'targetPerDay' => 10 + $position], range(0, 19));
        }];
        yield 'no explicit choice' => [static function (CreatePlanTemplateInput $input): void {
            $input->choices = [];
        }];
        yield 'a tax of 0' => [static function (CreatePlanTemplateInput $input): void {
            $input->settings['taxPercent'] = 0;
        }];
        yield 'a tax of 100' => [static function (CreatePlanTemplateInput $input): void {
            $input->settings['taxPercent'] = 100;
        }];
        yield 'half a day between rounds' => [static function (CreatePlanTemplateInput $input): void {
            $input->settings['daysBetweenRounds'] = 0.5;
        }];
        yield 'the Jita sell source' => [static function (CreatePlanTemplateInput $input): void {
            $input->settings['priceSource'] = 'jita-sell';
        }];
        yield 'one billion units a day' => [static function (CreatePlanTemplateInput $input): void {
            $input->productions[0]['targetPerDay'] = 1_000_000_000;
        }];
        yield 'a year between rounds' => [static function (CreatePlanTemplateInput $input): void {
            $input->settings['daysBetweenRounds'] = 365;
        }];
    }

    #[DataProvider('invalidNames')]
    public function testItRefusesAnEmptyBlankOrTooLongNameOnTheNameField(string $name): void
    {
        $input = $this->validInput();
        $input->name = $name;

        $this->assertRefusedOn(['name'], $input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'tabs and spaces' => [" \t "];
        yield '101 characters' => [str_repeat('a', self::NAME_LIMIT + 1)];
        yield '101 multibyte characters' => [str_repeat('é', self::NAME_LIMIT + 1)];
    }

    public function testItRefusesNoProductionAndMoreThanTwentyOnTheProductionsField(): void
    {
        $none = $this->validInput();
        $none->productions = [];
        $twentyOne = $this->validInput();
        $twentyOne->productions = array_map(static fn (int $position): array => ['typeId' => self::COOLANT, 'targetPerDay' => 10 + $position], range(0, 20));

        $this->assertRefusedOn(['productions'], $none);
        $this->assertRefusedOn(['productions'], $twentyOne);
    }

    #[DataProvider('invalidProductionTypes')]
    public function testItRefusesAProductionWhoseTypeIsNotAnOutputOfAPlanetarySchematic(mixed $typeId): void
    {
        $input = $this->validInput();
        $input->productions = [['typeId' => $typeId, 'targetPerDay' => 120]];

        $this->assertRefusedOn(['productions'], $input);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidProductionTypes(): iterable
    {
        yield 'a type that is not planetary' => [self::TRITANIUM];
        yield 'a raw resource that only feeds schematics' => [self::AQUEOUS_LIQUIDS];
        yield 'a type id written as a string' => ['9832'];
        yield 'a fractional type id' => [9_832.5];
        yield 'a null type id' => [null];
    }

    #[DataProvider('invalidTargets')]
    public function testItRefusesATargetThatIsNotAFinitePositiveNumberOnTheProductionsField(mixed $targetPerDay): void
    {
        $input = $this->validInput();
        $input->productions = [['typeId' => self::ROBOTICS, 'targetPerDay' => $targetPerDay]];

        $this->assertRefusedOn(['productions'], $input);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidTargets(): iterable
    {
        yield 'just above one billion a day' => [1_000_000_001];
        yield 'the largest float' => [PHP_FLOAT_MAX];
        yield 'zero' => [0];
        yield 'zero as a float' => [0.0];
        yield 'negative' => [-3];
        yield 'not a number' => [NAN];
        yield 'infinite' => [INF];
        yield 'negative infinite' => [-INF];
        yield 'a number written as a string' => ['120'];
        yield 'null' => [null];
    }

    public function testItRefusesAProductionLackingItsTypeOrItsTargetWithoutFailing(): void
    {
        $withoutType = $this->validInput();
        $withoutType->productions = [['targetPerDay' => 120]];
        $withoutTarget = $this->validInput();
        $withoutTarget->productions = [['typeId' => self::COOLANT]];
        $notAnObject = $this->validInput();
        $notAnObject->productions = [self::COOLANT];

        $this->assertRefusedOn(['productions'], $withoutType);
        $this->assertRefusedOn(['productions'], $withoutTarget);
        $this->assertRefusedOn(['productions'], $notAnObject);
    }

    #[DataProvider('invalidChoices')]
    public function testItRefusesAChoiceOnAnotherTypeThanAPlanetaryOutputOrOtherThanProduceOrBuyOnTheChoicesField(array $choices): void
    {
        $input = $this->validInput();
        $input->choices = $choices;

        $this->assertRefusedOn(['choices'], $input);
    }

    /**
     * @return iterable<string, array{array<int|string, mixed>}>
     */
    public static function invalidChoices(): iterable
    {
        yield 'a type that is not planetary' => [[self::TRITANIUM => 'buy']];
        yield 'a raw resource' => [[self::AQUEOUS_LIQUIDS => 'produce']];
        yield 'a key that is not an integer' => [['coolant' => 'buy']];
        yield 'a value that is neither produce nor buy' => [[self::COOLANT => 'maybe']];
        yield 'an empty value' => [[self::COOLANT => '']];
        yield 'a null value' => [[self::COOLANT => null]];
        yield 'a valid choice next to an invalid one' => [[self::PLASMOIDS => 'buy', self::ROBOTICS => 'sell']];
    }

    #[DataProvider('invalidSettings')]
    public function testItRefusesAnInvalidPriceSourceTaxOrDelayOnTheSettingsField(array $settings): void
    {
        $input = $this->validInput();
        $input->settings = $settings;

        $this->assertRefusedOn(['settings'], $input);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidSettings(): iterable
    {
        $valid = ['priceSource' => 'jita-sell', 'taxPercent' => 4, 'daysBetweenRounds' => 4];

        yield 'an unknown price source' => [array_replace($valid, ['priceSource' => 'jita-mid'])];
        yield 'a price source in capitals' => [array_replace($valid, ['priceSource' => 'JITA-SELL'])];
        yield 'a tax just below 0' => [array_replace($valid, ['taxPercent' => -0.1])];
        yield 'a tax of 101' => [array_replace($valid, ['taxPercent' => 101])];
        yield 'a tax just above 100' => [array_replace($valid, ['taxPercent' => 100.01])];
        yield 'a tax that is not a number' => [array_replace($valid, ['taxPercent' => NAN])];
        yield 'a tax written as a string' => [array_replace($valid, ['taxPercent' => '4'])];
        yield 'a delay of 366 days' => [array_replace($valid, ['daysBetweenRounds' => 366])];
        yield 'the largest float as a delay' => [array_replace($valid, ['daysBetweenRounds' => PHP_FLOAT_MAX])];
        yield 'a delay of 0.4 day' => [array_replace($valid, ['daysBetweenRounds' => 0.4])];
        yield 'a delay of 0' => [array_replace($valid, ['daysBetweenRounds' => 0])];
        yield 'a negative delay' => [array_replace($valid, ['daysBetweenRounds' => -1])];
        yield 'an infinite delay' => [array_replace($valid, ['daysBetweenRounds' => INF])];
        yield 'a delay that is not a number' => [array_replace($valid, ['daysBetweenRounds' => NAN])];
        yield 'a delay written as a string' => [array_replace($valid, ['daysBetweenRounds' => '4'])];
        yield 'no price source' => [['taxPercent' => 4, 'daysBetweenRounds' => 4]];
        yield 'no tax' => [['priceSource' => 'jita-sell', 'daysBetweenRounds' => 4]];
        yield 'no delay' => [['priceSource' => 'jita-sell', 'taxPercent' => 4]];
        yield 'no settings at all' => [[]];
    }

    public function testTheFiftiethTemplateIsStoredAndTheFiftyFirstIsRefusedWithoutStoringAnything(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');
        $this->processor($entityManager, self::TEMPLATE_LIMIT - 1)->process($this->validInput(), new Post());

        $refusingManager = $this->createMock(EntityManagerInterface::class);
        $refusingManager->expects($this->never())->method('persist');
        $refusingManager->expects($this->never())->method('flush');
        $refusingProcessor = $this->processor($refusingManager, self::TEMPLATE_LIMIT);

        $this->expectException(ValidationException::class);

        $refusingProcessor->process($this->validInput(), new Post());
    }

    /**
     * @param list<string> $expectedFields
     */
    private function assertRefusedOn(array $expectedFields, CreatePlanTemplateInput $input): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->processor($entityManager)->process($input, new Post());
        } catch (ValidationException $refusal) {
            $fields = [];
            foreach ($refusal->getConstraintViolationList() as $violation) {
                $fields[] = (string) preg_replace('/[\[.].*$/', '', $violation->getPropertyPath());
            }
            $this->assertSame($expectedFields, array_values(array_unique($fields)));

            return;
        }

        $this->fail('The input was not refused');
    }

    private function processor(EntityManagerInterface $entityManager, int $existingTemplates = 0, bool $authenticated = true): CreatePlanTemplateProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($authenticated ? $this->user : null);

        $templateRepository = $this->createStub(PlanetaryPlanTemplateRepository::class);
        $templateRepository->method('countByUser')->willReturn($existingTemplates);

        $schematicRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicRepository->method('getSchematicMap')->willReturn([
            121 => ['inputs' => [self::AQUEOUS_LIQUIDS => 3_000], 'output' => ['typeId' => self::PLASMOIDS, 'quantity' => 20]],
            65 => ['inputs' => [self::PLASMOIDS => 40], 'output' => ['typeId' => self::COOLANT, 'quantity' => 5]],
            77 => ['inputs' => [self::COOLANT => 10], 'output' => ['typeId' => self::ROBOTICS, 'quantity' => 3]],
            88 => ['inputs' => [self::ROBOTICS => 6], 'output' => ['typeId' => self::BROADCAST_NODE, 'quantity' => 1]],
        ]);

        return new CreatePlanTemplateProcessor(
            $security,
            $entityManager,
            $templateRepository,
            $schematicRepository,
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
    }

    private function validInput(): CreatePlanTemplateInput
    {
        $input = new CreatePlanTemplateInput();
        $input->name = 'Coolant and robotics';
        $input->productions = [['typeId' => self::COOLANT, 'targetPerDay' => 120]];
        $input->choices = [self::PLASMOIDS => 'buy'];
        $input->settings = ['priceSource' => 'jita-sell', 'taxPercent' => 4, 'daysBetweenRounds' => 4];

        return $input;
    }
}
