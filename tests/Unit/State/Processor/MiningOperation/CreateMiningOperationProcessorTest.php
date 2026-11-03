<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\MiningOperation\CreateMiningOperationInput;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\User;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationEnrolment;
use App\State\Processor\MiningOperation\CreateMiningOperationProcessor;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use App\Tests\Unit\Service\MiningOperation\BuildsSpodumainOperation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(CreateMiningOperationProcessor::class)]
#[Group('issue-262')]
#[AllowMockObjectsWithoutExpectations]
final class CreateMiningOperationProcessorTest extends TestCase
{
    use BuildsSpodumainOperation;

    private Security&Stub $security;
    private CreateMiningOperationProcessor $processor;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->security = $this->createStub(Security::class);
        $this->processor = new CreateMiningOperationProcessor(
            $this->security,
            new MiningOperationEnrolment($this->characterRepositoryReadingOpenEnrolments(), $this->createMock(EntityManagerInterface::class)),
            new MiningOperationResourceMapper(),
        );
    }

    public function testAriaCreatesTheSpodumainOperationAndReadsItAsItsAcceptedOwner(): void
    {
        $this->actAs($this->aria);

        $resource = $this->create('corporation', 'value_prorata', ['Aria Solberg', 'Aria II']);

        self::assertSame(self::SPODUMAIN_LABEL, $resource->label);
        self::assertSame('planned', $resource->status);
        self::assertSame('corporation', $resource->visibility);
        self::assertSame('value_prorata', $resource->splitMode);
        self::assertSame(98262101, $resource->corporationId);
        self::assertSame(self::PLANNED_START, $resource->plannedStartAt?->format(\DateTimeInterface::ATOM));
        self::assertMatchesRegularExpression(self::JOIN_CODE_PATTERN, (string) $resource->joinCode);
        self::assertSame('owner', $resource->myRole);
        self::assertSame('accepted', $resource->myStatus);
        self::assertSame(1, $resource->participantsCount);
        self::assertSame(2, $resource->charactersCount);
        self::assertSame('Aria Solberg', $resource->ownerCharacterName);
    }

    public function testPrivateEachForThemselvesOperationCountsItsSingleEnrolledCharacter(): void
    {
        $this->actAs($this->aria);

        $resource = $this->create('private', 'each_for_themselves', ['Aria II']);

        self::assertSame(1, $resource->charactersCount);
    }

    public function testOwnerWithoutMainCharacterHasNoOwnerCharacterNameNorCorporation(): void
    {
        $this->actAs($this->dorn);

        $resource = $this->create('private', 'value_prorata', ['Dorn Achar']);

        self::assertSame('private', $resource->visibility);
        self::assertNull($resource->corporationId);
        self::assertNull($resource->ownerCharacterName);
    }

    public function testAnonymousCreationIsUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->processor->process(new CreateMiningOperationInput(), new Post());
    }

    private function actAs(User $user): void
    {
        $this->security->method('getUser')->willReturn($user);
    }

    private function create(string $visibility, string $splitMode, array $characterNames): MiningOperationResource
    {
        $input = new CreateMiningOperationInput();
        $input->label = self::SPODUMAIN_LABEL;
        $input->plannedStartAt = new \DateTimeImmutable(self::PLANNED_START);
        $input->visibility = $visibility;
        $input->splitMode = $splitMode;
        $input->characterIds = $this->idsOf($characterNames);

        return $this->processor->process($input, new Post());
    }
}
