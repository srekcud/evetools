<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectLineBlueprintResource;
use App\ApiResource\Input\Industry\Project\AttachLineBlueprintInput;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Exception\InvalidLineBlueprintException;
use App\Repository\IndustryProjectLineBlueprintRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\Blueprint\LineBlueprintAttacher;
use App\Service\Industry\Project\Blueprint\LineBlueprintPositions;
use App\Service\Industry\Project\Blueprint\LineBlueprintReading;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectLineBlueprintResourceMapper;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<AttachLineBlueprintInput, ProjectLineBlueprintResource>
 */
final readonly class AttachLineBlueprintProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectLineBlueprintRepository $lineBlueprintRepository,
        private ProjectAccessChecker $accessChecker,
        private LineBlueprintAttacher $attacher,
        private LineBlueprintPositions $positions,
        private ProjectLineBlueprintResourceMapper $mapper,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectLineBlueprintResource
    {
        \assert($data instanceof AttachLineBlueprintInput);

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        if ($project->isCompleted()) {
            throw new ConflictHttpException('The project is completed, its step blueprints can no longer change');
        }

        $lineId = self::stringOf($data->bomLineId);
        $itemId = self::intOf($data->itemId);
        $line = $project->getBomItems()->filter(static fn (IndustryProjectBomLine $candidate): bool => $candidate->getId()->toRfc4122() === $lineId)->first() ?: null;
        if (null === $line) {
            throw new NotFoundHttpException('Line not found');
        }

        if (null !== $this->lineBlueprintRepository->findOneByProjectAndItemId($project, $itemId)) {
            throw new ConflictHttpException('This blueprint is already attached to a step of the project');
        }

        try {
            $attachment = $this->attacher->attach($line, $user, $itemId, new \DateTimeImmutable());
        } catch (InvalidLineBlueprintException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException('This blueprint is already attached to a step of the project', $exception);
        }

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'line_blueprint_changed',
            ['lineId' => $lineId, 'itemId' => $itemId, 'action' => 'attached'],
        );

        $readings = array_values(array_filter(
            $this->positions->readingsOf($project),
            static fn (LineBlueprintReading $reading): bool => $reading->attachment === $attachment,
        ));

        return $this->mapper->toResources($readings)[0] ?? throw new \LogicException('The attached blueprint is read back.');
    }

    private static function stringOf(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \LogicException('The validated input carries a string.');
        }

        return $value;
    }

    private static function intOf(mixed $value): int
    {
        if (!\is_int($value)) {
            throw new \LogicException('The validated input carries an integer.');
        }

        return $value;
    }
}
