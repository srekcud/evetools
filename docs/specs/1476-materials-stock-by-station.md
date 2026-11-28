# Spec #1476: Materials to source says where the stock is, by station

Circuit S raised to M (7 production files, new field on an API resource). Mode nuit.

## Decision (Sylvain, 2026-11-28)

The #801 rule stays: containers carrying the project name add up across stations, the status and `containerQuantity` do not change. The line says where the stock is, per station, and warns when it is not at the station where the job runs.

## Rules

1. `GET /industry/projects/{projectId}/container-verification`: each line gains `stockStations`, `list<{locationId: ?int, stationName: ?string, quantity: int}>|null`.
   - `null` when the line status carries no reading (unknown, container not found, hidden division, stale assets): same cases as `containerQuantity === null`.
   - Otherwise one entry per station holding the line type in the named containers (sub-containers included, each asset once), quantity above zero, ordered by station name then location id, the entry without station last. A container whose station cannot be resolved gives `{locationId: null, stationName: null}`. Nothing held: `[]`.
   - The station of a named container is the one `AssetStations::locationOf` gives its root asset, the same reading as the flow grid.
2. Front, `ContainerLineStations.vue` under the container status of each "Materials to source" line, from `stockStations` and the `station-quantities` entry of the type (needed minus consumed per station, remaining above zero = a station where a job runs):
   - warning (`container-line-stations-warning`) listing each resolved job station whose remaining need exceeds what is held there, only when stock exists elsewhere and no held entry has an unresolved station;
   - stock list (`container-line-stations`) shown unless all the stock sits at one resolved station and every job station of the type is that station;
   - nothing when `stockStations` is `null` or `[]`.
3. Labels in `fr.json` and `en.json` under `groupIndustry.container`.

## Out of scope

- Changing the sum of #801, `containerStockOf` (#866) and the other readers of the named containers.
- Splitting a BOM line per consuming station.
