# #1112 Project tabs: Sourcing for materials, Production for jobs

Circuit l, mode nuit, front-end only. Prerequisites checked on this branch (stacked on #1109 and #1111): `ChainNode.id` is the BOM line id (`ProjectChainBuilder::lineId`), so every job row of the chain maps to a `BomItem` of `projectStore.jobs`; `ProductionChain.vue`, `ProductionChainJobTree.vue`, `ProductionChainFlow.vue`, `ChainStatusFilter.vue`, `JobsTable.vue` exist. No backend change, no migration.

## Rules

1. Tab `bom` is the Sourcing tab (FR "Approvisionnement", EN "Sourcing"): it no longer renders `JobsTable`, nor `data-testid="project-jobs-section"`. Blacklist and invention toggles, container banner, courier, purchases to deliver, stock and BPC proposals, `BomTable`, `MaterialSourcing`, surplus stay. The helper text under the tab no longer says "Components & BPCs in Jobs below": it points to the Production tab.
2. Tab `chain` stays Production. Its job list is the existing job tree (view `tree`) plus the flow; no second job list exists anywhere.
3. Every action of `JobsTable` moves onto the job tree rows, keyed on `node.id` = BOM line id (`projectStore.jobs.find(line => line.id === node.id)`; a node without a line shows no action):
   - first cell: container check of the line (`ContainerLineStatus`), partly-covered remaining text (`job-container-remaining`), `UnclaimedStockBadge` (component lines), linked jobs (`job-match`: character, runs, status, locations with `JobLocationBadge`, `job-match-unlink` for the owner), `link-job-open` for the owner, `LinePurchases` (component lines);
   - runs cell: `matched-runs` text;
   - new last column `reservation`: `LineReservation`, whose `contribute` is re-emitted up to the detail view (`openContributeModal`).
4. The job link flow (`JobLinkModal`, available jobs, link, unlink, `job-link-error`) and Match jobs (owner, `chain-match-jobs`, with the failed characters list and the failed lines list `match-failed-line`) live in `ProductionChain.vue`, through a composable `useProjectJobActions` extracted from `JobsTable.vue` (same store calls: `matchJobs`, `fetchAvailableJobs`, `linkJob`, `unlinkJob`). After Match jobs the chain and slots are refetched as today.
5. The status of a job row is the chain status only (`ChainStatusFilter` counts replace the footer counters). The front-computed status of `JobsTable` (`delivered`, `active`, `covered`, `to_launch`, `bpc_contributed`) disappears with the component; `JobsTable.vue` is deleted and its container helpers (`containerLineOf`, partly-covered rule) move to `jobContainerCoverage.ts`.
6. The flow column "Invention and materials" shows the blueprint (invention and copy) nodes and ONE materials summary card instead of the material nodes. The card (`chain-materials-summary`, a button) reads `N / M material lines supplied`: M = material nodes, N = those with `fulfilledQuantity >= requiredQuantity` (effective quantity of #1109). Material nodes with a null `requiredQuantity` or `fulfilledQuantity` are counted in a separate `K unknown` text, never in N. Click opens the Sourcing tab. It ignores the status filter (the filter is for jobs) and is absent when the chain has no material node. No material node appears in the tree or the flow.
7. Links to the old jobs section open Production: the next action `launch_job` switches to `chain` (not `bom`) and scrolls to `data-testid="project-jobs-section"` wrapping the chain section on that tab; other actions keep `detailTabOfNextAction`. The companion `deliver` and `open-bom` navigate to `tab: chain`; `contribute` in the query still opens the modal. `source_material` keeps opening the contribute modal or the Sourcing tab.
8. FR and EN: tab label key `groupIndustry.tabs.materialsJobs` becomes `groupIndustry.tabs.sourcing`; `groupIndustry.jobs.title` and the footer keys are removed with `JobsTable`; add `groupIndustry.chain.flow.materialsSummary` (`{supplied} / {total} material lines supplied`), `materialsSummaryUnknown` (`{count} unknown`). `jobs.*` keys still used by the composable (`matchError`, `linkError`...) stay.
9. Help pages that name "Sourcing & jobs" or "Jobs to Launch" (`frontend/src/help/{en,fr}/playbooks/*.md`) are updated: materials under Sourcing, jobs and Match jobs under Production.

## Examples

| Material lines (required / fulfilled) | Card |
|---|---|
| 3 lines, 100/100, 50/100, 10/10 | `2 / 3 material lines supplied` |
| 2 lines, 100/100, 40/null | `1 / 2 material lines supplied`, `1 unknown` |
| 0 lines | no card |

## Edge cases

- A job node whose BOM line is not loaded yet (store `bomItems` empty): row without actions, no crash.
- Solo project: the `reservation` column header is empty, `LineReservation` renders as in `JobsTable`.
- Non-owner: no `link-job-open`, no unlink, no Match jobs, no modal; linked jobs list and reservation still visible.
- Filter hides every job: `chain-filter-empty` as today, the materials card stays.
- Estimated cost and the total of `JobsTable` have no equivalent on Production (real cost column exists); material estimates stay on `BomTable`.

## Out of scope

Backend, `ProjectChainBuilder` statuses, #1110 and #1108 (superseded), #1107 (companion order), the job tree columns other than the added `reservation`, any setting or route.

## Open decisions

Taken without Sylvain (night mode): the materials summary counts lines, not quantities (units of different items do not add); `JobsTable.vue` and its footer total estimated cost are removed as the issue states ("leaves this tab"); the estimated cost column is not reproduced.
