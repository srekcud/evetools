# #1009 — Material contribution valued at the price paid from the contributor wallet

Circuit m, group `group:group-industry`. Prerequisite #981 is on main (`ContributorPaidPurchaseReader`, `resolveMaterialValue`).

## Vocabulary
- **Value source** of a material contribution: `wallet_purchase` (the contributor states they bought the units) or `jita_price` (stock of their own; default, today's behaviour).
- **Awaiting quantity**: units of a `wallet_purchase` contribution with no matching buy transaction read yet.
- **Matched units**: units covered by buy transactions of the contributor (same type, after the project creation, not linked yet), taken oldest first.

## Rules
1. `SubmitContributionInput.valueSource` is optional (`wallet_purchase` | `jita_price`), default `jita_price`. Sent for a non-material type: `DomainException` (400).
2. Matching is by allocation, oldest transaction first, on the remaining quantity of each transaction (transaction quantity minus the quantity of the purchases already linked to it in the project). Matched value = sum of allocated units x transaction unit price (actual price, no average).
3. Each matched allocation is persisted as an `IndustryProjectPurchase` on the line: `transaction`, `buyer` = the contributor participant, `source` `esi_wallet`, quantity = allocated units, and `contribution` = the contribution (new nullable relation). Linked quantity then counts in `getPurchasedQuantity()` and is never matched twice.
4. `DerivedContributionService` ignores purchases with a `contribution`: no derived proposal is built from them and the declared contribution is not replaced.
5. `wallet_purchase`: awaiting = quantity - matched units. Value = matched value, or `null` when no unit is matched (never 0, never a Jita estimate). No Jita lookup.
6. `jita_price`: unmatched units are valued at the Jita price of the deposit day (5th percentile). No Jita price for the unmatched part: explicit `DomainException`, as today. `valuedAtJitaPrice` is true when at least one unit is at Jita.
7. A contribution with awaiting > 0 is never auto-approved (solo project or sole reviewing owner stay pending) and `approve()` throws `DomainException` until awaiting = 0.
8. Switch: the contributor of a pending `wallet_purchase` contribution with awaiting > 0 can value those units at the Jita price (`POST /industry/projects/{projectId}/contributions/{id}/value-at-jita-price`, no body). Value = linked purchases total + Jita x awaiting, `valueSource` becomes `jita_price`, awaiting 0, `valuedAtJitaPrice` true. No Jita price: 400, nothing changed. Other user, non pending, awaiting 0: 400/403.
9. Revaluation (`PendingContributionRevaluer`, called by `WalletTransactionSyncService` after new transactions of a character are persisted, for the user of that character): every pending, non auto-detected, material contribution of that user with awaiting > 0 or `valuedAtJitaPrice` is matched on its missing units (quantity - linked units) with the new transactions, same allocation and linking as rule 2/3.
   - `wallet_purchase`: awaiting = missing - newly matched; value = linked total (including new), `null` if nothing linked.
   - `jita_price`: units still at Jita keep their implied unit price (estimated value - linked total before, divided by missing units); `valuedAtJitaPrice` false when no unit is left at Jita. No Jita lookup.
   - A contribution with nothing newly matched is not touched. Approved, rejected and auto-detected contributions are never revalued. A Mercure group project event `contribution_valued` (`contributionId`, `bomItemId`) is published per revalued contribution; the front refreshes contributions, BOM, distribution on it.
10. Rejecting a declared material contribution deletes the purchases linked to it (the transactions become matchable again).
11. A `wallet_purchase` contribution with awaiting > 0 after submit requests a wallet refresh for the contributor characters whose reading is due (`ContributorWalletRefresher`, extracted from `RefreshProjectWalletProcessor`, which keeps its response). Not-due characters are not requeued.
12. Preview: `GET /industry/projects/{projectId}/material-value-previews/{id}?quantity=N` (id = BOM line id, accepted member only) returns `matchedQuantity`, `matchedValue` (null when 0), `unmatchedQuantity`, and `matches` (transaction date, quantity, unit price). Invalid or non positive quantity: 400.
13. `ProjectContributionResource` exposes `valueSource` (null for non material), `awaitingQuantity` and `switchableToJitaPrice` (viewer is the contributor, pending, awaiting > 0; `contributionToResource(contribution, viewer)`).

## Examples (Tritanium, Jita 7.0)
| Declared | Wallet (oldest first) | Source | Result |
|---|---|---|---|
| 100 | 100 @ 5 | wallet_purchase | value 500, awaiting 0, 1 purchase of 100 linked |
| 100 | 60 @ 5 | wallet_purchase | value 300, awaiting 40, no Jita lookup |
| 100 | none | wallet_purchase | value null, awaiting 100, refresh requested, not approvable |
| 100 | 60 @ 5 | jita_price | value 580, `valuedAtJitaPrice`, 60 linked |
| 100 | 200 @ 5 in one transaction | wallet_purchase | value 500, 100 linked, 100 left on the transaction |
| 100 (awaiting 40, value 300) | new 40 @ 6 | wallet_purchase | value 540, awaiting 0 |
| 100 (60 linked @ 5, 40 at Jita, value 580) | new 10 @ 6 | jita_price | value 300 + 60 + 30 x 7 = 570 |
| 100 (awaiting 40, value 300) | switch | wallet -> jita | value 580, awaiting 0 |

## Front (HUD, Contribute modal and contributions table)
- Contribute, material only: two radio cards "Wallet purchase" / "Jita price of the deposit day"; the existing "deposit day price" note shows for the Jita card only.
- Preview fetched when the line and a positive quantity are set (debounced); shows "N units matched in your wallet at X ISK" and, depending on the source, "M units awaiting a wallet transaction" or "M units valued at Jita". Failure to load the preview: visible message, the submit stays possible.
- Contributions table: a contribution with awaiting > 0 shows "N units awaiting wallet transaction" and "Value unknown" for them (never 0), the approve button is disabled with a title, and the contributor sees "Value at Jita price" which calls the switch endpoint.
- i18n FR + EN, help calculation page `group-contributions` updated.

## Edge cases
- Transaction before the project creation, sell transactions, other types: not matched (reader unchanged).
- Quantity larger than the remaining line quantity is still refused before any valuation.
- Revaluation with several characters: the reader already adds up all characters of the contributor.

## Out of scope
Automatic linking of non-owner purchases without declaration, pre-existing container stock (#1006), edit and withdraw of a contribution (#1012).

## Migration
`industry_project_contributions`: `value_source` varchar(20) null, `awaiting_quantity` int default 0. `industry_project_purchases`: `contribution_id` uuid null, FK ON DELETE CASCADE. Existing rows: `value_source` null (read as Jita), awaiting 0. No prod command.
