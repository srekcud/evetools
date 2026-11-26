<?php

declare(strict_types=1);

namespace App\State\Processor\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Planetary\RenameColonyLayoutInput;
use App\ApiResource\Planetary\PlanetaryColonyLayoutResource;
use App\Entity\User;
use App\Repository\PlanetaryColonyLayoutRepository;
use App\Service\Planetary\Layout\PlanetaryColonyLayoutName;
use App\Service\Planetary\Layout\PlanetaryColonyLayoutSummarizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<RenameColonyLayoutInput, PlanetaryColonyLayoutResource>
 */
final readonly class RenameColonyLayoutProcessor implements ProcessorInterface
{
    private const string NOT_FOUND_MESSAGE = 'Colony layout not found';

    public function __construct(
        private Security $security,
        private PlanetaryColonyLayoutRepository $layoutRepository,
        private EntityManagerInterface $entityManager,
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

        $id = (string) $uriVariables['id'];

        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException(self::NOT_FOUND_MESSAGE);
        }

        $layout = $this->layoutRepository->findOneByUserAndId($user, Uuid::fromString($id));

        if ($layout === null) {
            throw new NotFoundHttpException(self::NOT_FOUND_MESSAGE);
        }

        assert($data instanceof RenameColonyLayoutInput);

        if (!\is_string($data->name)) {
            throw ColonyLayoutViolations::exception($data, [['path' => 'name', 'code' => $data->name === null ? 'missing' : 'invalid_type']]);
        }

        $name = trim($data->name);
        $errorCode = $this->nameRules->errorCodeOf($name);
        if ($errorCode !== null) {
            throw ColonyLayoutViolations::exception($data, [['path' => 'name', 'code' => $errorCode]]);
        }

        $layout->setName($name);
        $this->entityManager->flush();

        return PlanetaryColonyLayoutResource::fromEntity($layout, $this->summarizer->summarize($layout->getTemplate()));
    }
}
