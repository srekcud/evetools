<?php

declare(strict_types=1);

namespace App\State\Processor\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Planetary\PlanetarySettingsResource;
use App\Entity\User;
use App\Service\Planetary\PlanetaryHomeLocationCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<PlanetarySettingsResource, PlanetarySettingsResource>
 */
final readonly class PlanetarySettingsProcessor implements ProcessorInterface
{
    public const int MIN_COVERAGE_WEEKS = 1;
    public const int MAX_COVERAGE_WEEKS = 52;

    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private PlanetaryHomeLocationCatalog $catalog,
        private int $defaultMarketStructureId,
        private string $defaultMarketStructureName,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlanetarySettingsResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required');
        }

        assert($data instanceof PlanetarySettingsResource);

        $violations = new ConstraintViolationList();
        $this->checkCoverageWeeks($data->coverageWeeks, $violations);
        $homeLocationName = $this->resolveHomeLocationName($user, $data->homeLocationId, $violations);

        if ($violations->count() > 0) {
            throw new ValidationException($violations);
        }

        $user->setPlanetaryCoverageWeeks($data->coverageWeeks);
        $user->setPlanetaryHomeLocation($data->homeLocationId, $homeLocationName);
        $this->entityManager->flush();

        return PlanetarySettingsResource::forUser($user, $this->defaultMarketStructureId, $this->defaultMarketStructureName);
    }

    private function checkCoverageWeeks(int $coverageWeeks, ConstraintViolationList $violations): void
    {
        if ($coverageWeeks >= self::MIN_COVERAGE_WEEKS && $coverageWeeks <= self::MAX_COVERAGE_WEEKS) {
            return;
        }

        $message = sprintf('The coverage must be between %d and %d weeks.', self::MIN_COVERAGE_WEEKS, self::MAX_COVERAGE_WEEKS);
        $violations->add(new ConstraintViolation($message, $message, [], $coverageWeeks, 'coverageWeeks', $coverageWeeks));
    }

    private function resolveHomeLocationName(User $user, ?int $locationId, ConstraintViolationList $violations): ?string
    {
        if ($locationId === null) {
            return null;
        }

        if ($locationId === $user->getPlanetaryHomeLocationId()) {
            return $user->getPlanetaryHomeLocationName();
        }

        foreach ($this->catalog->forUser($user) as $candidate) {
            if ($candidate['locationId'] === $locationId) {
                return $candidate['locationName'];
            }
        }

        $message = 'This location is not known to any of your characters.';
        $violations->add(new ConstraintViolation($message, $message, [], $locationId, 'homeLocationId', $locationId));

        return null;
    }
}
