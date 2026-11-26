<?php

declare(strict_types=1);

namespace App\State\Processor\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Planetary\CreateColonyLayoutInput;
use App\ApiResource\Planetary\PlanetaryColonyLayoutResource;
use App\Entity\PlanetaryColonyLayout;
use App\Entity\User;
use App\Exception\InvalidPlanetaryColonyLayoutException;
use App\Repository\PlanetaryColonyLayoutRepository;
use App\Service\Planetary\Layout\PlanetaryColonyLayoutName;
use App\Service\Planetary\Layout\PlanetaryColonyLayoutParser;
use App\Service\Planetary\Layout\PlanetaryColonyLayoutSummarizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateColonyLayoutInput, PlanetaryColonyLayoutResource>
 */
final readonly class CreateColonyLayoutProcessor implements ProcessorInterface
{
    private const int LAYOUT_LIMIT_PER_USER = 100;

    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private PlanetaryColonyLayoutRepository $layoutRepository,
        private PlanetaryColonyLayoutParser $parser,
        private PlanetaryColonyLayoutSummarizer $summarizer,
        private PlanetaryColonyLayoutName $nameRules,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlanetaryColonyLayoutResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateColonyLayoutInput);

        if ($this->layoutRepository->countByUser($user) >= self::LAYOUT_LIMIT_PER_USER) {
            throw ColonyLayoutViolations::exception($data, [['path' => 'layouts', 'code' => 'layout_limit_reached']]);
        }

        $errors = [];
        $gameTemplate = $this->parseTemplate($data->template, $errors);
        $name = $this->resolveName($data->name, $gameTemplate, $errors);

        if ($errors !== [] || $gameTemplate === null || $name === null) {
            throw ColonyLayoutViolations::exception($data, $errors);
        }

        $layout = (new PlanetaryColonyLayout())
            ->setUser($user)
            ->setName($name)
            ->setTemplate($gameTemplate);

        $this->entityManager->persist($layout);
        $this->entityManager->flush();

        return PlanetaryColonyLayoutResource::fromEntity($layout, $this->summarizer->summarize($gameTemplate));
    }

    /**
     * @param list<array{path: string, code: string}> $errors
     *
     * @return array<string, mixed>|null
     */
    private function parseTemplate(mixed $template, array &$errors): ?array
    {
        if ($template === null) {
            $errors[] = ['path' => 'template', 'code' => 'missing'];

            return null;
        }
        if (!\is_string($template)) {
            $errors[] = ['path' => 'template', 'code' => 'invalid_type'];

            return null;
        }

        try {
            return $this->parser->parse($template);
        } catch (InvalidPlanetaryColonyLayoutException $exception) {
            array_push($errors, ...$exception->errors());

            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $gameTemplate
     * @param list<array{path: string, code: string}> $errors
     */
    private function resolveName(mixed $enteredName, ?array $gameTemplate, array &$errors): ?string
    {
        if ($enteredName === null) {
            if ($gameTemplate === null) {
                return null;
            }
            $enteredName = $gameTemplate['Cmt'] ?? '';
        }
        if (!\is_string($enteredName)) {
            $errors[] = ['path' => 'name', 'code' => 'invalid_type'];

            return null;
        }

        $name = trim($enteredName);
        $errorCode = $this->nameRules->errorCodeOf($name);
        if ($errorCode !== null) {
            $errors[] = ['path' => 'name', 'code' => $errorCode];

            return null;
        }

        return $name;
    }
}
