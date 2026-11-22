# #1246 Match jobs in one scored pass across all open projects of a user

Circuit L. Spec validated in autonomy (night mode). Code: `src/Service/Industry/IndustryJobMatcher.php`, `src/Service/Industry/Project/OpenProjectsJobMatcher.php`, `src/State/Processor/Industry/MatchJobsProcessor.php`, `src/Entity/IndustryProjectJobMatch.php`. Map: `docs/modules/industry.md` (rows `IndustryJobMatcher`, jobs of a project). Builds on #1245 (kept links: the pass only places jobs not linked yet).

## Problem

`OpenProjectsJobMatcher::matchOpenProjectsOf` calls `matchEsiJobs` project by project. A job fitting two projects is either left unlinked (`contestedJobIds`, #1124) or taken by the first project processed (`findOpenOfAcceptedParticipant` has no `orderBy`). The data that tells projects apart (job places, planned structure, producer characters, reservations) is ignored.

## Rules

1. One pass per call of `OpenProjectsJobMatcher::matchOpenProjectsOf($user)` and per `MatchJobsProcessor` run: the jobs not linked yet are matched against the job lines of all launched open projects of the user (owner or accepted participant, `findOpenOfAcceptedParticipant`) at once. Drafts keep releasing their automatic matches (#1245 rule 8). Completed projects are out.
2. Eligibility, unchanged from today, evaluated per (job, line): `isManufacturingOrReactionJob` line without manual match, job on the blueprint of the line's producer, job of a character of the project's owner or accepted participants, started since the project `createdAt`, job with output (`hasNoOutput` false), job not already linked (to any project), job `runs` not above the line remainder (`runs - getMatchedRuns()`, remainder at zero or below: line skipped, no failure). `noRuns` and `producerMissingFromSde` failures are unchanged. #1125 stays: a job whose blueprint location (`blueprintLocationId`) is in the known container scope of another project and not in this project's own scope is not eligible for this project.
3. Each eligible pair gets a set of signals (new pure domain `App\Industry\Domain\JobMatching`), strongest first:
   1. `intent`: the project has `producerCharacterIds` (not null) and it contains the job character's `eveCharacterId`; or the line has an `IndustryProjectLineReservation` whose participant is the user of the job's character;
   2. `place`: the project container scope is known (`ProjectContainerScope::isKnown`) and contains the job's `outputLocationId` or `blueprintLocationId`;
   3. `structure`: the line's `plannedStructureId` (the uuid of a structure configuration) resolves to an `IndustryStructureConfig` whose `locationId` equals the job's `stationId`; unknown uuid or null location gives no point;
   4. `exact_fit`: the job `runs` equal the line remainder.
4. Score order is lexicographic, strongest first: any pair holding `intent` beats any without; then `place`; then `structure`; then `exact_fit` (implement as weights 8, 4, 2, 1).
5. A signal whose data is unknown (producer ids null, no reservation, scope unknown or container not found, assets not synced, job location null, no planned structure) gives no point and never excludes the pair.
6. Assignment is greedy and deterministic: repeat (a) take the best pair among the eligible pairs of the jobs still free, with scores computed on the current remainders, (b) link it, reduce the remainder, free pairs that no longer fit; stop when no pair is left. Ties on score: oldest project (`createdAt`, then id) first, then the line with the lowest id (creation order of the lines), then the job with the lowest `getJobId()`. Same input, same output, whatever the database order.
7. A job linked in the pass is linked once (to one line); a line takes several jobs while the remainder allows (as today).
8. The match stores the signals that decided it: new nullable json column `IndustryProjectJobMatch::matchSignals` (list of `intent`, `place`, `structure`, `exact_fit`, in that order; empty list when the pair had no signal; `null` for manual matches and for matches created before this change). Exposed read-only as `matchSignals` (list or null) on `ProjectJobMatchResource`. Migration is reversible (`ADD`/`DROP COLUMN`), existing rows stay `null`.
9. `contestedJobIds` (#1124) is replaced by rule 6: a job fitting several projects now goes to the best pair instead of staying unlinked. `IndustryJobMatcher::contestedJobIds` and `containerOwnersByLocationId` are removed with their tests, the container knowledge moves into the pass. The "clearly wins" gate comes with #1247 (out of scope here).
10. API (`IndustryJobMatcher`): `matchEsiJobs(project, …)` is replaced by `matchOpenProjects(list<IndustryProject>)`: releases the matches without output (`releaseMatchesWithoutOutput`, per project), runs the pass, flushes once, returns the `LineMatchFailure` list per project id (`array<string, list<LineMatchFailure>>`, each also logged as warning). `OpenProjectsJobMatcher::matchOpenProjectsOf` calls it once for the launched projects (a failure of the pass is logged and does not stop `recordJobsOf` and `refreshLine`, still isolated per project). `MatchJobsProcessor` calls it with the user's open projects plus the target project and reports the failures of the target project only.
11. Candidate characters per line stay the characters of the project's owner and accepted participants (today's reach: the owner's run still matches participants' cached jobs). The "jobs of the user's characters" of the issue is the minimum, never a restriction.
12. No new async action, no Mercure topic change, no front change: the existing sync, "Match jobs" button and Mercure `industry-project` refresh are unchanged.

## Examples

| Situation | Result |
|---|---|
| Projects A (older) and B, same blueprint, job of 5 runs, no data distinguishing them | Job goes to A (tie: oldest project), `matchSignals` `[]` or `["exact_fit"]` as the remainder says |
| Same, but the job output sits in B's container, A's scope unknown | Job goes to B, signals `["place"]` |
| Same, but A and B both have the container and only B plans the job's station | Job goes to B, signals `["place", "structure"]` |
| Same, B has `producerCharacterIds = [job character]`, A has null | Job goes to B, `["intent"]` |
| Line reserved by the installer's user in A, container match in B | A wins (`intent` beats `place`) |
| Job blueprint in A's container, A's lines all full, B has a fitting line | Job stays unlinked (#1125 rule 2) |
| Two jobs 6 and 4 runs, A line 10 runs, B line 6 runs | Two jobs 6 and 4 runs, A line 10 runs, B line 6 runs, nothing else distinguishing | The 6-run job goes to B (`exact_fit`), then the 4-run job goes to A (B has no remainder left) |
| Container scope unknown for every project | Scores differ only by `intent`, `structure`, `exact_fit`; nothing excluded |
| Same call twice, database order reversed | Same links |
| Kept link from the previous sync | Kept (#1245), the pass places only free jobs and counts the kept runs |

## Cases and limits

- Reservation signal looks at participants of the line's own project only; the participant's user must be the user of the job's character.
- `plannedStructureId` that is not a uuid, or a configuration without `locationId`: no `structure` point.
- No eligible pair for a job: unlinked, listed later by `AvailableJobsProvider` as today.
- Performance: one query per distinct (blueprint, project character set) is acceptable; no per-job query.

## Domain tests expected (pure, no database)

Two projects on the same blueprint separated only by the container, only by the planned structure, only by the producer characters, only by the reservation; tie-break by project age then line order then job id; unknown data gives no point; exact fit; deterministic under permuted input; greedy remainder handling with several jobs.

## Out of scope

Ambiguity gate (#1247), unassigned jobs list (#1248), dedicated character (#1249), shared job (#1250), copy and invention jobs (#1193; the planner is activity-agnostic so they plug in later), UI that shows the signals, the removal of manual-link behaviors.

## Open decisions (taken)

- Job fitting several projects now links to the best pair instead of staying unlinked: required by the issue's tie-break, gated later by #1247. To review at the end.
- #1125 kept as an eligibility filter, not a score: removing it would let a job leave the project whose container holds its blueprint.
- Signals stored as json on the match (one nullable column) rather than a table: read as a unit, never queried.
