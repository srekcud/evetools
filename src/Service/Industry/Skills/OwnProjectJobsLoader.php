<?php

declare(strict_types=1);

namespace App\Service\Industry\Skills;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectParticipantRepository;

class OwnProjectJobsLoader
{
    public function __construct(
        private readonly IndustryProjectParticipantRepository $participantRepository,
        private readonly CachedIndustryJobRepository $cachedJobRepository,
    ) {
    }

    public function loadFor(User $user, Character $character, \DateTimeImmutable $from, \DateTimeImmutable $to): OwnProjectJobs
    {
        $esiJobIds = [];
        foreach ($this->participantRepository->findAcceptedOnProjectsCompletedBetween($user, $from, $to) as $membership) {
            foreach ($membership->getProject()->getBomItems() as $bomLine) {
                foreach ($bomLine->getJobMatches() as $match) {
                    if ($match->getCharacterName() === $character->getName()) {
                        $esiJobIds[$match->getEsiJobId()] = $match->getEsiJobId();
                    }
                }
            }
        }

        $esiJobIds = array_values($esiJobIds);
        $cachedJobs = $this->cachedJobRepository->findByJobIds($esiJobIds);

        $jobs = [];
        $complete = true;
        foreach ($esiJobIds as $esiJobId) {
            $job = $cachedJobs[$esiJobId] ?? null;
            if ($job instanceof CachedIndustryJob) {
                $jobs[] = $job;
            } else {
                $complete = false;
            }
        }

        return new OwnProjectJobs($jobs, $complete);
    }
}
