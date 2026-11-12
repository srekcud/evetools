# 787 — Catch up after a Mercure reconnection

Issue #787, circuit:m, front only. The hub keeps no history (`transport local`, #611), so events published while the EventSource was down are lost.

## Wiring decision

Front-side signal, no new endpoint. Recommended default of the brief: every need of the issue (stuck sync, stale views) is covered by "refetch what the view already fetches on an event"; a sync-runs endpoint would only add state the views cannot use, since none of them reads a run list.

## Rules

1. `useSyncStore` exposes `reconnectedAt: number | null` (epoch ms), `null` until a reconnection happens.
2. A reconnection is an `onopen` that follows a drop: an `onerror` happened since the previous `onopen` (browser auto-retry, `readyState` CONNECTING) or a store-driven retry after `CLOSED`. The very first `onopen` after login is not a reconnection. Logout resets the flag and `reconnectedAt`.
3. On reconnection, every `syncStatus` entry in `started` or `in_progress` becomes `unknown` (same entry, `status: 'unknown'`, `progress: null`, message kept), so `isLoading`, `isAnyLoading` and the `*Progress` consumers stop reporting a running sync. Entries in `completed`, `error`, `idle` are untouched. No entry is invented for a sync type that has none.
4. `SyncStatus` gains `'unknown'`. It never raises a success or error notification in the activity feed.
5. Then `reconnectedAt` is set to the current time, once per reconnection, after the entries are marked.
6. `useRefetchOnReconnect(refetch)` (composable) runs `refetch` each time `reconnectedAt` changes to a non-null value. It never runs on mount.
7. Consumers that refetch on a Mercure event refetch on reconnection too:
   - stores: notifications (page 1 and unread count), industry actions, workshop projects, group-industry companion (project, bom, cockpit alerts);
   - views: GroupIndustryDetail (bom, project detail, cockpit), MiningOperationDetail (`load()`), MiningOperationList (`loadLists()`);
   - group-industry panels: ProjectResearch, ProjectBuyOrBuild, ProjectReactionSteps, MaterialSourcing, ProjectStructures, ProductionChain, ProjectCourier, ProjectPlanning (same fetch as their event watcher).
8. Two reconnections in a row each trigger one refetch.

## Cases

| Situation | Result |
|---|---|
| first `onopen` after login | `reconnectedAt` stays `null`, nothing refetched |
| `onerror` then `onopen` | `reconnectedAt` set, running syncs → `unknown` |
| `onerror` CLOSED, `attemptReconnect`, `onopen` | same as above |
| `character-assets` `completed` at reconnection | unchanged |
| `corporation-assets` `in_progress` at reconnection | `unknown`, `isLoading('corporation-assets')` false |
| logout then login | first `onopen` is not a reconnection |

## Out of scope

Mercure history on the hub, Caddyfile, docker-compose, `ERR_NETWORK_CHANGED` itself, an endpoint of current sync runs, panels of other modules (Mining site companion, PI, Admin) that only react to completion events they re-request on mount.

## Open decisions

- `unknown` is shown nowhere specially: views simply stop spinning. A "state lost, refresh" hint is a product decision, left out.
