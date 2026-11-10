<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Escalation;

use App\Entity\Character;
use App\Entity\Escalation;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Enum\EscalationSaleStatus;
use App\Enum\EscalationVisibility;
use App\Enum\PveIncomeType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class EscalationIncomeApiTestCase extends WebTestCase
{
    protected const int CORPORATION_ID = 98_533_001;

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected User $pilot;
    protected User $otherMember;
    protected \DateTimeImmutable $saleDate;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('logger', new NullLogger());
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->saleDate = new \DateTimeImmutable('-3 days');

        $this->pilot = $this->createUser(2_112_533_001, 'Ilse Varn');
        $this->otherMember = $this->createUser(2_112_533_002, 'Doran Kesh');
    }

    protected function createUser(int $eveCharacterId, string $name): User
    {
        $character = (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(self::CORPORATION_ID)
            ->setCorporationName('Varn Salvage Collective');
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);
        $this->em->persist($user);
        $this->em->persist($character);
        $this->em->flush();

        return $user;
    }

    protected function escalationOf(User $owner, EscalationSaleStatus $saleStatus, int $price, EscalationVisibility $visibility = EscalationVisibility::Perso): Escalation
    {
        $escalation = (new Escalation())
            ->setUser($owner)
            ->setCharacterId(2_112_533_001)
            ->setCharacterName('Ilse Varn')
            ->setType('Blood Raider Hideaway')
            ->setSolarSystemId(30_002_813)
            ->setSolarSystemName('Aldranette')
            ->setSecurityStatus(-0.4)
            ->setPrice($price)
            ->setVisibility($visibility)
            ->setCorporationId(self::CORPORATION_ID)
            ->setExpiresAt(new \DateTimeImmutable('+3 days'));
        if ($saleStatus === EscalationSaleStatus::Vendu) {
            $escalation->setSaleStatus(EscalationSaleStatus::Vendu);
            $escalation->setSoldAt($this->saleDate);
        }
        $this->em->persist($escalation);
        $this->em->flush();

        return $escalation;
    }

    protected function incomeOf(User $owner, PveIncomeType $type, float $amount, \DateTimeImmutable $date, string $description, ?Escalation $escalation = null): PveIncome
    {
        $income = (new PveIncome())
            ->setUser($owner)
            ->setType($type)
            ->setDescription($description)
            ->setAmount($amount)
            ->setDate($date)
            ->setEscalation($escalation);
        $this->em->persist($income);
        $this->em->flush();

        return $income;
    }

    protected function soldEscalationIncome(User $owner, float $amount, \DateTimeImmutable $date): PveIncome
    {
        $escalation = $this->escalationOf($owner, EscalationSaleStatus::Vendu, (int) $amount);

        return $this->incomeOf($owner, PveIncomeType::Escalation, $amount, $date, 'Blood Raider Hideaway - Aldranette', $escalation);
    }

    protected function getJson(User $reader, string $uri): array
    {
        $this->client->loginUser($reader, 'api');
        $this->client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    protected function escalationIncomesOf(User $owner): array
    {
        $this->em->clear();

        return $this->em->getRepository(PveIncome::class)->findBy(['user' => $owner->getId(), 'type' => PveIncomeType::Escalation], ['date' => 'ASC']);
    }
}
