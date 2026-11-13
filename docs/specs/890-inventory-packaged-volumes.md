# #890 — Inventory: packaged volume and courier contract

Issue #890 (circuit:l), decision of 2026-11-13: a table of our own holds the packaged volume per type, a weekly scheduled task refreshes it, the inventory never calls ESI to compute a volume, station containers are excluded from totals, the UI labels the figure "packaged volume". Night mode, spec written by the thread.

## Rules

1. **Table** `type_packaged_volumes` (`TypePackagedVolume`: `typeId` PK, `packagedVolume` float m³, `refreshedAt`). One row per type, upserted, never deleted. A type without a row has an **unknown** volume; there is no default value.
2. **Source**: ESI `GET /universe/types/{type_id}/` (public, no token), read through `EsiClient::getBatch`, 20 types per batch. Stored value = `packaged_volume`; when ESI omits it the type has no repackaged form and moves at `volume` (decision 1). When both are absent the type is counted as failed and not stored.
3. **Types refreshed** = distinct `type_id` of `cached_assets` (characters and corporations), minus the station container types. A type first seen after the weekly run is unknown until the next run or an admin trigger.
4. **Abort**: 3 consecutive batches with no usable answer stop the run (same rule as `JitaMarketService::syncRegionalDailyVolumes`); the run is then recorded as failed.
5. **Task**: message `SyncPackagedVolumes`, handler tracked by `SyncTracker` under `packaged-volumes` (global sync, so the existing `admin-sync` Mercure topic covers it, no new topic). Scheduled every 7 days (`EXPECTED_INTERVALS` 604800), admin-triggerable through `TriggerableSyncs`, label in the admin sync list (en, fr), row in the `CLAUDE.md` scheduler table. The summary reads `N types synced; M failed`.
6. **Station containers**: `EveConstants::STATION_CONTAINER_TYPE_IDS` = 17366 (Station Container), 17367 (Station Vault Container), 17368 (Station Warehouse Container), all in group 448 (checked in the SDE). They are excluded from every total; their contents are counted (items that can be taken out), decision 2.
7. **API**: `GET /api/me/assets/packaged-volumes` (`ROLE_USER`), one resource `{ id: 'packaged-volumes', volumes: {typeId: m³}, excludedTypeIds: [..], refreshedAt: ISO|null }`. `refreshedAt` is the newest `refreshedAt` of the table, `null` when the table is empty. Volumes are public SDE/ESI data, no scoping beyond the authenticated user.
8. **Volume of a set of assets** (pure front util `summarizeVolume`): for each asset, `quantity × packagedVolume` of its type; recurse into its contents (container contents, ship cargo and fit) once per item id; a station container adds nothing for itself. Result `{ cubicMeters, unknownTypeCount }`; assets of a type without a volume add nothing and count once per distinct type in `unknownTypeCount`. Never `?? 0`: unknown stays visible.
9. **Display** (label "Packaged volume", `m³`, thousands separators, unknown types shown as "N types without volume" in amber):
   - location header: volume of the location (its assets and their contents);
   - container row: volume of the container and its contents;
   - ship group row (`AssetShipGroups`): volume of the group's ships with their fit and cargo;
   - selection bar: volume of the selection (same assets as the multibuy lines).
10. **Sort**: the location sort gains `volume` (descending, name as tie-break), next to `items` and `name`.
11. **Courier contract**: the selection bar gets a "Courier contract" button, enabled when the selection volume is above zero. It opens a panel (`AssetCourierContractPanel`) with the origin locations, the packaged volume and the unknown-type warning, and a "Copy" button that copies the text. ESI has no endpoint to open the create-contract window (decision 3), and the inventory shows no ISK value (decision of 2026-11-13), so collateral and reward are not prefilled.

## Examples (front util, packaged volumes: Rifter 2 500, Tritanium 0.01, Station Warehouse Container excluded)

| Assets | cubicMeters | unknownTypeCount |
|---|---|---|
| 3 Rifter | 7 500 | 0 |
| 1 000 Tritanium | 10 | 0 |
| Station Warehouse Container holding 100 Tritanium | 1 | 0 |
| Cargo container (volume 100) holding 2 Rifter | 5 100 | 0 |
| 5 Rifter, 2 of an unlisted type | 12 500 | 1 |
| Container listed twice in the contents map | counted once | 0 |

Sync: batch answer `{packaged_volume: 2500, volume: 27289}` stores 2500; `{volume: 0.01}` stores 0.01; `{}` is failed; 17368 present in assets is never requested.

## Edge cases

- No cached asset at all: no ESI call, run recorded complete with `0 types synced; 0 failed`.
- Table empty: endpoint answers `volumes: []` and `refreshedAt: null`; the inventory shows no volume and one notice "Packaged volumes not loaded yet" instead of zeros.
- Volume endpoint fails: the inventory still loads, volumes absent, notice shown.
- An ESI answer with a negative or non-numeric volume counts as failed.

## Out of scope

Collateral, reward, route or any ISK figure; creating the contract in game; per-corporation volumes on the server (the front aggregates what it already loads, `#878` lot 2 will move totals server side if needed); volume in the By type view; a fit-aware repackaging rule for ships.

## Decisions (autonomous, night mode)

1. A type with no `packaged_volume` moves at its `volume` (ESI omits the field for types without a repackaged form).
2. Station container contents are counted, only the container itself is excluded.
3. "Create a courier contract" = prefilled text to copy, because ESI cannot open a create-contract window.

## Production actions

Migration `type_packaged_volumes` at deploy; then trigger `packaged-volumes` once from the admin sync page (otherwise volumes stay unknown until the first weekly run).
