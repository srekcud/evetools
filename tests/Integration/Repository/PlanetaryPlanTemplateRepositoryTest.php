<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\PlanetaryPlanTemplate;
use App\Entity\User;
use App\Repository\PlanetaryPlanTemplateRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

#[Group('issue-1405')]
final class PlanetaryPlanTemplateRepositoryTest extends IntegrationTestCase
{
    private const int COOLANT = 9_832;
    private const int ROBOTICS = 9_848;
    private const int PLASMOIDS = 2_389;
    private const string UNKNOWN_ID = 'f3a6c0d2-84e1-4b79-a5d6-0e2b9c7f1a48';

    private PlanetaryPlanTemplateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(PlanetaryPlanTemplateRepository::class);
    }

    public function testItListsTheTemplatesOfTheUserFromTheMostRecentToTheOldestAndNeverThoseOfAnotherUser(): void
    {
        $alice = $this->createUser();
        $bob = $this->createUser();
        $this->persistTemplate($alice, 'Coolant line', '2026-11-20T09:00:00+00:00');
        $this->persistTemplate($alice, 'Robotics', '2026-11-22T18:30:00+00:00');
        $this->persistTemplate($bob, 'Bob newest', '2026-11-25T08:00:00+00:00');
        $this->persistTemplate($alice, 'Broadcast chain', '2026-11-21T12:15:00+00:00');
        $this->flushAndClear();

        $names = array_map(static fn (PlanetaryPlanTemplate $template): string => $template->getName(), $this->repository->findByUser($this->reload($alice)));

        $this->assertSame(['Robotics', 'Broadcast chain', 'Coolant line'], $names);
    }

    public function testAUserWithoutTemplateHasAnEmptyList(): void
    {
        $alice = $this->createUser();
        $bob = $this->createUser();
        $this->persistTemplate($bob, 'Bob only', '2026-11-25T08:00:00+00:00');
        $this->flushAndClear();

        $this->assertSame([], $this->repository->findByUser($this->reload($alice)));
    }

    public function testItCountsTheTemplatesOfEachUserSeparately(): void
    {
        $alice = $this->createUser();
        $bob = $this->createUser();
        $carol = $this->createUser();
        foreach (['2026-11-20T09:00:00+00:00', '2026-11-21T09:00:00+00:00', '2026-11-22T09:00:00+00:00'] as $position => $createdAt) {
            $this->persistTemplate($alice, sprintf('Alice %d', $position), $createdAt);
        }
        $this->persistTemplate($bob, 'Bob', '2026-11-23T09:00:00+00:00');
        $this->flushAndClear();

        $this->assertSame(3, $this->repository->countByUser($this->reload($alice)));
        $this->assertSame(1, $this->repository->countByUser($this->reload($bob)));
        $this->assertSame(0, $this->repository->countByUser($this->reload($carol)));
    }

    public function testItFindsATemplateByItsIdForItsOwnerOnly(): void
    {
        $alice = $this->createUser();
        $bob = $this->createUser();
        $own = $this->persistTemplate($alice, 'Coolant line', '2026-11-20T09:00:00+00:00');
        $other = $this->persistTemplate($bob, 'Bob template', '2026-11-21T09:00:00+00:00');
        $this->flushAndClear();
        $ownId = $own->getId();
        $otherId = $other->getId();
        $this->assertInstanceOf(Uuid::class, $ownId);
        $this->assertInstanceOf(Uuid::class, $otherId);

        $found = $this->repository->findOneByUserAndId($this->reload($alice), $ownId);

        $this->assertInstanceOf(PlanetaryPlanTemplate::class, $found);
        $this->assertSame('Coolant line', $found->getName());
        $this->assertNull($this->repository->findOneByUserAndId($this->reload($alice), $otherId));
        $this->assertNull($this->repository->findOneByUserAndId($this->reload($alice), Uuid::fromString(self::UNKNOWN_ID)));
    }

    public function testTheProductionsChoicesAndSettingsComeBackAsStored(): void
    {
        $alice = $this->createUser();
        $template = $this->persistTemplate($alice, 'Mixed plan', '2026-11-20T09:00:00+00:00')
            ->setProductions([['typeId' => self::COOLANT, 'targetPerDay' => 120], ['typeId' => self::ROBOTICS, 'targetPerDay' => 36.5]])
            ->setChoices([self::PLASMOIDS => 'buy', self::ROBOTICS => 'produce'])
            ->setSettings(['priceSource' => 'jita-buy', 'taxPercent' => 7.5, 'daysBetweenRounds' => 0.5]);
        $this->flushAndClear();
        $id = $template->getId();
        $this->assertInstanceOf(Uuid::class, $id);

        $found = $this->repository->findOneByUserAndId($this->reload($alice), $id);

        $this->assertInstanceOf(PlanetaryPlanTemplate::class, $found);
        $this->assertSame([['typeId' => self::COOLANT, 'targetPerDay' => 120], ['typeId' => self::ROBOTICS, 'targetPerDay' => 36.5]], $found->getProductions());
        $this->assertSame([self::PLASMOIDS => 'buy', self::ROBOTICS => 'produce'], $found->getChoices());
        $this->assertSame(['priceSource' => 'jita-buy', 'taxPercent' => 7.5, 'daysBetweenRounds' => 0.5], $found->getSettings());
        $this->assertSame('2026-11-20T09:00:00+00:00', $found->getCreatedAt()->format('c'));
    }

    private function persistTemplate(User $user, string $name, string $createdAt): PlanetaryPlanTemplate
    {
        $template = (new PlanetaryPlanTemplate())->setUser($user)->setName($name)->setProductions([['typeId' => self::COOLANT, 'targetPerDay' => 120]]);
        (new \ReflectionProperty(PlanetaryPlanTemplate::class, 'createdAt'))->setValue($template, new \DateTimeImmutable($createdAt));
        $this->em->persist($template);

        return $template;
    }

    private function reload(User $user): User
    {
        $reloaded = $this->em->find(User::class, $user->getId());
        $this->assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }
}
