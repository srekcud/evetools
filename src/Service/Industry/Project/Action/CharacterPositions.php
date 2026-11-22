<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

use App\Entity\User;
use App\Service\ESI\CharacterLocation;
use App\Service\ESI\CharacterLocationReader;

final readonly class CharacterPositions
{
    public function __construct(private CharacterLocationReader $locationReader)
    {
    }

    /**
     * @return list<CharacterLocation>
     */
    public function of(User $user): array
    {
        $positions = [];
        foreach ($user->getCharacters() as $character) {
            $position = $this->locationReader->read($character);
            if (null !== $position) {
                $positions[] = $position;
            }
        }

        return $positions;
    }
}
