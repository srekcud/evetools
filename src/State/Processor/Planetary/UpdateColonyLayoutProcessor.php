<?php

declare(strict_types=1);

namespace App\State\Processor\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Planetary\UpdateColonyLayoutInput;
use App\ApiResource\Planetary\PlanetaryColonyLayoutResource;
use App\Entity\PlanetaryColonyLayout;
use App\Entity\User;
use App\Repository\PlanetaryColonyLayoutRepository;
use App\Service\Planetary\Layout\PlanetaryColonyLayoutName;
use App\Service\Planetary\Layout\PlanetaryColonyLayoutSummarizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UpdateColonyLayoutInput, PlanetaryColonyLayoutResource>
 */
final readonly class UpdateColonyLayoutProcessor implements ProcessorInterface
{
    private const string NOT_FOUND_MESSAGE = 'Colony layout not found';

    public function __construct(
        private Security $security,
        private PlanetaryColonyLayoutRepository $layoutRepository,
        private EntityManagerInterface $entityManager,
        private PlanetaryColonyLayoutSummarizer $summarizer,
        private PlanetaryColonyLayoutName $nameRules,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlanetaryColonyLayoutResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $id = (string) $uriVariables['id'];

        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException(self::NOT_FOUND_MESSAGE);
        }

        $layout = $this->layoutRepository->findOneByUserAndId($user, Uuid::fromString($id));

        if ($layout === null) {
            throw new NotFoundHttpException(self::NOT_FOUND_MESSAGE);
        }

        assert($data instanceof UpdateColonyLayoutInput);

        $provided = get_object_vars($data);
        $errors = [];
        $name = null;

        if (!\array_key_exists('name', $provided) && !\array_key_exists('public', $provided)) {
            $errors[] = ['path' => 'name', 'code' => 'missing'];
        }

        if (\array_key_exists('name', $provided)) {
            $name = $this->validatedName($provided['name'], $errors);
        }

        if (\array_key_exists('public', $provided) && !\is_bool($provided['public'])) {
            $errors[] = ['path' => 'public', 'code' => $provided['public'] === null ? 'missing' : 'invalid_type'];
        }

        if ($errors !== []) {
            throw ColonyLayoutViolations::exception($data, $errors);
        }

        if ($name !== null) {
            $layout->setName($name);
        }

        if (\array_key_exists('public', $provided)) {
            $this->applyPublication($layout, $provided['public'] === true);
        }

        $this->entityManager->flush();

        return PlanetaryColonyLayoutResource::fromEntity($layout, $this->summarizer->summarize($layout->getTemplate()));
    }

    /**
     * @param list<array{path: string, code: string}> $errors
     */
    private function validatedName(mixed $name, array &$errors): ?string
    {
        if (!\is_string($name)) {
            $errors[] = ['path' => 'name', 'code' => $name === null ? 'missing' : 'invalid_type'];

            return null;
        }

        $trimmed = trim($name);
        $errorCode = $this->nameRules->errorCodeOf($trimmed);
        if ($errorCode !== null) {
            $errors[] = ['path' => 'name', 'code' => $errorCode];

            return null;
        }

        return $trimmed;
    }

    private function applyPublication(PlanetaryColonyLayout $layout, bool $public): void
    {
        if (!$public) {
            $layout->unpublish();

            return;
        }

        if (!$layout->isPublic()) {
            $layout->publish($this->clock->now());
        }
    }
}
