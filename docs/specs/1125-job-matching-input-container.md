# #1125 A job is never matched to a project whose container is not the one it takes its input from

Circuit m, mode nuit. Stacked on #1124 (PR #1129). Rule from the clarification of Sylvain (2026-11-20) on the issue: the job input location decides.

Prerequisites checked on the #1124 branch: `IndustryJobMatcher::matchEsiJobs`, `contestedJobIds`, `ProjectContainerService::containerScopeOf` (item ids of the named containers and of everything stored in them), `CachedIndustryJob::getBlueprintLocationId()`.

## Rules

1. The input of a job is `CachedIndustryJob::$blueprintLocationId` (where the job takes its blueprint and materials). The output location is not read.
2. A project "owns" a location when its container scope is known and its `containerItemIds` hold the location. Draft projects (#1123) and projects with an unknown scope own nothing.
3. A job whose input location is owned by at least one candidate project is only a candidate for the projects that own it. For every other project the job is excluded, as if it were held by another project, whatever its runs and its output location.
4. A job whose input location is owned by no project (hangar, a container that is not a project container, location missing, assets not synced) is neutral: the rules of #1124 apply unchanged, nothing is guessed.
5. Two projects sharing the same container both own the location: both stay candidates and the unique-candidate rule of #1124 decides (contested if both fit).
6. `IndustryJobMatcher::containerOwnersByLocationId(list<IndustryProject>)` returns `array<int, list<string>>`: location item id => project ids (RFC 4122) owning it, built once from `containerScopeOf` of each non-draft project.
7. `matchEsiJobs(IndustryProject, array $contestedJobIds = [], array $containerOwnersByLocationId = [])` and `contestedJobIds(array $projects, array $containerOwnersByLocationId = [])` skip, for a project, the jobs of rule 3. The exclusion is applied when the candidate jobs of a line are read, so the fixed point of #1124 sees it too. Empty map = today's behaviour.
8. `OpenProjectsJobMatcher::matchOpenProjectsOf` (after the release step) and `MatchJobsProcessor` compute the map once over the same project set as `contestedJobIds` and pass it to both calls.

## Examples

Formula Ishtar, jobs started after both `created_at`, same owner, line runs 5.

| Case | Result |
|---|---|
| A container C1, B container C2, J input in C1 | J to A only, whatever the order; B gets nothing |
| Same, J output in C2 and input in C1 | J to A (output ignored) |
| J input in C1 (A), output outside every container | J to A (#506 kept) |
| J input in a hangar, A and B both fit | contested (#1124), linked nowhere |
| J input unresolved (`null`) | neutral: single fitting project gets it |
| A and B share C1, J input in C1, both fit | contested |
| B is a draft with container C2, J input in C2 | C2 owned by nobody: neutral for A |
| A with unknown scope, J input in C1 owned by B | J to B only |

## Edge cases

- A job fitting the line of a project owning its input but not fitting its remaining runs stays unlinked there and is not offered to a project of another container.
- The manual link (`linkJobToBomLine`) is not filtered: the owner can still link a job by hand.

## Out of scope

- Reading the output location, or the front display of the resolved locations (#319).
- Changing `ProjectJobLocationResolver` or `containerScopeOf`.
