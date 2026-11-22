# #1245 Stable automatic job matches across syncs

Circuit M. Spec validated in autonomy (night mode). Code: `src/Service/Industry/IndustryJobMatcher.php`, `src/Service/Industry/Project/OpenProjectsJobMatcher.php`, `src/Entity/IndustryProjectJobMatch.php`. Map: `docs/modules/industry.md` (rows `IndustryJobMatcher`, jobs of a project).

## Problem

`OpenProjectsJobMatcher::matchOpenProjectsOf` releases every automatic match of every launched project (`releaseAutomaticMatches`), then `matchEsiJobs` clears them again and recomputes. With several open projects on the same blueprint, or a line whose remainder changed, a linked job can move to another line or project between two syncs, and `DerivedContributionService::refreshLine` and `ProjectJobActivityRecorder` follow the moves.

## Rules

1. An automatic job match (`manual = false`), once created, is kept by later syncs and by "Match jobs". The matcher only adds matches for jobs not linked yet.
2. An automatic match is removed only in these cases: its job ended without output (match `status` is `cancelled` or `reverted`, same list as `CachedIndustryJob::STATUSES_WITHOUT_OUTPUT`, new `IndustryProjectJobMatch::hasNoOutput`); the user unlinks it (`UnlinkJobProcessor`, unchanged); the line is removed (existing cascade, unchanged).
3. The status read is the one the sync copies onto the match (`copyJobState`, runs before the matching in `syncUserJobs`). A match whose cached job disappeared is kept.
4. Manual matches and lines with a manual match keep their current behavior (skipped by the automatic pass, their job ids stay unavailable to other lines and projects).
5. The remaining runs of a line, for adding new matches, are `runs - getMatchedRuns()` (kept matches count). Remaining runs at zero or below: no new match on that line, and no failure reported. `noRuns` and `producerMissingFromSde` failures are unchanged.
6. The job ids already matched to the project's own lines (kept automatic matches included) are unavailable for new matches of that project, so a kept job is never matched twice. A job kept by another project stays unavailable to this one (`findEsiJobIdsMatchedToOtherProjects`, unchanged), and is therefore never in `contestedJobIds`.
7. `contestedJobIds` is computed on the same basis (remaining runs, kept job ids unavailable), so only jobs not linked yet can be contested.
8. Draft projects: unchanged, `releaseAutomaticMatches` still frees everything they hold (a draft must not hold jobs another project could use).
10. API: new public `IndustryJobMatcher::releaseMatchesWithoutOutput(IndustryProject)` removes the automatic matches without output (lines without manual match only) and flushes. `OpenProjectsJobMatcher::matchOpenProjectsOf` calls it for launched projects where it called `releaseAutomaticMatches` (same position: before `containerOwnersByLocationId` and `contestedJobIds`, per project failure isolation unchanged). `matchEsiJobs` no longer clears, and calls `releaseMatchesWithoutOutput` itself first (so `MatchJobsProcessor` benefits). `IndustryProjectJobMatch::hasNoOutput` is new.
9. A kept match whose job no longer fits (line runs lowered, character no longer a participant, new `createdAt`) stays; the user unlinks it.

## Examples

| Situation | Sync 1 | Sync 2 | Result |
|---|---|---|---|
| Two open projects A and B, same blueprint, one job of 5 runs, each project has a line of 10 runs | job goes to A (first fit) | A and B both unchanged | job stays on A's line, B has none |
| Line of 10 runs, kept job of 4 runs, a second job of 7 runs appears | 4 matched | 7 > remaining 6, not added | only the 4-run job stays; a 6-run job would be added |
| Job linked to A, then cancelled in ESI | linked | sync copies `cancelled` onto the match | match removed, the job is not re-linked (no output) |
| Job linked to A, A's line runs lowered below the job's runs | linked | unchanged | match kept, no new match on that line |
| Draft project holding an automatic match | n/a | sync | released as today |
| Project with a manual match on a line | n/a | sync | line skipped as today |

## Out of scope

Scored single pass across projects (#1246), ambiguity rule (#1247), unassigned jobs queue (#1248), dedicated character (#1249), shared job (#1250), copy and invention matching (#1193). No migration, no endpoint, no front change.

## Open decisions

None. Drafts keep releasing their matches (rule 8) because the issue does not mention them and holding jobs in a draft would block launched projects.
