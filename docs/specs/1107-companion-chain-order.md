# #1107 Companion: craft lines ordered like the Production tab

## Context
In the project companion, « To build » (section « To assign ») and « My lines to deliver > To build » list the craft (job) lines in BOM order. The final product can take a visible slot (`FREE_LINES_SHOWN`) while launchable components sit behind « more ». The Production tab already has the right order: `ProjectChainBuilder` sorts the chain nodes by stage (blueprint, material, component, product), then, inside the component stage, by status (`COMPONENT_STATUS_ORDER`: todo, missing_materials, unknown, active, blocked, done), level, name. A chain node id is the BOM line id.

## Rules
1. Order source (decision): the companion reads `GET /industry/projects/{id}/chain` (existing endpoint, same access as the BOM). No backend change, no new field on the BOM line. The order rule stays in `ProjectChainBuilder`; the front takes the position of a line in the returned `nodes` array as its rank and never re-sorts by status, level or name. Issue #1108 (Jobs to Launch) can read the same rank from the chain store.
2. `useGroupCompanionStore` loads the chain on `open` and on every `handleEvent` (same moments as the BOM), keeps `chainNodes: ChainNode[] | null` (`null` until loaded and after a failed load; a failure sets `error` like the other loads).
3. `buildCompanionTasks` takes `chainNodes: ChainNode[] | null`. When not null, the job lines of `free` and of `mine` are put in chain rank order. Non-job (material) lines keep their BOM positions and order (job lines fill the job slots in rank order).
4. A job line with no node in a loaded chain goes after the ranked job lines, in BOM order (transient state between two loads), never first.
5. `CompanionTasks` gets `waitingForComponents: string[]`: the ids of the BOM job lines whose chain node status is `blocked`. Empty when `chainNodes` is `null`.
6. `GroupCompanion.vue` shows a « Waiting for components » tag (`data-testid="companion-waiting"`) on a job line of `free` and `mine` whose id is in `waitingForComponents`. No tag for `unknown`, `missing_materials`, `todo`, `active`, `done`: an `unknown` line is never shown as launchable (no « launchable » tag exists).
7. `FREE_LINES_SHOWN` is unchanged: the first six build lines are therefore the first six of the chain order.

## Examples (will be the red tests)
Chain order of the nodes: Capacitor Console (todo), Armor Plate (missing_materials), Sensor Cluster (unknown), Hull (active), Ishtar (blocked, product), BOM order: Ishtar, Hull, Sensor Cluster, Armor Plate, Capacitor Console.

| Input | Output |
|---|---|
| `free` build lines, chain loaded | Capacitor Console, Armor Plate, Sensor Cluster, Hull, Ishtar |
| `free` with Tritanium (material) between two jobs in BOM order | Tritanium keeps its BOM slot, jobs reordered around it |
| `mine` build lines, chain loaded | same chain order |
| `chainNodes` null | BOM order, `waitingForComponents` empty |
| Ishtar node `blocked`, others not | `waitingForComponents` = [Ishtar id] |
| A job line absent from the chain nodes | after every ranked job line |
| 8 build lines, chain order, 6 shown | the 2 hidden are the 2 last in chain order |
| Companion with a blocked Ishtar and todo components | the tag is on Ishtar only; no tag on the todo components |

## Edge cases
- Chain load fails: `error` set, ordering falls back to the BOM order, no tag (the error message is the signal).
- A refresh (Mercure `industry-project`, reconnect) reloads project, BOM, cockpit and chain together.

## Existing tests changed
- `frontend/tests/stores/groupCompanion.spec.ts`: the default API mock answers the chain path with `{ id, nodes: [] }`, call counts of other endpoints unchanged.
- `companionTasks.spec.ts`, `groupCompanion*.spec.ts`: the helpers pass `chainNodes: null` (or nodes) to `buildCompanionTasks`; no existing assertion changes.

## Out of scope
- Order of the « To buy » lines, order of the « My jobs (ESI) » list, the Jobs to Launch table (#1108), any backend or API Platform change, the Production tab itself.

## Decisions (night, autonomous)
- Chain endpoint rather than a rank/status field on the BOM line: avoids computing the chain (plan, material supply) on every BOM read, and the chain endpoint is already the source of the Production tab. Cost: one more GET in the companion, refreshed with the BOM.
- Tag only `blocked` (« Waiting for components »), as the issue asks.
- Glossary: no new term (« Compagnon de projet » definition extended with the chain order).
