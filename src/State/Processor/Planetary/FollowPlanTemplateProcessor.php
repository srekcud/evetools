<?php

declare(strict_types=1);

namespace App\State\Processor\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Planetary\FollowPlanTemplateInput;
use App\ApiResource\Planetary\PlanetaryPlanTemplateResource;
use App\Entity\User;
use App\Repository\PlanetaryPlanTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<FollowPlanTemplateInput, PlanetaryPlanTemplateResource>
 */
final readonly class FollowPlanTemplateProcessor implements ProcessorInterface
{
    private const string NOT_FOUND_MESSAGE = 'Plan template not found';
    private const string NOT_A_BOOLEAN_MESSAGE = 'This value should be of type bool.';

    public function __construct(
        private Security $security,
        private PlanetaryPlanTemplateRepository $templateRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlanetaryPlanTemplateResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $id = (string) $uriVariables['id'];

        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException(self::NOT_FOUND_MESSAGE);
        }

        $template = $this->templateRepository->findOneByUserAndId($user, Uuid::fromString($id));

        if ($template === null) {
            throw new NotFoundHttpException(self::NOT_FOUND_MESSAGE);
        }

        assert($data instanceof FollowPlanTemplateInput);

        if (!is_bool($data->followed)) {
            throw new ValidationException(new ConstraintViolationList([
                new ConstraintViolation(self::NOT_A_BOOLEAN_MESSAGE, self::NOT_A_BOOLEAN_MESSAGE, [], $data, 'followed', $data->followed),
            ]));
        }

        if ($data->followed) {
            foreach ($this->templateRepository->findFollowedByUser($user) as $followedTemplate) {
                $followedTemplate->setFollowed(false);
            }
        }

        $template->setFollowed($data->followed);
        $this->entityManager->flush();

        return PlanetaryPlanTemplateResource::fromEntity($template);
    }
}
