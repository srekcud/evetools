<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[Group('issue-1287')]
final class ProjectBomLineSourcingMarketApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:published';
        });
        self::getContainer()->set(MercurePublisherService::class, new MercurePublisherService($hub, new NullLogger()));
    }

    public function testOwnerChoosesTheStructureThenJitaAndTheStoredValueIsReplacedOnTheSameLine(): void
    {
        $this->requestAs($this->owner, 'POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => 'structure']);

        self::assertResponseStatusCodeSame(200);
        $choice = $this->responseJson();
        self::assertSame(
            [$this->idOf($this->morphiteLine), $this->idOf($this->morphiteLine), 'structure'],
            [$choice['id'], $choice['lineId'], $choice['market']],
        );
        self::assertSame('structure', $this->storedSourcingMarketOf($this->morphiteLine));

        $this->requestAs($this->owner, 'POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => 'jita']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('jita', $this->responseJson()['market']);
        self::assertSame('jita', $this->storedSourcingMarketOf($this->morphiteLine));
        self::assertNull($this->storedSourcingMarketOf($this->tritaniumLine));
    }

    public function testRemovingTheChoiceGivesBackNullAndAFurtherRemovalIsNotFound(): void
    {
        $this->requestAs($this->owner, 'POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => 'structure']);
        self::assertResponseStatusCodeSame(200);

        $this->requestAs($this->owner, 'DELETE', $this->sourcingMarketUri($this->morphiteLine));

        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->storedSourcingMarketOf($this->morphiteLine));

        $this->requestAs($this->owner, 'DELETE', $this->sourcingMarketUri($this->morphiteLine));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAcceptedAdminChoosesAndRemovesTheChoiceOfALineNobodyReserved(): void
    {
        $admin = $this->persistPilot('Fenn Ador', self::OWNER_CORPORATION_ID);
        $this->addMembership($this->ishtarBatch, $admin, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->em->flush();

        $this->requestAs($admin, 'POST', $this->sourcingMarketUri($this->tritaniumLine), ['market' => 'structure']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('structure', $this->storedSourcingMarketOf($this->tritaniumLine));

        $this->requestAs($admin, 'DELETE', $this->sourcingMarketUri($this->tritaniumLine));
        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->storedSourcingMarketOf($this->tritaniumLine));
    }

    public function testAcceptedMemberWhoIsNotAdminCannotChooseNorRemoveEvenOnALineHeReserved(): void
    {
        $this->requestAs($this->owner, 'POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => 'structure']);
        self::assertResponseStatusCodeSame(200);
        $this->em->persist(new IndustryProjectLineReservation($this->morphiteLine, $this->memberMembership, 100));
        $this->em->flush();

        $this->requestAs($this->member, 'POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => 'jita']);
        self::assertResponseStatusCodeSame(403);
        $this->requestAs($this->member, 'DELETE', $this->sourcingMarketUri($this->morphiteLine));
        self::assertResponseStatusCodeSame(403);

        self::assertSame('structure', $this->storedSourcingMarketOf($this->morphiteLine));
    }

    public function testPilotOfAnotherCorporationAndPendingMemberAreRefused(): void
    {
        $this->requestAs($this->outsider, 'POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => 'jita']);
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->pendingMember, 'POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => 'jita']);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->storedSourcingMarketOf($this->morphiteLine));
    }

    public function testAnonymousVisitorIsRefused(): void
    {
        $this->request('POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => 'jita']);
        self::assertResponseStatusCodeSame(401);

        $this->request('DELETE', $this->sourcingMarketUri($this->morphiteLine));
        self::assertResponseStatusCodeSame(401);

        self::assertNull($this->storedSourcingMarketOf($this->morphiteLine));
    }

    public function testLineOfAnotherProjectUnknownLineUnknownProjectAndMalformedIdsAreNotFound(): void
    {
        $this->requestAs($this->owner, 'POST', $this->projectUri($this->ishtarBatch).'/bom/'.$this->idOf($this->hecateTritaniumLine).'/sourcing-market', ['market' => 'jita']);
        self::assertResponseStatusCodeSame(404);

        $this->requestAs($this->owner, 'POST', $this->projectUri($this->ishtarBatch).'/bom/'.Uuid::v7()->toRfc4122().'/sourcing-market', ['market' => 'jita']);
        self::assertResponseStatusCodeSame(404);

        $this->requestAs($this->owner, 'POST', $this->projectUri($this->ishtarBatch).'/bom/'.self::NOT_A_UUID.'/sourcing-market', ['market' => 'jita']);
        self::assertResponseStatusCodeSame(404);

        $this->requestAs($this->owner, 'POST', '/api/industry/projects/'.Uuid::v7()->toRfc4122().'/bom/'.$this->idOf($this->morphiteLine).'/sourcing-market', ['market' => 'jita']);
        self::assertResponseStatusCodeSame(404);

        self::assertNull($this->storedSourcingMarketOf($this->hecateTritaniumLine));
    }

    public function testAccessIsCheckedBeforeTheLineSoANonAdminMemberIsRefusedOnAForeignLineAndOnAJobLine(): void
    {
        $this->requestAs($this->member, 'POST', $this->projectUri($this->ishtarBatch).'/bom/'.$this->idOf($this->hecateTritaniumLine).'/sourcing-market', ['market' => 'jita']);
        self::assertResponseStatusCodeSame(403);

        $this->requestAs($this->member, 'POST', $this->sourcingMarketUri($this->ramJobLine), ['market' => 'jita']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testJobLineHasNoSourcingMarketAndIsRefusedWithABadRequest(): void
    {
        $this->requestAs($this->owner, 'POST', $this->sourcingMarketUri($this->ramJobLine), ['market' => 'structure']);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->storedSourcingMarketOf($this->ramJobLine));
    }

    public function testValueOtherThanJitaOrStructureIsRefusedWithABadRequestAndTheStoredChoiceStays(): void
    {
        $this->requestAs($this->owner, 'POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => 'structure']);
        self::assertResponseStatusCodeSame(200);

        foreach (['unknown', 'amarr', 'Jita', ' structure'] as $market) {
            $this->requestAs($this->owner, 'POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => $market]);
            self::assertResponseStatusCodeSame(400, 'market '.$market);
        }

        self::assertSame('structure', $this->storedSourcingMarketOf($this->morphiteLine));
    }

    public function testBodyWithoutMarketOrWithANullMarketIsUnprocessableAndNeverAServerError(): void
    {
        $this->requestAs($this->owner, 'POST', $this->sourcingMarketUri($this->morphiteLine), []);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['market'], $this->violatedPaths());

        $this->requestAs($this->owner, 'POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => null]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['market'], $this->violatedPaths());

        self::assertNull($this->storedSourcingMarketOf($this->morphiteLine));
    }

    public function testCompletedProjectRefusesTheChoiceAndItsRemovalAndKeepsTheStoredValue(): void
    {
        $this->requestAs($this->owner, 'POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => 'structure']);
        self::assertResponseStatusCodeSame(200);
        $this->ishtarBatch->setStatus(ProjectStatus::Completed);
        $this->em->flush();

        $this->requestAs($this->owner, 'POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => 'jita']);
        self::assertResponseStatusCodeSame(400);
        $this->requestAs($this->owner, 'DELETE', $this->sourcingMarketUri($this->morphiteLine));
        self::assertResponseStatusCodeSame(400);

        self::assertSame('structure', $this->storedSourcingMarketOf($this->morphiteLine));
    }

    public function testEveryAcceptedParticipantIsToldAfterTheChoiceAndAfterItsRemovalAndAPendingOneIsNot(): void
    {
        $expected = $this->expectedIndustryProjectEvents();

        $this->requestAs($this->owner, 'POST', $this->sourcingMarketUri($this->morphiteLine), ['market' => 'structure']);
        self::assertSame($expected, $this->publishedIndustryProjectEvents());

        $this->publishedUpdates = [];
        $this->requestAs($this->owner, 'DELETE', $this->sourcingMarketUri($this->morphiteLine));
        self::assertSame($expected, $this->publishedIndustryProjectEvents());
    }

    private function requestAs(User $user, string $method, string $uri, ?array $body = null): void
    {
        $this->actAs($user);
        $this->request($method, $uri, $body);
    }

    private function sourcingMarketUri(IndustryProjectBomLine $line): string
    {
        return $this->projectUri($this->ishtarBatch).'/bom/'.$this->idOf($line).'/sourcing-market';
    }

    private function storedSourcingMarketOf(IndustryProjectBomLine $line): ?string
    {
        $stored = $this->em->getConnection()->fetchOne('SELECT sourcing_market FROM industry_project_bom_lines WHERE id = ?', [$this->idOf($line)]);

        return false === $stored ? throw new \LogicException('The line is not stored.') : $stored;
    }

    private function publishedIndustryProjectEvents(): array
    {
        $events = array_map(
            static fn (Update $update): array => [$update->getTopics()[0], json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR)['data']],
            $this->publishedUpdates,
        );
        sort($events);

        return $events;
    }

    private function expectedIndustryProjectEvents(): array
    {
        $data = ['projectId' => $this->ishtarBatch->getId()->toRfc4122(), 'lineId' => $this->idOf($this->morphiteLine)];
        $events = array_map(
            fn (User $user): array => ['/user/'.$user->getId()->toRfc4122().'/sync/industry-project', $data],
            [$this->owner, $this->member],
        );
        sort($events);

        return $events;
    }
}
