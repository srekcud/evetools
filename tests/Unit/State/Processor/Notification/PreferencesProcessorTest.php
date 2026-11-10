<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Notification;

use ApiPlatform\Metadata\Put;
use App\ApiResource\Input\Notification\PreferencesInput;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserNotificationPreference;
use App\Repository\UserNotificationPreferenceRepository;
use App\State\Processor\Notification\PreferencesProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[CoversClass(PreferencesProcessor::class)]
#[Group('issue-537')]
final class PreferencesProcessorTest extends TestCase
{
    public function testPlanetaryThresholdOfFifteenMinutesIsAccepted(): void
    {
        $preference = $this->savePlanetaryThreshold(15);

        $this->assertSame(15, $preference->getThresholdMinutes());
    }

    public function testPlanetaryThresholdOfSevenDaysIsAccepted(): void
    {
        $preference = $this->savePlanetaryThreshold(10080);

        $this->assertSame(10080, $preference->getThresholdMinutes());
    }

    public function testPlanetaryThresholdBelowFifteenMinutesIsABadRequestAndNothingIsSaved(): void
    {
        $preference = (new UserNotificationPreference())->setCategory(Notification::CATEGORY_PLANETARY)->setThresholdMinutes(1440);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->process([['category' => 'planetary', 'thresholdMinutes' => 14]], $preference, $entityManager);
            $this->fail('A planetary threshold of 14 minutes must be rejected');
        } catch (BadRequestHttpException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1440, $preference->getThresholdMinutes());
    }

    public function testPlanetaryThresholdAboveSevenDaysIsABadRequestAndNothingIsSaved(): void
    {
        $preference = (new UserNotificationPreference())->setCategory(Notification::CATEGORY_PLANETARY)->setThresholdMinutes(1440);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->process([['category' => 'planetary', 'thresholdMinutes' => 10081]], $preference, $entityManager);
            $this->fail('A planetary threshold of 10081 minutes must be rejected');
        } catch (BadRequestHttpException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1440, $preference->getThresholdMinutes());
    }

    public function testPlanetaryThresholdThatIsNotAnIntegerIsABadRequest(): void
    {
        foreach (['abc', 60.5] as $notAnInteger) {
            try {
                $this->process([['category' => 'planetary', 'thresholdMinutes' => $notAnInteger]], null, $this->createStub(EntityManagerInterface::class));
                $this->fail('A non integer planetary threshold must be rejected');
            } catch (BadRequestHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPlanetaryThresholdCanBeClearedToFallBackOnTheDefault(): void
    {
        $preference = (new UserNotificationPreference())->setCategory(Notification::CATEGORY_PLANETARY)->setThresholdMinutes(360);

        $this->process([['category' => 'planetary', 'thresholdMinutes' => null]], $preference, $this->createStub(EntityManagerInterface::class));

        $this->assertNull($preference->getThresholdMinutes());
    }

    public function testInvalidPlanetaryThresholdRejectsTheWholeBatchBeforeAnyOtherPreferenceIsSaved(): void
    {
        $price = (new UserNotificationPreference())->setCategory(Notification::CATEGORY_PRICE)->setEnabled(true);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        try {
            $this->process(
                [['category' => 'price', 'enabled' => false], ['category' => 'planetary', 'thresholdMinutes' => 5]],
                null,
                $entityManager,
                $price,
            );
            $this->fail('The batch holding an invalid planetary threshold must be rejected');
        } catch (BadRequestHttpException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue($price->isEnabled());
    }

    public function testEscalationThresholdKeepsItsOwnUnboundedValue(): void
    {
        $preference = (new UserNotificationPreference())->setCategory(Notification::CATEGORY_ESCALATION)->setThresholdMinutes(60);

        $this->process([['category' => 'escalation', 'thresholdMinutes' => 5]], $preference, $this->createStub(EntityManagerInterface::class));

        $this->assertSame(5, $preference->getThresholdMinutes());
    }

    private function savePlanetaryThreshold(int $thresholdMinutes): UserNotificationPreference
    {
        $preference = (new UserNotificationPreference())->setCategory(Notification::CATEGORY_PLANETARY)->setThresholdMinutes(1440);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $this->process([['category' => 'planetary', 'thresholdMinutes' => $thresholdMinutes]], $preference, $entityManager);

        return $preference;
    }

    private function process(array $preferences, ?UserNotificationPreference $existing, EntityManagerInterface $entityManager, ?UserNotificationPreference $existingPrice = null): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $repository = $this->createStub(UserNotificationPreferenceRepository::class);
        $repository->method('findByUserAndCategory')->willReturnCallback(
            static fn (User $user, string $category): ?UserNotificationPreference => match (true) {
                $existing !== null && $existing->getCategory() === $category => $existing,
                $existingPrice !== null && $existingPrice->getCategory() === $category => $existingPrice,
                default => null,
            },
        );
        $repository->method('getOrCreateAll')->willReturn(array_values(array_filter([$existing, $existingPrice])));

        $input = new PreferencesInput();
        $input->preferences = $preferences;

        (new PreferencesProcessor($security, $repository, $entityManager))->process($input, new Put());
    }
}
