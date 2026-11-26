# #1393 Store character buy orders

## Goal
The buy orders of every character of a user are stored and refreshed with the sell orders, readable per type (open quantity, price, location). Read-only: no order is modified from evetools. Prerequisite of the PI stock coverage (#1395) and of the paid price of PI inputs (#1396).

## Rules
1. One ESI read per character (`GET /characters/{id}/orders/`, scope `esi-markets.read_character_orders.v1`, already requested) yields both the sell orders and the buy orders. `CharacterOrdersService::getOrders()` returns a `CharacterOrders` with `sellOrders` (list of `CharacterSellOrder`) and `buyOrders` (list of `CharacterBuyOrder`). It replaces `getSellOrders()`.
2. A row is a buy order only when `is_buy_order` is `true`; a missing flag is a sell order (unchanged). A buy row with a missing or invalid field raises the same explicit `EsiApiException` as a sell row.
3. Buy orders are stored in a new table `cached_character_buy_orders` (entity `CachedCharacterBuyOrder`): `order_id` (bigint, unique), `character`, `type_id`, `location_id` (bigint), `price`, `volume_remain`, `volume_total`, `issued_at`, `synced_at`. No position, rank or undercut data: those only exist for sell orders.
4. Every buy order of the character is stored, whatever its type (PI inputs are not project products). Sell orders keep their rule: only the types of the products of a sellable project.
5. Each sync replaces the buy orders of a character: new rows are created, existing rows (same `order_id`) are updated, rows no longer returned by the ESI are removed.
6. A character whose ESI read fails keeps all its rows, sell and buy, and does not block the others (unchanged rule, now for both tables).
7. A user without sellable project no longer skips the ESI read: the buy orders are stored and the cached sell orders of its characters are removed.
8. The Jita books are refreshed only for the types of the sell orders kept (buy orders never trigger a refresh).
9. `CachedCharacterBuyOrderRepository` offers `findByCharacter(Character)` and `findByCharactersAndTypes(list<Character>, list<int>)` (empty characters or types give an empty list).
10. The existing `character-orders` Mercure topic and messages are kept; the started message reads "Fetching orders...".

## Examples
| Case | Result |
|---|---|
| ESI returns 1 sell (Hammerhead, project product) + 1 buy (Robotics, 10 000 remaining, 4 800 ISK, Jita 4-4) | 1 sell row, 1 buy row |
| Same sync, the buy order is gone from the ESI | its row is removed |
| ESI returns a buy order of a type that is not a project product | stored |
| User without sellable project, ESI returns 1 buy | ESI read once, buy stored, cached sell rows removed |

## Out of scope
Exposing the buy orders through an API resource (#1395, #1396), the coverage grid, the paid price, any change of the sell orders position logic.

## Open decisions
None. Separate entity chosen (not a flag on `CachedCharacterOrder`) so that no consumer of the sell orders (`ProjectSellOrdersReader`, `UserLastDataReader`) can read a buy order as a sell order.

## Prod actions
Migration at deploy (`make deploy` runs it); the table fills at the next character orders sync.
