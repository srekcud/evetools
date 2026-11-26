<?php

declare(strict_types=1);

namespace App\State\Processor\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Planetary\CreatePlanTemplateInput;
use App\ApiResource\Planetary\PlanetaryPlanTemplateResource;
use App\Entity\PlanetaryPlanTemplate;
use App\Entity\User;
use App\Repository\PlanetaryPlanTemplateRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @implements ProcessorInterface<CreatePlanTemplateInput, PlanetaryPlanTemplateResource>
 */
final readonly class CreatePlanTemplateProcessor implements ProcessorInterface
{
    private const int NAME_LENGTH_LIMIT = 100;
    private const int MIN_PRODUCTIONS = 1;
    private const int MAX_PRODUCTIONS = 20;
    private const int TEMPLATE_LIMIT_PER_USER = 50;
    private const float MIN_DAYS_BETWEEN_ROUNDS = 0.5;
    private const int MAX_DAYS_BETWEEN_ROUNDS = 365;
    private const int MAX_TARGET_PER_DAY = 1_000_000_000;
    private const int MIN_TAX_PERCENT = 0;
    private const int MAX_TAX_PERCENT = 100;
    private const array PRICE_SOURCES = ['jita-sell', 'jita-buy'];
    private const array CHOICES = ['produce', 'buy'];
    private const string LIMIT_REACHED_MESSAGE = 'Template limit reached';

    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private PlanetaryPlanTemplateRepository $templateRepository,
        private PlanetSchematicTypeRepository $schematicTypeRepository,
        private ValidatorInterface $validator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlanetaryPlanTemplateResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreatePlanTemplateInput);

        $name = trim($data->name);
        $this->rejectInvalidFields($data, $name);
        $this->rejectBeyondTemplateLimit($user, $data);

        $settings = $data->settings;
        assert(isset($settings['priceSource'], $settings['taxPercent'], $settings['daysBetweenRounds']));

        $choices = [];
        foreach ($data->choices as $typeId => $choice) {
            $choices[(int) $typeId] = $choice;
        }

        /** @var list<array{typeId: int, targetPerDay: int|float}> $productions */
        $productions = array_values($data->productions);

        $template = (new PlanetaryPlanTemplate())
            ->setUser($user)
            ->setName($name)
            ->setProductions($productions)
            ->setChoices($choices)
            ->setSettings([
                'priceSource' => $settings['priceSource'],
                'taxPercent' => $settings['taxPercent'],
                'daysBetweenRounds' => $settings['daysBetweenRounds'],
            ]);

        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return PlanetaryPlanTemplateResource::fromEntity($template);
    }

    private function rejectInvalidFields(CreatePlanTemplateInput $input, string $name): void
    {
        $outputTypeIds = $this->planetaryOutputTypeIds();

        $violations = new ConstraintViolationList();
        $this->collect($violations, $input, 'name', $name, $this->nameConstraints());
        $this->collect($violations, $input, 'productions', $input->productions, $this->productionsConstraints($outputTypeIds));
        $this->collect($violations, $input, 'choices', $input->choices, $this->choicesConstraints($outputTypeIds));
        $this->collect($violations, $input, 'settings', $input->settings, $this->settingsConstraints());

        if ($violations->count() > 0) {
            throw new ValidationException($violations);
        }
    }

    private function rejectBeyondTemplateLimit(User $user, CreatePlanTemplateInput $input): void
    {
        if ($this->templateRepository->countByUser($user) < self::TEMPLATE_LIMIT_PER_USER) {
            return;
        }

        throw new ValidationException(new ConstraintViolationList([
            new ConstraintViolation(self::LIMIT_REACHED_MESSAGE, self::LIMIT_REACHED_MESSAGE, [], $input, 'templates', null),
        ]));
    }

    /**
     * @param list<Constraint> $constraints
     */
    private function collect(ConstraintViolationList $violations, CreatePlanTemplateInput $input, string $field, mixed $value, array $constraints): void
    {
        foreach ($this->validator->validate($value, $constraints) as $violation) {
            $violations->add(new ConstraintViolation(
                $violation->getMessage(),
                $violation->getMessageTemplate(),
                $violation->getParameters(),
                $input,
                $field.$violation->getPropertyPath(),
                $violation->getInvalidValue(),
            ));
        }
    }

    /**
     * @return list<int>
     */
    private function planetaryOutputTypeIds(): array
    {
        $typeIds = [];
        foreach ($this->schematicTypeRepository->getSchematicMap() as $schematic) {
            if ($schematic['output'] !== null) {
                $typeIds[] = $schematic['output']['typeId'];
            }
        }

        return $typeIds;
    }

    /**
     * @return list<Constraint>
     */
    private function nameConstraints(): array
    {
        return [new NotBlank(), new Length(max: self::NAME_LENGTH_LIMIT)];
    }

    /**
     * @param list<int> $outputTypeIds
     *
     * @return list<Constraint>
     */
    private function productionsConstraints(array $outputTypeIds): array
    {
        return [
            new Count(min: self::MIN_PRODUCTIONS, max: self::MAX_PRODUCTIONS),
            new All([new Collection(fields: [
                'typeId' => [new NotNull(), new Type('int'), new Choice(callback: static fn (): array => $outputTypeIds)],
                'targetPerDay' => [new NotNull(), new Type(['int', 'float']), new Positive(), new LessThanOrEqual(self::MAX_TARGET_PER_DAY)],
            ])]),
        ];
    }

    /**
     * @param list<int> $outputTypeIds
     *
     * @return list<Constraint>
     */
    private function choicesConstraints(array $outputTypeIds): array
    {
        return [
            new Callback(static function (array $choices, ExecutionContextInterface $context) use ($outputTypeIds): void {
                foreach (array_keys($choices) as $typeId) {
                    if (!in_array($typeId, $outputTypeIds, true)) {
                        $context->buildViolation('This type is not an output of a planetary schematic.')->atPath(sprintf('[%s]', $typeId))->addViolation();
                    }
                }
            }),
            new All([new NotNull(), new Choice(choices: self::CHOICES)]),
        ];
    }

    /**
     * @return list<Constraint>
     */
    private function settingsConstraints(): array
    {
        return [new Collection(fields: [
            'priceSource' => [new NotNull(), new Choice(choices: self::PRICE_SOURCES)],
            'taxPercent' => [new NotNull(), new Type(['int', 'float']), new GreaterThanOrEqual(self::MIN_TAX_PERCENT), new LessThanOrEqual(self::MAX_TAX_PERCENT)],
            'daysBetweenRounds' => [new NotNull(), new Type(['int', 'float']), new GreaterThanOrEqual(self::MIN_DAYS_BETWEEN_ROUNDS), new LessThanOrEqual(self::MAX_DAYS_BETWEEN_ROUNDS)],
        ])];
    }
}
