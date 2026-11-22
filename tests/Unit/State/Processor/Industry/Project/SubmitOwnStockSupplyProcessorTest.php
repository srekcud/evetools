<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Industry\Project\SubmitContributionInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ContributionValueSource;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\Industry\Project\UnclaimedStockGuard;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\ContributorWalletRefresher;
use App\State\Processor\Industry\Project\SubmitContributionProcessor;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use App\Tests\Unit\Service\Industry\Project\BuildsChainBomLines;
use App\Tests\Unit\Support\BuildsOwnStockSupplyGuard;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SubmitContributionProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-1318')]
final class SubmitOwnStockSupplyProcessorTest extends TestCase
{
    use BuildsChainBomLines;
    use BuildsOwnStockSupplyGuard;

    private const int FINAL_PRODUCT = 16_673;
    private const int PHOTONIC_METAMATERIALS = 1_153_002;
    private const int TRITANIUM = 34;
    private const int PHOTONIC_METAMATERIALS_IN_HANGAR = 90_000;

    private ProjectContributionService&MockObject $contributionService;
    private SubmitContributionProcessor $processor;
    private IndustryProject $project;
    private IndustryProjectBomLine $photonic;
    private IndustryProjectBomLine $tritanium;
    private User $owner;
    private User $contributor;
    private IndustryProjectParticipant $contributorParticipant;
    private array $verifiedOwnStockFlags = [];

    public function testMemberSupplyingAJobLineOfAGroupProjectWithinTheirOwnStockIsSubmittedAsVerifiedOwnStock(): void
    {
        $this->givenProjectOf(group: true);

        $this->process($this->input('material', $this->photonic, self::PHOTONIC_METAMATERIALS_IN_HANGAR));

        self::assertSame([true], $this->verifiedOwnStockFlags);
    }

    public function testMemberSupplyingAboveTheirOwnStockIsABadRequestWithTheAvailableQuantityAndNothingIsSubmitted(): void
    {
        $this->givenProjectOf(group: true);

        $failure = null;
        try {
            $this->process($this->input('material', $this->photonic, self::PHOTONIC_METAMATERIALS_IN_HANGAR + 1));
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(BadRequestHttpException::class, $failure);
        self::assertStringContainsString('exceeds your own stock', $failure->getMessage());
        self::assertStringContainsString('(90000)', $failure->getMessage());
        self::assertSame([], $this->verifiedOwnStockFlags);
    }

    #[DataProvider('submissionsOutsideTheOwnStockSupply')]
    public function testSubmissionOutsideTheOwnStockSupplyIsNeverCheckedNorMarkedVerified(bool $group, string $type, string $line, ?string $valueSource): void
    {
        $this->givenProjectOf($group);
        $input = $this->input($type, 'job' === $line ? $this->photonic : $this->tritanium, 10_000_000);
        $input->valueSource = $valueSource;
        $input->estimatedValue = 'job_install' === $type ? 1_000.0 : null;

        $this->process($input);

        self::assertSame([false], $this->verifiedOwnStockFlags);
    }

    public static function submissionsOutsideTheOwnStockSupply(): iterable
    {
        yield 'solo project of the contributor' => [false, 'material', 'job', null];
        yield 'job install instead of a material' => [true, 'job_install', 'job', null];
        yield 'material line instead of a job line' => [true, 'material', 'material', null];
        yield 'claim on the unclaimed stock' => [true, 'material', 'job', 'launch_price'];
    }

    private function givenProjectOf(bool $group): void
    {
        $this->owner = new User();
        $this->contributor = $group ? new User() : $this->owner;
        $this->project = (new IndustryProject())->setOwner($this->owner)->setStatus(ProjectStatus::InProgress);
        $this->assignId($this->project);
        $this->project->enrolOwner();
        if ($group) {
            $this->project->addMember(
                (new IndustryProjectParticipant())->setUser($this->contributor)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted),
            );
        }
        foreach ($this->project->getMembers() as $participant) {
            $this->assignId($participant);
            if ($participant->getUser() === $this->contributor) {
                $this->contributorParticipant = $participant;
            }
        }

        $this->photonic = $this->jobLine(self::PHOTONIC_METAMATERIALS, 'Photonic Metamaterials', 'component', self::FINAL_PRODUCT, 10)->setRequiredQuantity(300_000);
        $this->tritanium = $this->materialLine(self::TRITANIUM, 'Tritanium', 12_000, 0);
        $this->project->addBomItem($this->photonic);
        $this->project->addBomItem($this->tritanium);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->contributor);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);
        $memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $memberRepository->method('findOneBy')->willReturn($this->contributorParticipant);
        $bomItemRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomItemRepository->method('find')->willReturnCallback(
            fn (Uuid $bomItemId): ?IndustryProjectBomLine => match (true) {
                $bomItemId->equals($this->photonic->getId()) => $this->photonic,
                $bomItemId->equals($this->tritanium->getId()) => $this->tritanium,
                default => null,
            },
        );

        $this->verifiedOwnStockFlags = [];
        $this->contributionService = $this->createMock(ProjectContributionService::class);
        $this->contributionService->method('submit')->willReturnCallback(
            function (IndustryProjectParticipant $member, IndustryProjectBomLine $bomItem, ContributionType $type, int $quantity, ?float $estimatedValue = null, ?string $note = null, ?ContributionValueSource $valueSource = null, bool $verifiedOwnStock = false): IndustryProjectContribution {
                $this->verifiedOwnStockFlags[] = $verifiedOwnStock;
                $contribution = (new IndustryProjectContribution())
                    ->setProject($this->project)
                    ->setMember($member)
                    ->setBomItem($bomItem)
                    ->setType($type)
                    ->setQuantity($quantity)
                    ->setEstimatedValue(0.0)
                    ->setStatus(ContributionStatus::Approved);
                $this->assignId($contribution);

                return $contribution;
            },
        );

        $this->processor = new SubmitContributionProcessor(
            $security,
            $projectRepository,
            $memberRepository,
            $bomItemRepository,
            $this->contributionService,
            new ProjectResourceMapper(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            $this->createStub(ContributorWalletRefresher::class),
            $this->createStub(UnclaimedStockGuard::class),
            $this->ownStockSupplyGuardOver(fn (User $user): array => $user === $this->contributor ? [self::PHOTONIC_METAMATERIALS => self::PHOTONIC_METAMATERIALS_IN_HANGAR] : []),
        );
    }

    private function process(SubmitContributionInput $input): void
    {
        $this->processor->process($input, new Post(), ['projectId' => $this->project->getId()->toRfc4122()]);
    }

    private function input(string $type, IndustryProjectBomLine $line, int $quantity): SubmitContributionInput
    {
        $input = new SubmitContributionInput();
        $input->type = $type;
        $input->bomItemId = $line->getId()->toRfc4122();
        $input->quantity = $quantity;

        return $input;
    }

    private function assignId(object $entity): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, Uuid::v4());
    }
}
