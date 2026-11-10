<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-341')]
final class ProjectStructurePolicySwitchApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsIshtarBlueprintsAndTheProjectProduct;
    use SeedsJitaOrderBooksAndCostIndices;

    private const float MINIMUM_SAVING = 250_000.0;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->seedIshtarBlueprintsAndTheProjectProduct();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->seedNoJitaSellOrderFor(self::RAM_STARSHIP_TECH_TYPE_ID, self::TRITANIUM_TYPE_ID);
        $this->em->remove($this->memberMorphiteContribution);
        $this->em->flush();
    }

    public function testOwnerAllowsTheOutsideOfTheFavoriteSystemOnAProjectInProgressAndTheOptionIsSavedAndExposed(): void
    {
        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch), ['acceptsOutsideFavoriteSystem' => true, 'minimumOutsideSaving' => self::MINIMUM_SAVING]);

        self::assertResponseStatusCodeSame(200);
        $json = $this->responseJson();
        self::assertSame([true, self::MINIMUM_SAVING], [$json['acceptsOutsideFavoriteSystem'], $json['minimumOutsideSaving']]);
        $this->em->refresh($this->ishtarBatch);
        self::assertSame([true, self::MINIMUM_SAVING], [$this->ishtarBatch->isAcceptsOutsideFavoriteSystem(), $this->ishtarBatch->getMinimumOutsideSaving()]);
    }

    public function testParticipantWhoIsNotAnAdminCannotChangeThePolicyAndItIsUnchanged(): void
    {
        $this->actAs($this->member);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch), ['acceptsOutsideFavoriteSystem' => true]);

        self::assertResponseStatusCodeSame(403);
        $this->em->refresh($this->ishtarBatch);
        self::assertFalse($this->ishtarBatch->isAcceptsOutsideFavoriteSystem());
    }

    public function testNegativeMinimumSavingIsRejectedAndTheOptionIsUnchanged(): void
    {
        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch), ['acceptsOutsideFavoriteSystem' => true, 'minimumOutsideSaving' => -1]);

        self::assertResponseStatusCodeSame(422);
        $this->em->refresh($this->ishtarBatch);
        self::assertFalse($this->ishtarBatch->isAcceptsOutsideFavoriteSystem());
    }
}
