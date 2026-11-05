<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Delete;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\User;
use App\Repository\IndustryStepJobMatchRepository;
use App\State\Provider\Industry\JobMatchDeleteProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(JobMatchDeleteProvider::class)]
#[Group('issue-200')]
final class JobMatchDeleteProviderTest extends TestCase
{
    public function testJobMatchIdThatIsNotAUuidIsNotFoundWithoutReadingTheJobMatches(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $matchRepository = $this->createMock(IndustryStepJobMatchRepository::class);
        $matchRepository->expects($this->never())->method('find');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Job match not found');

        (new JobMatchDeleteProvider($security, $matchRepository))
            ->provide(new Delete(), ['id' => 'm-1']);
    }

    #[Group('issue-206')]
    public function testJobMatchOfAStepOfAnotherPilotIsNotFound(): void
    {
        $step = (new IndustryProjectStep())->setActivityType('manufacturing')->setRuns(12)->setQuantity(12);
        (new IndustryProject())->setOwner(new User())->addStep($step);
        $matchOfAnotherPilot = (new IndustryStepJobMatch())->setEsiJobId(307)->setRuns(12)->setStatus('active');
        $step->addJobMatch($matchOfAnotherPilot);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $matchRepository = $this->createStub(IndustryStepJobMatchRepository::class);
        $matchRepository->method('find')->willReturn($matchOfAnotherPilot);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Job match not found');

        (new JobMatchDeleteProvider($security, $matchRepository))
            ->provide(new Delete(), ['id' => Uuid::v4()->toRfc4122()]);
    }
}
