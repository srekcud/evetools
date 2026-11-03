<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\User;
use App\Service\MiningOperation\MiningOperationNotifier;
use App\Service\Notification\NotificationDispatcher;

trait RecordsMiningOperationNotifications
{
    private \ArrayObject $dispatchedNotifications;

    private function notifierRecording(): MiningOperationNotifier
    {
        $this->dispatchedNotifications = new \ArrayObject();
        $dispatcher = $this->createStub(NotificationDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            function (User $user, string $category, string $level, string $title, string $message, ?array $data = null, ?string $route = null): void {
                $this->dispatchedNotifications->append([
                    'recipient' => $this->recipientNameOf($user),
                    'category' => $category,
                    'level' => $level,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'route' => $route,
                ]);
            },
        );

        return new MiningOperationNotifier($dispatcher);
    }

    private function recipientNameOf(User $user): string
    {
        return $user->getMainCharacter()?->getName() ?? $user->getCharacters()->first()->getName();
    }

    private function notificationsTitled(string $title): array
    {
        $matching = array_values(array_filter(
            $this->dispatchedNotifications->getArrayCopy(),
            static fn (array $notification): bool => $notification['title'] === $title,
        ));
        usort($matching, static fn (array $left, array $right): int => $left['recipient'] <=> $right['recipient']);

        return $matching;
    }

    private function recipientsTitled(string $title): array
    {
        return array_column($this->notificationsTitled($title), 'recipient');
    }

    private function notificationTitled(string $title, string $recipient): array
    {
        foreach ($this->notificationsTitled($title) as $notification) {
            if ($notification['recipient'] === $recipient) {
                return $notification;
            }
        }

        throw new \LogicException(sprintf('%s received no "%s" notification', $recipient, $title));
    }

    private function digitsOf(string $message): string
    {
        return preg_replace('/[^0-9]/u', '', $message);
    }

    private function operationRouteOf(MiningOperation $operation): string
    {
        return '/mining-operations/' . $operation->getId()->toRfc4122();
    }
}
