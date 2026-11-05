<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\GroupIndustry;

use App\Entity\Character;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\IndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait BuildsIshtarGroupProject
{
    private const int OWNER_CORPORATION_ID = 98_206_101;
    private const int MEMBER_CORPORATION_ID = 98_206_102;
    private const int OUTSIDER_CORPORATION_ID = 98_206_103;

    private const int TRITANIUM_TYPE_ID = 34;
    private const int NOCXIUM_TYPE_ID = 38;
    private const int MORPHITE_TYPE_ID = 11399;
    private const int RAM_STARSHIP_TECH_TYPE_ID = 11478;
    private const int ISHTAR_TYPE_ID = 12005;
    private const int HECATE_TYPE_ID = 35683;

    private const int RAM_RUNS = 3;
    private const int RAM_UNITS_PER_RUN = 100;
    private const int ISHTAR_RUNS = 2;

    private const string CONTAINER_NAME = 'ISHTAR-206';
    private const string NOT_A_UUID = 'abc';
    private const string REVIEWED_AT = '2026-10-30T12:00:00+00:00';

    private static int $nextEveCharacterId = 2_112_206_101;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private User $member;
    private User $pendingMember;
    private User $outsider;
    private User $ownerCorpmate;
    private IndustryProject $ishtarBatch;
    private IndustryProject $hecateBatch;
    private GroupIndustryProjectMember $ownerMembership;
    private GroupIndustryProjectMember $memberMembership;
    private GroupIndustryProjectMember $pendingMembership;
    private GroupIndustryProjectMember $hecateOwnerMembership;
    private GroupIndustryBomItem $tritaniumLine;
    private GroupIndustryBomItem $morphiteLine;
    private GroupIndustryBomItem $nocxiumLine;
    private GroupIndustryBomItem $ramJobLine;
    private GroupIndustryBomItem $ishtarJobLine;
    private GroupIndustryBomItem $hecateTritaniumLine;
    private GroupIndustryContribution $memberMorphiteContribution;
    private GroupIndustryContribution $ownerIshtarJobInstall;
    private GroupIndustryContribution $memberRamJobInstall;
    private GroupIndustrySale $ishtarSale;

    private function setUpIshtarGroupProject(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->owner = $this->persistPilot('Aria Solberg', self::OWNER_CORPORATION_ID);
        $this->member = $this->persistPilot('Brann Holt', self::MEMBER_CORPORATION_ID);
        $this->pendingMember = $this->persistPilot('Dorn Achar', self::MEMBER_CORPORATION_ID);
        $this->outsider = $this->persistPilot('Elia Varr', self::OUTSIDER_CORPORATION_ID);
        $this->ownerCorpmate = $this->persistPilot('Corin Vale', self::OWNER_CORPORATION_ID);

        $this->ishtarBatch = $this->persistProject('Ishtar batch');
        $this->ishtarBatch->setContainerName(self::CONTAINER_NAME)->setBrokerFeePercent(3.0)->setSalesTaxPercent(4.5);
        $this->ownerMembership = $this->addMembership($this->ishtarBatch, $this->owner, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->memberMembership = $this->addMembership($this->ishtarBatch, $this->member, GroupMemberRole::Member, GroupMemberStatus::Accepted);
        $this->pendingMembership = $this->addMembership($this->ishtarBatch, $this->pendingMember, GroupMemberRole::Member, GroupMemberStatus::Pending);

        $this->tritaniumLine = $this->addMaterialLine($this->ishtarBatch, self::TRITANIUM_TYPE_ID, 'Tritanium', 12_000, 4.25);
        $this->morphiteLine = $this->addMaterialLine($this->ishtarBatch, self::MORPHITE_TYPE_ID, 'Morphite', 150, 18_500.0)->setFulfilledQuantity(3);
        $this->nocxiumLine = $this->addMaterialLine($this->ishtarBatch, self::NOCXIUM_TYPE_ID, 'Nocxium', 600, null);
        $this->ramJobLine = $this->addJobLine($this->ishtarBatch, self::RAM_STARSHIP_TECH_TYPE_ID, 'R.A.M.- Starship Tech', 'component', self::RAM_RUNS, self::RAM_RUNS * self::RAM_UNITS_PER_RUN, 10, 20, self::ISHTAR_TYPE_ID);
        $this->ishtarJobLine = $this->addJobLine($this->ishtarBatch, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_RUNS, self::ISHTAR_RUNS, 4, 8, null)->setFulfilledQuantity(self::ISHTAR_RUNS);

        $this->memberMorphiteContribution = $this->addContribution($this->memberMembership, $this->morphiteLine, ContributionType::Material, 3, 55_500.0, ContributionStatus::Approved);
        $this->ownerIshtarJobInstall = $this->addContribution($this->ownerMembership, $this->ishtarJobLine, ContributionType::JobInstall, self::ISHTAR_RUNS, 499_500.0, ContributionStatus::Approved);
        $this->memberRamJobInstall = $this->addContribution($this->memberMembership, $this->ramJobLine, ContributionType::JobInstall, self::RAM_RUNS * self::RAM_UNITS_PER_RUN, 180_000.0, ContributionStatus::Pending)
            ->setNote('Three runs in Jita IV');

        $this->ishtarSale = $this->addSale($this->ishtarBatch, self::ISHTAR_TYPE_ID, 'Ishtar', 2, 1_500_000.0, 'Jita IV - Moon 4', '2026-10-31T18:20:00+00:00');

        $this->hecateBatch = $this->persistProject('Hecate batch');
        $this->hecateOwnerMembership = $this->addMembership($this->hecateBatch, $this->owner, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->hecateTritaniumLine = $this->addMaterialLine($this->hecateBatch, self::TRITANIUM_TYPE_ID, 'Tritanium', 40_000, 4.4);
        $this->addSale($this->hecateBatch, self::HECATE_TYPE_ID, 'Hecate', 1, 45_000_000.0, 'Amarr VIII (Oris)', '2026-11-01T09:00:00+00:00');

        $this->em->flush();
    }

    private function persistPilot(string $name, int $corporationId): User
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId($corporationId)
            ->setCorporationName(sprintf('Corporation %d', $corporationId));
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);

        return $user;
    }

    private function persistProject(string $name): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->owner)->setName($name)->setStatus(GroupProjectStatus::InProgress);
        $this->em->persist($project);

        return $project;
    }

    private function addMembership(IndustryProject $project, User $user, GroupMemberRole $role, GroupMemberStatus $status): GroupIndustryProjectMember
    {
        $membership = (new GroupIndustryProjectMember())->setUser($user)->setRole($role)->setStatus($status);
        $project->addMember($membership);

        return $membership;
    }

    private function addMaterialLine(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity, ?float $bomPrice): GroupIndustryBomItem
    {
        $line = (new GroupIndustryBomItem())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setEstimatedPrice($bomPrice)
            ->setIsJob(false);
        $project->addBomItem($line);

        return $line;
    }

    private function addJobLine(IndustryProject $project, int $typeId, string $typeName, string $jobGroup, int $runs, int $quantity, int $meLevel, int $teLevel, ?int $parentTypeId): GroupIndustryBomItem
    {
        $line = (new GroupIndustryBomItem())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($quantity)
            ->setIsJob(true)
            ->setJobGroup($jobGroup)
            ->setActivityType('manufacturing')
            ->setRuns($runs)
            ->setMeLevel($meLevel)
            ->setTeLevel($teLevel)
            ->setParentTypeId($parentTypeId);
        $project->addBomItem($line);

        return $line;
    }

    private function addContribution(GroupIndustryProjectMember $membership, GroupIndustryBomItem $line, ContributionType $type, int $quantity, float $estimatedValue, ContributionStatus $status): GroupIndustryContribution
    {
        $contribution = (new GroupIndustryContribution())
            ->setMember($membership)
            ->setBomItem($line)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue($estimatedValue)
            ->setStatus($status)
            ->setIsAutoDetected(false)
            ->setIsVerified(false);
        if ($status === ContributionStatus::Approved) {
            $contribution->setReviewedBy($this->owner)->setReviewedAt(new \DateTimeImmutable(self::REVIEWED_AT));
        }
        $membership->getProject()->addContribution($contribution);

        return $contribution;
    }

    private function addSale(IndustryProject $project, int $typeId, string $typeName, int $quantity, float $unitPrice, string $venue, string $soldAt): GroupIndustrySale
    {
        $sale = (new GroupIndustrySale())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setVenue($venue)
            ->setSoldAt(new \DateTimeImmutable($soldAt))
            ->setRecordedBy($this->owner);
        $project->addSale($sale);

        return $sale;
    }

    private function projectUri(IndustryProject $project): string
    {
        return '/api/group-industry/projects/'.$project->getId()?->toRfc4122();
    }

    private function idOf(GroupIndustryProjectMember|GroupIndustryBomItem|GroupIndustryContribution|GroupIndustrySale $entity): string
    {
        return (string) $entity->getId()?->toRfc4122();
    }

    private function request(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function errorDetail(): array
    {
        $error = $this->responseJson();

        return [$error['status'] ?? null, $error['detail'] ?? null];
    }

    private function violatedPaths(): array
    {
        return array_values(array_unique(array_column($this->responseJson()['violations'] ?? [], 'propertyPath')));
    }

    private function rowsSortedBy(string $key, array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => $a[$key] <=> $b[$key]);

        return $rows;
    }
}
