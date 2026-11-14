<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectTemplate;
use App\Entity\User;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProject>
 */
class IndustryProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProject::class);
    }

    /**
     * @param ProjectStatus[] $statuses
     * @return IndustryProject[]
     */
    public function findDiscoverableByOwnerCorporation(int $corporationId, array $statuses): array
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('owner', 'mc', 'ownerToken')
            ->join('p.owner', 'owner')
            ->join('owner.mainCharacter', 'mc')
            // Character::eveToken is an inverse OneToOne: not joined, Doctrine would load it row by row
            ->leftJoin('mc.eveToken', 'ownerToken')
            ->where('mc.corporationId = :corpId')
            ->andWhere('p.private = false')
            ->setParameter('corpId', $corporationId);

        if (!empty($statuses)) {
            $qb->andWhere('p.status IN (:statuses)')
                ->setParameter('statuses', array_map(fn (ProjectStatus $s) => $s->value, $statuses));
        }

        $qb->orderBy('p.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<IndustryProject>
     */
    public function findOpenOfAcceptedParticipant(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.members', 'm')
            ->where('m.user = :user')
            ->andWhere('m.status = :accepted')
            ->andWhere('p.status != :completed')
            ->setParameter('user', $user)
            ->setParameter('accepted', ParticipantStatus::Accepted)
            ->setParameter('completed', ProjectStatus::Completed)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<IndustryProjectTemplate> $templates
     * @return array<string, int>
     */
    public function countByTemplate(array $templates): array
    {
        if ($templates === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.template) AS templateId', 'COUNT(p.id) AS usageCount')
            ->where('p.template IN (:templates)')
            ->setParameter('templates', $templates)
            ->groupBy('p.template')
            ->getQuery()
            ->getArrayResult();

        $countsByTemplateId = [];
        foreach ($rows as $row) {
            $countsByTemplateId[(string) $row['templateId']] = (int) $row['usageCount'];
        }

        $counts = [];
        foreach ($templates as $template) {
            $templateId = (string) $template->getId();
            $counts[$templateId] = array_key_exists($templateId, $countsByTemplateId) ? $countsByTemplateId[$templateId] : 0;
        }

        return $counts;
    }

    public function findByShortLinkCode(string $code): ?IndustryProject
    {
        return $this->createQueryBuilder('p')
            ->where('p.shortLinkCode = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<IndustryProject>
     */
    public function findOpenOwnedBy(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.owner = :user')
            ->andWhere('p.status != :completed')
            ->setParameter('user', $user)
            ->setParameter('completed', ProjectStatus::Completed)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<IndustryProject>
     */
    public function findOpenOwnedByCorporation(int $corporationId): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.owner', 'owner')
            ->join('owner.mainCharacter', 'mc')
            ->where('mc.corporationId = :corporationId')
            ->andWhere('p.status != :completed')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('completed', ProjectStatus::Completed)
            ->getQuery()
            ->getResult();
    }
}
