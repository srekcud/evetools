# #1401 PI sell orders compared with the best price

## Goal
The sell orders of the PI products a user produces get the same comparison as project products (position, undercut since, price to copy), refreshed by the same 5-minute `character-orders` sync and shown in the Revenue tab of the Planetary module. Read-only: nothing is modified from evetools.

## Rules
1. **PI products of a user** (`PlanetaryProductTypes::of(User)`): the output type of every schematic run by a factory pin (`isFactory()`) of the colonies of the user's characters, keyed by type id with its SDE name. A colony without factory, an extractor-only colony, or a schematic without output gives nothing. A type produced by several colonies appears once. No factory: empty.
2. `UserSellableProductTypes::of()` returns the product types of the sellable projects (rules of #350 unchanged) plus the PI products, each type once. The sync, the position assessment and the Jita refresh therefore cover the PI products with no other change.
3. `ProjectSellOrdersReader::forProducts(User $viewer, array<int, string> $productNames): ProjectSellOrders` returns the cached orders of the viewer's characters for those types (names from the map), sorted by urgency (undercut first, then unknown, then first), with the characters to reconnect and the latest sync date. `of(project, viewer)` keeps its rules (personal use or completed project: empty) and delegates to it.
4. `GET /api/planetary/revenue` gains `sellOrders` = `{orders, charactersToReconnect, syncedAt}` (same fields as `ProjectSellOrdersResource`), filled with `forProducts` on the PI products. No PI product: empty orders. Only the connected user's characters are read.
5. Revenue tab: a "My sell orders" card under the grids. It reuses `ProjectSellOrdersCard` (position badge, undercut duration, best price, copy of the price to copy, reconnect hint), with a PI title and a PI empty message. A best price of `null` stays shown as unknown.

## Examples
| Case | Result |
|---|---|
| Colony with a factory on the Coolant schematic and an open sell order of Coolant, a cheaper order exists at Jita | the order is stored by the sync and shown as undercut with the price to copy |
| Same Coolant order, the user has no factory left | no PI product, the cached order is removed by the next sync, empty card |
| Coolant is also the final product of a selling project | listed once in `UserSellableProductTypes::of()`; the order shows in the project and in the PI card |

## Out of scope
The buy orders (#1393), the sell orders of P0 raw materials, a PI sell order toggle per product, any change of the undercut rules (#350, #1356), a new ESI scope.

## Open decisions
None. Orders of a product sold both by a project and by PI show in both lists (same orders, two readers).

## Prod actions
None: no migration, no console command. The orders appear at the next `character-orders` sync (5 minutes).
