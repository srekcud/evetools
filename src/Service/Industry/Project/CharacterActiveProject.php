<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Repository\IndustryProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CharacterActiveProject
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly CharacterRepository $characterRepository,
    ) {
    }

    public function dedicate(User $user, Character $character, ?IndustryProject $project): void
    {
        if ($character->getUser() !== $user) {
            throw new NotFoundHttpException('Character not found');
        }

        if ($project !== null && !$this->isOpenProjectOfAcceptedParticipant($user, $project)) {
            throw new NotFoundHttpException('Project not found');
        }

        $character->setActiveProject($project);
        $this->entityManager->flush();
    }

    public function clearForProject(IndustryProject $project): void
    {
        foreach ($this->characterRepository->findBy(['activeProject' => $project]) as $character) {
            $character->setActiveProject(null);
        }
    }

    public function clearForParticipant(IndustryProject $project, User $user): void
    {
        foreach ($this->characterRepository->findBy(['activeProject' => $project, 'user' => $user]) as $character) {
            $character->setActiveProject(null);
        }
    }

    private function isOpenProjectOfAcceptedParticipant(User $user, IndustryProject $project): bool
    {
        foreach ($this->projectRepository->findOpenOfAcceptedParticipant($user) as $openProject) {
            if ($openProject->getId()->equals($project->getId())) {
                return true;
            }
        }

        return false;
    }
}
