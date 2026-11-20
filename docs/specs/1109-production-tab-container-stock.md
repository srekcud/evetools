# #1109 Production tab: count the container stock as supplied

Circuit m, mode nuit. Prerequisites checked on origin/main: `ProjectLineFulfillments`, `ProjectContainerFulfillment`, `ContainerStockState`, `ChainMaterialSupply`, `ProjectChainBuilder`, `ProjectChainProvider`. #1112 (materials summary replacing the material nodes) is not merged: the rule applies to the material nodes of `ProjectChainBuilder` today and carries over to the summary because both read the same value.

## Rules

1. The effective supplied quantity of a purchasable material line is `ProjectLineFulfillments::fulfilledOf` (#866): `max(fulfilledQuantity, min(container stock, required))`.
2. `ProjectChainBuilder::build` takes a `ProjectLineFulfillments` (default `none()`); `ProjectChainProvider` obtains it from `ProjectContainerFulfillment::of($project)`.
3. A material node carries the effective supplied quantity as `fulfilledQuantity`; its status (`done`, `active`, `todo`) and the remaining-share sort read the same value.
4. `ChainMaterialSupply::allocate` takes the same `ProjectLineFulfillments` (default `none()`); the pool of a material type sums the effective supplied quantity of its material lines. Job lines keep `getFulfilledQuantity()` (a job line is not read from the container here).
5. Unreadable container stock (`ContainerStockState::Unknown`) stays a lower bound: the pool holds `fulfilledQuantity` only. A job that is short of a material whose lines are in the `Unknown` state, and short of nothing else, reads `unknown` (`isSupplied` is `null`), never `missing_materials`. A job short of a material with a known state reads `missing_materials` even when another of its materials is unknown.
6. No container configured (`ContainerStockState::None`): behaviour unchanged.
7. Write paths keep reading `fulfilledQuantity`: no change outside the chain.

## Examples

| Required | Fulfilled | Container | State | Material node | Job needing it |
|---|---|---|---|---|---|
| 100 | 0 | 100 | Known | 100/100, `done` | `todo` (to launch) |
| 100 | 30 | 60 | Known | 60/100, `active` | `missing_materials` |
| 100 | 80 | 40 | Known | 80/100, `active` | `missing_materials` |
| 100 | 0 | 250 | Known | 100/100 (capped at required), `done` | `todo` |
| 100 | 40 | not read | Unknown | 40/100, `active` | `unknown` |
| 100 | 100 | not read | Unknown | 100/100, `done` | `todo` |
| 100 | 40 | none | None | 40/100, `active` | `missing_materials` |

## Edge cases

- Container stock is shared between jobs through the pool: two jobs needing 100 each with 150 in the container are `todo` then `missing_materials` (same allocation order as today).
- A job line also bought as a material keeps its own `getFulfilledQuantity()` in the pool, with no container addition.

## Out of scope

The materials summary of #1112, the job not seen yet of #1111, front-end changes (`ProductionChainFlowNode.vue` already shows `fulfilledQuantity`), and the Sourcing & jobs tab.

## Open decisions

None.
