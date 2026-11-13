# #812 — Price alerts on a followed structure market

Decision (Sylvain, 2026-11-13): option B, one PR. The structure sources (`structure_sell`, `structure_buy`) already exist and read the user's preferred structure at check time. This spec stores the chosen structure on the alert.

## Rules

1. `market_price_alerts.structure_id` BIGINT nullable. `MarketPriceAlert::getStructureId()/setStructureId()`. Always null for `jita_sell` and `jita_buy`.
2. Creation (`CreateAlertProcessor`, `CreateAlertInput::$structureId`, optional `?int`):
   - structure source and no `structureId` given: use `User::getPreferredMarketStructureId()`; if the user has none, 400 with the existing message.
   - `structureId` given with a structure source: it must be the preferred structure or one of `User::getMarketStructures()` ids, otherwise 400 (`The structure must be your preferred or a followed market structure`).
   - Jita source with a `structureId`: 400 (`A structure can only be set on a structure price source`).
3. Check (`MarketAlertService::checkAlerts`) and display (`MarketAlertPriceResolver`): a structure alert reads the price of ITS `structure_id`, never the owner's current preferred structure. Alerts are batched per distinct structure id (one `getLowestSellPrices`/`getHighestBuyPrices` call per structure, with the type ids of the alerts on it).
4. An alert with a structure source and a null `structure_id`, or a structure with no cached price for the type: current price `null` (unknown), no trigger, no re-arm. No fallback to Jita or to the preferred structure, no `?? 0`.
5. Two alerts of the same user on the same type and the same direction on two different structures are independent: each triggers on its own structure price.
6. `MarketAlertResource` exposes `structureId` (`?int`) and `structureName` (`?string`: the name found in the owner's preferred or followed structures, `null` when the id is in neither). `MarketAlertResourceMapper::toResource` takes the name as an argument, or resolves it from the alert's user.
7. Editing (PATCH) never changes the structure (the price source is read-only already).
8. Type detail (`MarketTypeDetailProvider`): new `structurePrices`, a list of `{structureId, structureName, sell, buy}` (`?float` prices, null when unknown) for the preferred structure then the followed ones, deduplicated by id, from the cached prices only (`StructureMarketService`). The existing `structureSell`, `structureBuy`, `structureName`, `hasPreferredStructure` stay unchanged.
9. Migration (new `Version…`, after `Version20261112170000`): add the column, then `UPDATE market_price_alerts SET structure_id = users.preferred_market_structure_id FROM users WHERE users.id = market_price_alerts.user_id AND price_source IN ('structure_sell','structure_buy')`. `down()` drops the column.
10. Front (`AlertFormModal`, `MarketTypeDetail`, `AlertsPanel`, `stores/market.ts`, i18n en+fr):
    - modal in creation mode: with a structure source selected and `structurePrices.length > 1`, a structure `<select>` (default: the preferred, the first entry); with one entry, no selector, the structure name is shown as today.
    - the source options are built from the selected structure name (`<system> Sell` / `<system> Buy`); the "current price" line shows sell and buy of the SELECTED structure from `structurePrices`, `formatIsk(null)` rendering for unknown.
    - the submit payload carries `structureId` for a structure source only.
    - `AlertsPanel` and the edit modal label a structure alert with its own `structureName` (fallback `Structure` when null), not the preferred one.
    - `MarketAlert` gains `structureId`, `structureName`; `CreateAlertInput` gains `structureId?`.

## Examples

| Alert | Prices | Result |
|---|---|---|
| structure_sell below 100, structure 11 | S11 sell 90, Jita sell 120 | triggers at 90 |
| structure_sell below 100, structure 11 | S11 sell 120, Jita sell 90 | no trigger |
| structure_sell below 100, structure 11 | no S11 price | current price null, no trigger |
| structure_buy above 50, structure null | S(preferred) buy 60 | null, no trigger |
| two alerts same type, S11 and S22 | S11 80, S22 130, threshold 100 below | only the S11 alert triggers |
| preferred changes from 11 to 22 after creation | alert created on 11 | still reads 11 |

## Edge cases

- Creation with `structureId` equal to the preferred structure while `marketStructures` is empty: accepted.
- A structure removed from the followed list after creation: the alert keeps working (price cache read by id); `structureName` is null.
- Triggered alerts are re-armed against their own structure price.

## Out of scope

- No new ESI call, no new sync, no change to the market-structure sync.
- No change of the structure on an existing alert (delete and recreate).
- No fallback to `app.default_market_structure_id` (the user's preferred structure is the default; instances without one keep the 400).
- Jita sources unchanged.

## Production actions

- Run the migration at deploy (backfills existing structure alerts with their owner's preferred structure). No console command, no fixture.

## Open decisions

- `MarketAlertPriceResolver::currentPrice(User, alert)` keeps its `User` parameter to avoid churn on four callers; it no longer drives the structure.
