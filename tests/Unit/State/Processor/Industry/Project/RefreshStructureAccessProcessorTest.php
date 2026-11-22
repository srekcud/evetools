<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Post;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Message\RefreshProjectStructureAccess;
use App\Repository\IndustryProjectRepository;
use App\State\Processor\Industry\Project\RefreshStructureAccessProcessor;
use App\Tests\Unit\State\Provider\Industry\Project\BuildsWorkshopProjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(RefreshStructureAccessProcessor::class)]
#[Group('issue-1299')]
final class RefreshStructureAccessProcessorTest extends TestCase
{
    use BuildsWorkshopProjects;

    public function testSecondRefreshOfTheSameProjectWithinTheCooldownIsRefusedAndQueuesNothing(): void
    {
        $owner = new User();
        $project = $this->workshopProject('Ishtar batch', ProjectStatus::InProgress, $owner);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($owner);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($project);
        $dispatched = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
            $dispatched[] = $message;

            return new Envelope($message);
        });
        $processor = new RefreshStructureAccessProcessor($security, $projectRepository, $bus, new ArrayAdapter());
        $uriVariables = ['projectId' => $project->getId()->toRfc4122()];

        $processor->process(null, new Post(), $uriVariables);

        $this->expectException(TooManyRequestsHttpException::class);
        try {
            $processor->process(null, new Post(), $uriVariables);
        } finally {
            self::assertEquals([new RefreshProjectStructureAccess($project->getId()->toRfc4122())], $dispatched);
        }
    }
}
