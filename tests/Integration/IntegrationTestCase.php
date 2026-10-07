<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class for tests hitting the real PostgreSQL test database (eve_app_test, see `make test-db`).
 * Each test runs inside a transaction rolled back by dama/doctrine-test-bundle.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    private static int $nextEveCharacterId = 90_000_000;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(): User
    {
        $user = new User();
        $this->em->persist($user);

        return $user;
    }

    protected function createCharacter(?User $user = null, string $name = 'Test Pilot'): Character
    {
        $character = (new Character())
            ->setEveCharacterId(self::$nextEveCharacterId++)
            ->setName($name)
            ->setCorporationId(98_000_001)
            ->setCorporationName('Test Corp');

        ($user ?? $this->createUser())->addCharacter($character);
        $this->em->persist($character);

        return $character;
    }

    /** Writes pending changes and clears the identity map so reads really hit the database. */
    protected function flushAndClear(): void
    {
        $this->em->flush();
        $this->em->clear();
    }
}
