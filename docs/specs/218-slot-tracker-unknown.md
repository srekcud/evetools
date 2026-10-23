# #218 — Slot Tracker: unknown skills and stale jobs are reported, not replaced by plausible values

## Context

`SlotTrackerService::getSlotStatus()` computes max slots as `1 + skill levels`, with a missing skill counted as level 0. Skills are only synced on demand, so a character whose skills were never synced gets 1/1/1 max slots and free-slot suggestions built on that guess. Jobs are read from the cache without looking at the character's authorization or the cache age, so a character with an expired token shows its last known jobs as current. `skillsMayBeStale` only lights up when global used slots exceed global max.

## Decisions (Sylvain, 2026-10-23)

- Skills are unknown for a character when it has **no cached skill at all**. A synced character without a slot skill has level 0 for it (ESI only returns trained skills).
- Global KPIs sum the characters with known skills only, and report how many characters have unknown skills.
- A character's jobs are stale when its authorization is invalid, or when the most recent `cachedAt` of its active jobs is older than 1 hour (two 30-minute sync cycles).

## Rules

1. Character entry, skills unknown: `slots.<category>.max` is `null` for the three categories, `used` is still the count from the jobs; `freeSlots` is empty (no suggestion is built on an unknown max). New entry field `skillsUnknown: true` (false otherwise).
2. Character entry, skills known: unchanged computation (`1 + level + advanced level`, absent slot skill = 0).
3. Character entry `jobsStale: true` when `Character::isAuthValid()` is false, or when it has at least one active job and the newest `cachedAt` among them is more than 1 hour before now. A character with no active job and a valid authorization is not stale.
4. `globalKpis.<category>.used` and `.max` sum only the characters with known skills; `percent` is computed on those sums. New top-level field `unknownSkillsCharacterCount` (int).
5. `skillsMayBeStale` is true when `unknownSkillsCharacterCount > 0`, or (existing rule) when known used exceeds known max in a category.
6. Frontend (`IndustrySlotsSection` and the slot types in `frontend/src/stores/industry/types.ts`): an unknown max is shown as unknown (`UnknownValue`, `—` with the unknown-value tooltip), never as a number; the global KPI shows the partial mention with the count of characters with unknown skills; a character with `jobsStale` shows a stale-data badge.

## Examples

| Situation | Expected |
|---|---|
| Character A, no cached skill, 1 active manufacturing job | A: manufacturing `{used: 1, max: null}`, `skillsUnknown: true`, `freeSlots: []` |
| Character B, cached skills Mass Production 4, Advanced Mass Production 2, other skills present, no reaction skill | B: manufacturing max 7, reaction max 1, `skillsUnknown: false` |
| A + B together, B has 3 manufacturing jobs | global manufacturing `{used: 3, max: 7, percent: 42.9}`, `unknownSkillsCharacterCount: 1`, `skillsMayBeStale: true` |
| B only, 3 jobs, max 7 | `unknownSkillsCharacterCount: 0`, `skillsMayBeStale: false` |
| Character C, authorization invalid, jobs cached 5 min ago | C: `jobsStale: true` |
| Character D, valid, newest active job `cachedAt` 61 min ago | D: `jobsStale: true` |
| Character E, valid, newest active job `cachedAt` 59 min ago (another 3 h old) | E: `jobsStale: false` |
| Character F, valid, no active job | F: `jobsStale: false` |
| Frontend, character max `null` | `—` with unknown-value tooltip instead of a number |
| Frontend, `unknownSkillsCharacterCount: 1` | partial mention on the global KPI with count 1 |
| Frontend, `jobsStale: true` | stale-data badge on the character card |

## Out of scope

- Triggering a skill sync automatically.
- Stale detection for a valid character without active jobs.

## Glossary

- **Compétences inconnues** / Unknown skills: a character with no cached skill; its max slots are unknown and excluded from the global KPIs.
