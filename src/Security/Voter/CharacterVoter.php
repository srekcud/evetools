<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Character;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Character>
 */
class CharacterVoter extends Voter
{
    public const VIEW = 'CHARACTER_VIEW';
    public const EDIT = 'CHARACTER_EDIT';
    public const DELETE = 'CHARACTER_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof Character;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Character $character */
        $character = $subject;

        // User must own the character
        if ($character->getUser() !== $user) {
            return false;
        }

        return match ($attribute) {
            self::VIEW, self::EDIT => true,
            self::DELETE => !$character->isMain(), // Cannot delete main character
            default => false,
        };
    }
}
