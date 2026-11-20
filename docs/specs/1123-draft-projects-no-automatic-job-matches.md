# #1123 Draft projects take ESI jobs from the project that launched them

Circuit m, mode nuit. Prerequisites checked on origin/main: `OpenProjectsJobMatcher::matchOpenProjectsOf`, `IndustryProjectRepository::findOpenOfAcceptedParticipant`, `IndustryJobMatcher::matchEsiJobs`, `IndustryJobMatcher::clearAutomaticMatches`, `IndustryProjectJobMatchRepository::findEsiJobIdsMatchedToOtherProjects`, `ProjectStatus::Draft`.

## Rules

1. The automatic matching after a jobs sync (`OpenProjectsJobMatcher::matchOpenProjectsOf`) does not call `matchEsiJobs` for a project in status `Draft`. Projects `Published`, `InProgress` and `Selling` are matched as before; `Completed` stays excluded.
2. A draft that holds automatic matches (`manual = false`) loses them at that same step, so `findEsiJobIdsMatchedToOtherProjects` stops hiding the jobs from the project that launched them. Matching then runs on the other projects of the user in the same pass, and the freed jobs are linked there.
3. Releasing is done by a new public `IndustryJobMatcher::releaseAutomaticMatches(IndustryProject)`: for each job line of the project without a manual match, every match is removed (same lines as the recompute in `matchEsiJobs`); a line holding a manual match keeps all its matches; one flush at the end.
4. Order inside `matchOpenProjectsOf`: draft projects are released first, then the other projects are matched, so a job freed from a draft is available to them in the same run.
5. After releasing a draft, its job lines are refreshed with `DerivedContributionService::refreshLine` (derived contributions of the removed matches disappear). `ProjectJobActivityRecorder::recordJobsOf` is not called for a draft.
6. A failure on one project is logged (`Failed to match jobs of project`) and the others are still processed, as today.
7. A manual match (`manual = true`) on a draft is never touched.

## Examples

| Projects of the user (creation order) | Held matches before | After one sync |
|---|---|---|
| Nomad 18 (draft, older), Launch (in progress) | Nomad 18 holds jobs 680408323 and 680408523 (automatic) | Nomad 18 holds none; Launch is matched to them |
| Draft with one manual match on a line | manual job 1 | manual job 1 kept |
| Draft, line A automatic job 2, line B manual job 3 | A: 2, B: 3 | A: none (line A has no manual match), B: 3 |
| Draft alone, no matches | none | none, `matchEsiJobs` never called |

## Edge cases

- The manual `Match jobs` action on a draft (`MatchJobsProcessor`, owner only) is unchanged: it still calls `matchEsiJobs` on that project. Its automatic rows are released by the next automatic sync. Open question for Sylvain (not decided tonight): refuse or keep the manual action on a draft.
- `PinnedContainerCatalog` keeps using `findOpenOfAcceptedParticipant` (draft containers stay pinned): the repository method is unchanged.
- Publishing a draft makes it eligible for the next sync; no data to restore.

## Out of scope

- The oversized fallback stealing an exact match (#1124), the project container as match input (#1125).
- No migration, no console command: the release runs inside the sync (30 min) for every user who takes part in a draft.
