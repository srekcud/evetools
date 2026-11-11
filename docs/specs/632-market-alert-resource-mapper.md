# #632 — One mapping for MarketAlertResource, a current price on GET and PATCH

Circuit m, night mode (spec validated autonomously, no 🚦).

## Context

`MarketAlertResource` is built field by field in four classes (`CreateAlertProcessor`, `UpdateAlertProcessor`, `MarketAlertCollectionProvider`, `MarketAlertDeleteProvider`). `MarketAlertDeleteProvider` also serves `GET /api/market/alerts/{id}` and `PATCH`, and neither it nor `UpdateAlertProcessor` sets `currentPrice`: both answer `currentPrice: null` while the collection and `POST` answer the live price. The price-source rules (`AlertPriceSource` to Jita sell, Jita buy, structure sell, structure buy) live twice (collection provider in batch, create processor per type).

## Rules

1. `App\State\Provider\Market\MarketAlertResourceMapper::toResource(MarketPriceAlert $alert, ?float $currentPrice): MarketAlertResource` is the only place that builds a `MarketAlertResource`. It exposes: `id` (RFC 4122, `''` while unpersisted), `typeId`, `typeName`, `direction`, `threshold`, `priceSource`, `status`, `currentPrice` (as given), `triggeredAt` (ISO `c`, null when null), `triggeredPrice`, `createdAt` (`c`). Same naming as the existing `*ResourceMapper` classes (decision: the repository convention wins over the `Factory` name suggested by the issue).
2. `App\Service\MarketAlertPriceResolver` holds the price-source rules in one place:
   - `currentPrices(User $user, array $alerts): array` returns a list of `?float`, same order as `$alerts`, loading prices in batch (one Jita sell call and one Jita buy call for the distinct type ids, structure calls only when at least one alert uses a structure source);
   - `currentPrice(User $user, MarketPriceAlert $alert): ?float` is the single-alert form (`currentPrices` on one alert).
   - Jita sources use `JitaMarketService::getPricesWithFallback` / `getBuyPricesWithFallback`; structure sources use `StructureMarketService::getLowestSellPrices` / `getHighestBuyPrices` on the user's preferred market structure.
   - A missing price is `null` (unknown, never a default). A structure source with no preferred structure on the user is `null` (the collection already does this; creation still rejects it before).
3. `GET /api/market/alerts/{id}` and `PATCH /api/market/alerts/{id}` return the same `currentPrice` as the collection, through the resolver and the mapper.
4. `MarketAlertDeleteProvider` is renamed `MarketAlertItemProvider` (it backs Get, Patch and Delete); `MarketAlertResource` points to the new name. Ownership and not-found rules are unchanged (404 for unknown or malformed id, 403 for another user's alert).
5. `CreateAlertProcessor`, `UpdateAlertProcessor`, `MarketAlertCollectionProvider` and `MarketAlertItemProvider` call the mapper and the resolver; none of them builds the resource itself, and none keeps its own copy of the price-source `match`.
6. Frontend: `useMarketStore().updateAlert` replaces the alert with the PATCH response as is (the response now carries `currentPrice`); the workaround that kept the previous `currentPrice` goes away.

## Examples (become the red tests)

| Case | Input | Expected |
|---|---|---|
| Mapper, active alert | Pyerite (35), `below`, 8.5, `jita_buy`, price 8.75 | every field mapped, `currentPrice` 8.75, `triggeredAt` and `triggeredPrice` null |
| Mapper, triggered alert | same, triggered at 7.25 | `status` `triggered`, `triggeredPrice` 7.25, `triggeredAt` = `->format('c')` of the entity |
| Mapper, unknown price | price `null` | `currentPrice` null |
| Resolver, four sources | one alert per source, Jita sell 12, Jita buy 9, structure sell 11, structure buy 8 | `[12.0, 9.0, 11.0, 8.0]` in input order |
| Resolver, structure without preferred structure | `structure_sell` alert, user without preferred structure | `[null]`, structure service never called |
| Resolver, price missing | Jita service has no entry for the type | `[null]` |
| Resolver, no structure alert | only Jita alerts | structure service never called |
| GET item | alert `jita_sell`, Jita sell 12 | `currentPrice` 12.0, triggered fields kept |
| PATCH | triggered alert `jita_sell`, Jita sell 12, new threshold | `currentPrice` 12.0, re-armed (`active`, triggered fields null) |
| Frontend PATCH | response `currentPrice` 8.75 | store alert has `currentPrice` 8.75, other alerts unchanged |

## Edge cases

- Delete operation: the item provider also computes a price (unused by the 204 answer); accepted to keep one provider path.
- Create keeps rejecting a structure source without preferred structure (unchanged), so the resolver `null` branch is only reachable from the collection, GET and PATCH.
- Structure single-type reads in create switch from `getLowestSellPrice` to the batch method with one type id: same value.

## Access and freshness

No change: every operation keeps its `User` and ownership checks; prices come from the same caches as before.

## Existing tests changed

- `CreateAlertProcessorTest`, `UpdateAlertProcessorTest`, `MarketAlertCollectionProviderTest`: constructors take the resolver; assertions unchanged except the PATCH test that now expects the live price.
- `MarketAlertDeleteProviderTest` becomes `MarketAlertItemProviderTest` and adds the `currentPrice` case.
- `frontend/tests/stores/marketUpdateAlert.spec.ts`: "keeps the current price of the alert" is replaced by "replaces the alert with the PATCH response".

## Out of scope

Price cache freshness, batch reads for GET, the `triggeredPrice` semantics (#626), Delete answer shape, any other alert field.

## Glossary

No new term (price alert resource, not a business concept change).

## Prod actions

None (no migration, no console command).
