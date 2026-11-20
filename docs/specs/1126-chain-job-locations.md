# #1126 — Chain tab: job input and output locations

Issue: srekcud/evetools#1126 (circuit:m, group:jobs-sync). Related: #319 (locations resolver), #1125 (job of another project).

## Problem

`ProductionChainJobTree.vue` renders the « Input → output » cell as a hard-coded unknown for every node with an activity, and `ProductionChain.vue` always shows the static note `ioUnknownCause`. `ProjectChainBuilder` exposes no location although `ProjectJobLocationResolver::locationsOf()` already resolves `blueprint` (input) and `output` of each linked job against the project container scope.

## Rules

1. **API**: each job of a chain node (`ProjectChainNode::$jobs`, `GET /industry/projects/{projectId}/chain`) gains a `locations` key, `{blueprint, output}` with the resolver side shape (`locationId`, `kind`, `name`, `divisionName`, `status` inside|outside|unknown, `unknownReason`), or `null`.
2. **Visibility**: `locations` is filled only for the project owner and admins (`ProjectAccessChecker::isAdminOrOwner`, same rule as `ProjectBomProvider`); any other accepted member gets `null` on every job, and the resolver is not called for them.
3. **Gone job**: `locations` is `null` when the resolver has no entry for the job (cached job purged).
4. The resolver is fed with the job matches of all BOM lines of the project, one call per request (no call per node).
5. `locations` is added in `ProjectChainProvider` (after the installer-name masking), not in `ProjectChainBuilder`; `ProjectChainNode` docblocks list the new key; `ChainJob` front type gets `locations?: JobLocations | null`.
6. **Front, job tree, « Input → output » cell**: one entry per job of the node (`data-testid="chain-job-io"`), in the order of `node.jobs`:
   - input name then output name (`chain-job-input`, `chain-job-output`), each carrying `data-status` (inside|outside|unknown); a side with a null `name` shows `groupIndustry.jobLocations.unknownLocation`;
   - a side `inside` shows the tag `groupIndustry.jobLocations.projectContainer` (« Project container ») next to its name;
   - the existing `JobLocationBadge` (`chain-job-check`) stays as the output check;
   - input `outside` (container or hangar, `jobInputOutside()` in `jobLocationCheck.ts`) adds an amber flag `chain-job-input-outside` with `groupIndustry.jobLocations.inputOutside` (« Input outside the project container: the job probably belongs to another project »);
   - each distinct `unknownReason` of an `unknown` side is shown as text (`chain-job-io-reason`, `groupIndustry.jobLocations.reasons.<reason>`), never the static note.
7. A job with `locations` null, and a node without job, show nothing in the cell (no unknown placeholder). The cell no longer contains `chain-io-unknown`.
8. The static note `chain-io-unknown-cause` and the i18n key `groupIndustry.chain.ioUnknownCause` (en + fr) are removed: they were the bug, not a setting. `jobLocationCheck()` is unchanged (output-only, #319 revision of 2026-11-10).
9. i18n en + fr for `projectContainer` and `inputOutside` under `groupIndustry.jobLocations`.
10. Flow view unchanged.

## Examples

| Job | Input | Output | Cell |
|---|---|---|---|
| ISHTAR launched | `ISHTAR-319` inside | `ISHTAR-319` inside | « ISHTAR-319 Project container → ISHTAR-319 Project container », badge ok |
| job of another project | `Perso` outside | `Perso` outside | both names, amber input flag, badge output-outside |
| input in a hangar | station hangar `Jita 4-4` outside | project container inside | input flag shown (hangar counts as outside) |
| assets not synced | unknown, reason `assets_not_synced` | unknown, same reason | `Unknown location` x2, one reason text (deduplicated) |
| member who is not admin | `locations` null | | nothing |

## Edge cases

- Two jobs on a node: two entries, each its own flag.
- Input unknown, output outside: output badge `output-outside`, no input flag.
- `locations` absent (older payload fixtures): same as null.

## Out of scope

Changing `jobLocationCheck`, the BOM job-match locations, the planning views, a project-membership detection of the job (#1125), the flow view.

## Open decisions

None. Production action: none (no migration, no command).
