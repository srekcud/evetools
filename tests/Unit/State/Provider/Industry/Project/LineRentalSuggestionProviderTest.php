<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\Project\LineRentalSuggestionResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Industry\Domain\LineRentalCategory;
use App\Repository\IndustryProjectBomLineRepository;
use App\Service\Industry\Project\LineRentalSuggester;
use App\Service\Industry\Project\LineRentalSuggestion;
use App\Service\Industry\Project\LineRentalUnknownReason;
use App\State\Provider\Industry\Project\LineRentalSuggestionProvider;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LineRentalSuggestionProvider::class)]
#[Group('issue-300')]
final class LineRentalSuggestionProviderTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private Security&Stub $security;
    private LineRentalSuggestionProvider $provider;

    private IndustryProjectBomLine $pulseLaserLine;
    private IndustryProjectBomLine $lineOfAnotherProject;
    private LineRentalSuggestion $suggestion;

    private array $suggestCalls = [];

    protected function setUp(): void
    {
        $this->createSabreBatch();
        $this->security = $this->createStub(Security::class);

        $this->pulseLaserLine = $this->lineOf($this->sabreBatch, 3057, 'Pulse Laser II');

        $hecateBatch = new IndustryProject();
        $hecateBatch->setOwner($this->sabreBatchOwner);
        $hecateBatch->setName('Hecate batch');
        $hecateBatch->setStatus(ProjectStatus::InProgress);
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($hecateBatch, Uuid::v4());
        $this->lineOfAnotherProject = $this->lineOf($hecateBatch, 2454, 'Hobgoblin II');

        $this->suggestion = new LineRentalSuggestion(5_961_805.56, null, LineRentalCategory::ModuleT2, 0.9618055555555556, 50, 1_000_000.0, 100_000.0);

        $suggester = $this->createStub(LineRentalSuggester::class);
        $suggester->method('suggest')->willReturnCallback(function (User $contributor, IndustryProjectBomLine $line): LineRentalSuggestion {
            $this->suggestCalls[] = [$contributor, $line];

            return $this->suggestion;
        });

        $lines = [$this->pulseLaserLine, $this->lineOfAnotherProject];
        $lineRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $lineRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?IndustryProjectBomLine => array_values(array_filter(
                $lines,
                static fn (IndustryProjectBomLine $line): bool => (string) $id === $line->getId()->toRfc4122(),
            ))[0] ?? null,
        );
        $lineRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?IndustryProjectBomLine => array_values(array_filter(
                $lines,
                static fn (IndustryProjectBomLine $line): bool => (string) $criteria['id'] === $line->getId()->toRfc4122()
                    && (!isset($criteria['project']) || $criteria['project'] === $line->getProject()),
            ))[0] ?? null,
        );

        $this->provider = new LineRentalSuggestionProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            $lineRepository,
            new ProjectAccessChecker($this->sabreBatchMemberRepository()),
            $suggester,
        );
    }

    #[DataProvider('acceptedMemberAccessMatrix')]
    public function testOnlyTheOwnerAndAcceptedMembersReachALineRentalSuggestion(string $profile, string $expectedOutcome): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile($profile));

        self::assertSame($expectedOutcome, $this->outcomeOf(fn () => $this->provideSuggestion($this->pulseLaserUri())));
    }

    public function testUserWithoutAccessIsNeverComputedASuggestion(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::STRANGER));

        $this->outcomeOf(fn () => $this->provideSuggestion($this->pulseLaserUri()));

        self::assertSame([], $this->suggestCalls);
    }

    public function testSuggestionIsComputedForTheCallingUserAndTheRequestedLine(): void
    {
        $member = $this->userWithProfile(self::ACCEPTED_MEMBER);
        $this->security->method('getUser')->willReturn($member);

        $this->provideSuggestion($this->pulseLaserUri());

        self::assertSame([[$member, $this->pulseLaserLine]], $this->suggestCalls);
    }

    public function testKnownSuggestionIsMappedToTheResource(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        $resource = $this->provideSuggestion($this->pulseLaserUri());

        self::assertSame(
            [$this->pulseLaserLine->getId()->toRfc4122(), 5_961_805.56, null, 'module_t2', 0.9618055555555556, 50, 1_000_000.0, 100_000.0],
            [$resource->bomItemId, $resource->suggestedValue, $resource->unknownReason, $resource->category, $resource->days, $resource->runs, $resource->dayFactor, $resource->ratePerRun],
        );
    }

    public function testUnknownSuggestionIsMappedWithItsReasonAndNoValue(): void
    {
        $this->suggestion = new LineRentalSuggestion(null, LineRentalUnknownReason::NoLinkedJob, null, null, null, null, null);
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        $resource = $this->provideSuggestion($this->pulseLaserUri());

        self::assertSame(
            [$this->pulseLaserLine->getId()->toRfc4122(), null, 'no_linked_job', null, null, null, null, null],
            [$resource->bomItemId, $resource->suggestedValue, $resource->unknownReason, $resource->category, $resource->days, $resource->runs, $resource->dayFactor, $resource->ratePerRun],
        );
    }

    public function testLineOfAnotherProjectIsNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        self::assertSame(404, $this->httpStatusOf(fn () => $this->provideSuggestion([
            'projectId' => $this->sabreBatch->getId()->toRfc4122(),
            'id' => $this->lineOfAnotherProject->getId()->toRfc4122(),
        ])));
        self::assertSame([], $this->suggestCalls);
    }

    public function testUnknownLineIsNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        self::assertSame(404, $this->httpStatusOf(fn () => $this->provideSuggestion([
            'projectId' => $this->sabreBatch->getId()->toRfc4122(),
            'id' => Uuid::v4()->toRfc4122(),
        ])));
    }

    public function testLineIdentifierThatIsNotAUuidIsNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        self::assertSame(404, $this->httpStatusOf(fn () => $this->provideSuggestion([
            'projectId' => $this->sabreBatch->getId()->toRfc4122(),
            'id' => 'abc',
        ])));
    }

    public function testUnknownProjectIsNotFound(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::OWNER));

        self::assertSame(404, $this->httpStatusOf(fn () => $this->provideSuggestion([
            'projectId' => Uuid::v4()->toRfc4122(),
            'id' => $this->pulseLaserLine->getId()->toRfc4122(),
        ])));
    }

    public function testRequestWithoutUserIsUnauthorized(): void
    {
        $this->security->method('getUser')->willReturn(null);

        self::assertSame(self::UNAUTHORIZED, $this->outcomeOf(fn () => $this->provideSuggestion($this->pulseLaserUri())));
    }

    private function lineOf(IndustryProject $project, int $typeId, string $typeName): IndustryProjectBomLine
    {
        $line = new IndustryProjectBomLine();
        $line->setProject($project);
        $line->setTypeId($typeId);
        $line->setTypeName($typeName);

        return $line;
    }

    private function pulseLaserUri(): array
    {
        return [
            'projectId' => $this->sabreBatch->getId()->toRfc4122(),
            'id' => $this->pulseLaserLine->getId()->toRfc4122(),
        ];
    }

    private function httpStatusOf(\Closure $request): int|string
    {
        try {
            $request();
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode();
        } catch (\Throwable $throwable) {
            return $throwable::class . ': ' . $throwable->getMessage();
        }

        return self::GRANTED;
    }

    private function provideSuggestion(array $uriVariables): LineRentalSuggestionResource
    {
        return $this->provider->provide(new Get(), $uriVariables);
    }
}
