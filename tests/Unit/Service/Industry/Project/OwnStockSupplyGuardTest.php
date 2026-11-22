<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\OwnStockSupplyGuard;
use App\Tests\Unit\Support\BuildsOwnStockSupplyGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OwnStockSupplyGuard::class)]
#[Group('issue-1318')]
final class OwnStockSupplyGuardTest extends TestCase
{
    use BuildsChainBomLines;
    use BuildsOwnStockSupplyGuard;

    private const int FINAL_PRODUCT = 16_673;
    private const int PHOTONIC_METAMATERIALS = 1_153_002;
    private const int PHOTONIC_METAMATERIALS_IN_HANGAR = 90_000;

    private User $owner;
    private User $member;
    private IndustryProject $project;
    private IndustryProjectBomLine $photonic;
    private IndustryProjectBomLine $finalProduct;

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->member = new User();
        $this->project = (new IndustryProject())->setOwner($this->owner)->setStatus(ProjectStatus::InProgress);
        $this->project->addMember((new IndustryProjectParticipant())->setUser($this->member)->setRole(ParticipantRole::Member)->setStatus(ParticipantStatus::Accepted));
        $this->finalProduct = $this->jobLine(self::FINAL_PRODUCT, 'Fernite Carbide', 'final', null, 10)->setFulfilledQuantity(0);
        $this->photonic = $this->jobLine(self::PHOTONIC_METAMATERIALS, 'Photonic Metamaterials', 'component', self::FINAL_PRODUCT, 10)
            ->setRequiredQuantity(300_000)
            ->setFulfilledQuantity(0);
        $this->project->addBomItem($this->finalProduct);
        $this->project->addBomItem($this->photonic);
    }

    public function testSupplyUpToTheOwnStockOfTheContributorIsAccepted(): void
    {
        $guard = $this->guardOverTheStockOfTheMember();

        $failure = $this->failureOf(fn () => $guard->assertWithin($this->photonic, $this->member, self::PHOTONIC_METAMATERIALS_IN_HANGAR));

        self::assertNull($failure);
    }

    public function testSupplyAboveTheOwnStockOfTheContributorIsRefusedWithTheAvailableQuantity(): void
    {
        $guard = $this->guardOverTheStockOfTheMember();

        $failure = $this->failureOf(fn () => $guard->assertWithin($this->photonic, $this->member, self::PHOTONIC_METAMATERIALS_IN_HANGAR + 1));

        self::assertInstanceOf(\DomainException::class, $failure);
        self::assertStringContainsString('exceeds your own stock', $failure->getMessage());
        self::assertStringContainsString('(90000)', $failure->getMessage());
    }

    public function testSupplyIsRefusedOnceTheProjectIsSelling(): void
    {
        $this->project->setStatus(ProjectStatus::Selling);
        $guard = $this->guardOverTheStockOfTheMember();

        $failure = $this->failureOf(fn () => $guard->assertWithin($this->photonic, $this->member, 1));

        self::assertInstanceOf(\DomainException::class, $failure);
        self::assertStringContainsString('selling', $failure->getMessage());
    }

    #[DataProvider('refusedWhateverTheQuantity')]
    public function testSupplyIsRefusedWhenTheLineIsNotListedOrTheStockOfTheContributorIsUnknown(bool $personalAssetsSynced, string $line): void
    {
        $guard = $this->ownStockSupplyGuardOver(
            fn (User $user): array => $user === $this->member ? [self::PHOTONIC_METAMATERIALS => self::PHOTONIC_METAMATERIALS_IN_HANGAR, self::FINAL_PRODUCT => 5] : [],
            $personalAssetsSynced,
        );
        $lineToSupply = 'final' === $line ? $this->finalProduct : $this->photonic;

        $failure = $this->failureOf(fn () => $guard->assertWithin($lineToSupply, $this->member, 1));

        self::assertInstanceOf(\DomainException::class, $failure);
    }

    public static function refusedWhateverTheQuantity(): iterable
    {
        yield 'final product line is never listed' => [true, 'final'];
        yield 'personal assets never synchronised' => [false, 'component'];
    }

    private function guardOverTheStockOfTheMember(): OwnStockSupplyGuard
    {
        return $this->ownStockSupplyGuardOver(
            fn (User $user): array => $user === $this->member ? [self::PHOTONIC_METAMATERIALS => self::PHOTONIC_METAMATERIALS_IN_HANGAR] : [self::PHOTONIC_METAMATERIALS => 500_000],
        );
    }

    private function failureOf(callable $action): ?\Throwable
    {
        try {
            $action();
        } catch (\Throwable $failure) {
            return $failure;
        }

        return null;
    }
}
