<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\IndustryProjectTemplateResource;
use App\ApiResource\Input\Industry\Project\CreateProjectTemplateInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectTemplate;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectTemplateRepository;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<CreateProjectTemplateInput, IndustryProjectTemplateResource>
 */
class CreateProjectTemplateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly IndustryProjectTemplateRepository $templateRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IndustryProjectTemplateResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateProjectTemplateInput);
        assert($data->projectId !== null && $data->name !== null);

        $project = $this->projectRepository->find(Uuid::fromString($data->projectId));

        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        if ($this->templateRepository->countByOwner($user) >= IndustryProjectTemplate::MAXIMUM_PER_OWNER) {
            throw new UnprocessableEntityHttpException(sprintf('At most %d templates can be saved', IndustryProjectTemplate::MAXIMUM_PER_OWNER));
        }

        $template = (new IndustryProjectTemplate())
            ->setOwner($user)
            ->setName($data->name)
            ->setItems($this->itemsOf($project))
            ->setBlacklistGroupIds(array_values($project->getBlacklistGroupIds()))
            ->setBlacklistTypeIds(array_values($project->getBlacklistTypeIds()))
            ->setAcceptsOutsideFavoriteSystem($project->isAcceptsOutsideFavoriteSystem())
            ->setExcludesInvention($project->isExcludesInvention())
            ->setMinimumOutsideSaving($project->getMinimumOutsideSaving())
            ->setSellStructure($project->getSellStructureId(), $project->getSellStructureName())
            ->setSharedWithCorporation($data->sharedWithCorporation);

        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return IndustryProjectTemplateResource::fromTemplate($template, $user, 0);
    }

    /** @return list<array{typeId: int, typeName: string, meLevel: int, teLevel: int, runs: int}> */
    private function itemsOf(IndustryProject $project): array
    {
        $products = $project->getItems()->toArray();
        usort($products, static fn (IndustryProjectProduct $first, IndustryProjectProduct $second): int => $first->getSortOrder() <=> $second->getSortOrder());

        return array_map(
            static fn (IndustryProjectProduct $product): array => [
                'typeId' => $product->getTypeId(),
                'typeName' => $product->getTypeName(),
                'meLevel' => $product->getMeLevel(),
                'teLevel' => $product->getTeLevel(),
                'runs' => $product->getRuns(),
            ],
            $products,
        );
    }
}
