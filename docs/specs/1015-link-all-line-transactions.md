# #1015 — Link all matching wallet transactions of a line in one confirmed action

Front only (`LinePurchases.vue`, i18n FR+EN). No API, no migration, no Mercure topic: each link reuses `POST /industry/projects/{id}/bom/{lineId}/purchases` with `{ transactionId }` (already used by the per-row Link button).

## Rules

1. The purchases modal of a line gets a "Link all" action over the line's unlinked suggestions (same `typeId`, `alreadyLinked: false`).
2. Quantity left to buy = `max(0, requiredQuantity - purchasedQuantity)` of the line (same definition as `AutomaticPurchaseLinker::quantityLeftToBuy`).
3. Selection: unlinked suggestions oldest first (by `date`); a transaction is selected when the running total of selected quantities plus its own quantity stays within the quantity left to buy; one that does not fit is skipped (the next, smaller ones may still fit). A transaction is never split: a transaction is linked whole or not at all.
4. The button is shown only when at least 2 transactions are selected (one is the existing per-row Link) and is disabled while a submission runs.
5. Clicking it opens an inline confirmation in the modal (no browser dialog) with: number of transactions, total quantity, total ISK (sum of `totalPrice`) of the selected ones. Buttons: Confirm, Cancel. Cancel closes it and sends nothing.
6. When some unlinked transactions are skipped by rule 3, the confirmation also says how many are left out because they exceed the quantity left to buy (they stay linkable one by one with the row button).
7. Confirm links the selected transactions one request at a time, oldest first, each with body `{ transactionId: <uuid> }` only (no quantity). Linked rows flip to "Already linked" as in the single link.
8. First failure stops the sequence: nothing more is sent, the error shows with the existing `groupIndustry.purchases.linkError` message (or the API message), rows already linked stay linked, the confirmation closes.
9. Available to every participant (same as the row button), not only the owner.

## Examples (line needs 100, purchased 30 → left 70)

| Unlinked, oldest first | Selected | Left out |
|---|---|---|
| 20, 30, 40 | 20, 30 (50) ; 40 would reach 90 | 1 |
| 50, 30, 10 | 50, 10 (60) ; 30 would reach 80 | 1 |
| 20, 30 | 20, 30 | 0 |
| 80, 10 | 10 only → no Link all (single) | 1 |
| left 0 | none → no Link all | all |

## Out of scope

- Project-level indicator of lines with pending suggestions (the issue says "possibly"): kept for a later issue, noted in the night journal.
- Splitting a transaction to fill the last units.
- Backend bulk endpoint.
