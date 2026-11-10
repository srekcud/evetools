<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Escalation;

use App\Entity\Escalation;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-555')]
final class EscalationCreatePriceBoundsApiTest extends EscalationIncomeApiTestCase
{
    public function testCreatingAnEscalationAbovePriceInteger4StoresThePrice(): void
    {
        $this->postEscalation(2_147_483_648);

        self::assertResponseStatusCodeSame(201);
        $escalationId = $this->responseBody()['id'];
        $this->em->clear();
        $stored = $this->em->find(Escalation::class, $escalationId);
        self::assertSame(2_147_483_648, $stored?->getPrice());
    }

    public function testCreatingAnEscalationAboveTheMaximumPriceIsRejectedAndCreatesNothing(): void
    {
        $this->postEscalation(1_000_000_000_000_001);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storedEscalations());
    }

    public function testCreatingAnEscalationAtPriceZeroIsAccepted(): void
    {
        $this->postEscalation(0);

        self::assertResponseStatusCodeSame(201);
        $escalationId = $this->responseBody()['id'];
        $this->em->clear();
        $stored = $this->em->find(Escalation::class, $escalationId);
        self::assertSame(0, $stored?->getPrice());
    }

    private function postEscalation(int $price): void
    {
        $this->client->loginUser($this->pilot, 'api');
        $this->client->request(
            'POST',
            '/api/escalations',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode([
                'characterId' => 2_112_533_001,
                'type' => 'Blood Raider Hideaway',
                'solarSystemId' => 30_002_813,
                'solarSystemName' => 'Aldranette',
                'securityStatus' => -0.4,
                'price' => $price,
                'visibility' => 'perso',
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function responseBody(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function storedEscalations(): array
    {
        $this->em->clear();

        return $this->em->getRepository(Escalation::class)->findAll();
    }
}
