<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\EveSsoTokenRevoker;
use App\Service\ESI\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

class AccountDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EveSsoTokenRevoker $tokenRevoker,
        private readonly TokenManager $tokenManager,
        private readonly TagAwareAdapterInterface $esiCache,
    ) {
    }

    public function delete(User $user): void
    {
        $refreshTokensByEveCharacterId = $this->decryptedRefreshTokens($user);
        $cacheTags = $this->esiCacheTags($user);

        $this->entityManager->wrapInTransaction(function () use ($user): void {
            $deletedProjects = $this->handOverOwnedProjects($user);
            $this->anonymizeMemberships($user, $deletedProjects);
            $this->structureConfigRepository()->removeUserFromHiddenByUsers((string) $user->getId()?->toRfc4122());
            $this->entityManager->remove($user);
        });

        foreach ($refreshTokensByEveCharacterId as $eveCharacterId => $refreshToken) {
            $this->tokenRevoker->revoke($refreshToken, $eveCharacterId);
        }

        $this->esiCache->invalidateTags($cacheTags);
    }

    /**
     * @return array<int, string>
     */
    private function decryptedRefreshTokens(User $user): array
    {
        $refreshTokens = [];
        foreach ($user->getCharacters() as $character) {
            $eveToken = $character->getEveToken();
            if ($eveToken === null) {
                continue;
            }

            try {
                $refreshTokens[$character->getEveCharacterId()] = $this->tokenManager->decryptRefreshToken($eveToken->getRefreshTokenEncrypted());
            } catch (\RuntimeException) {
                $this->tokenRevoker->reportUnreadableRefreshToken($character->getEveCharacterId());
            }
        }

        return $refreshTokens;
    }

    /**
     * @return list<string>
     */
    private function esiCacheTags(User $user): array
    {
        $cacheTags = [];
        foreach ($user->getCharacters() as $character) {
            $characterId = $character->getId();
            if ($characterId !== null) {
                $cacheTags[] = EsiClient::cacheTagForCharacter($characterId);
            }
        }

        return $cacheTags;
    }

    /**
     * @return list<GroupIndustryProject>
     */
    private function handOverOwnedProjects(User $user): array
    {
        $memberRepository = $this->memberRepository();
        $deletedProjects = [];

        foreach ($this->entityManager->getRepository(GroupIndustryProject::class)->findBy(['owner' => $user]) as $project) {
            $successor = $memberRepository->findOwnershipSuccessor($project, $user);
            $newOwner = $successor?->getUser();

            if ($successor === null || $newOwner === null) {
                $this->entityManager->remove($project);
                $deletedProjects[] = $project;

                continue;
            }

            $successor->setRole(GroupMemberRole::Owner);
            $project->setOwner($newOwner);
        }

        return $deletedProjects;
    }

    /**
     * @param list<GroupIndustryProject> $deletedProjects
     */
    private function anonymizeMemberships(User $user, array $deletedProjects): void
    {
        $contributionRepository = $this->entityManager->getRepository(GroupIndustryContribution::class);

        foreach ($this->memberRepository()->findBy(['user' => $user]) as $membership) {
            if (\in_array($membership->getProject(), $deletedProjects, true)) {
                continue;
            }

            if ($membership->getStatus() === GroupMemberStatus::Pending) {
                $this->entityManager->remove($membership);

                continue;
            }

            $unapprovedContributions = $contributionRepository->findBy([
                'member' => $membership,
                'status' => [ContributionStatus::Pending, ContributionStatus::Rejected],
            ]);
            foreach ($unapprovedContributions as $contribution) {
                $this->entityManager->remove($contribution);
            }

            $membership->anonymize();
        }
    }

    private function memberRepository(): GroupIndustryProjectMemberRepository
    {
        $repository = $this->entityManager->getRepository(GroupIndustryProjectMember::class);
        if (!$repository instanceof GroupIndustryProjectMemberRepository) {
            throw new \LogicException(sprintf('%s must be the repository of %s.', GroupIndustryProjectMemberRepository::class, GroupIndustryProjectMember::class));
        }

        return $repository;
    }

    private function structureConfigRepository(): IndustryStructureConfigRepository
    {
        $repository = $this->entityManager->getRepository(IndustryStructureConfig::class);
        if (!$repository instanceof IndustryStructureConfigRepository) {
            throw new \LogicException(sprintf('%s must be the repository of %s.', IndustryStructureConfigRepository::class, IndustryStructureConfig::class));
        }

        return $repository;
    }
}
