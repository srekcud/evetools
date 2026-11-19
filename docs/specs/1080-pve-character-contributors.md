# 1080 — Character contributors for loot, escalations and expenses on the PVE revenue grid

Issue: #1080 (circuit:m, group:revenue-unification). Stacked on #1079 (PVE grid, scan processors).

## Rules

1. `pve_income` and `pve_expenses` get a nullable bigint `character_id` (EVE character id, migration). History stays null; nothing is backfilled.
2. Syncs fill it: bounty/ESS/mission journal entries, market loot sales, loot contracts, corp project contributions (the contributing character), ammo purchases (the scanned character).
3. Escalations: the income created from a sold escalation carries the escalation's `characterId`.
4. Scans return the scanned character's EVE id (`characterId`) on each detected sale, loot contract and expense; the frontend sends it back on import.
5. Imports store `characterId` only when it is one of the importing user's own characters; any other value is stored as null (the payload is untrusted).
6. Manual loot sales and manual expenses stay without a character.
7. Grid columns `lootSales`, `escalations`, `expenses` expose `contributors` (`name`, `value`) by character when every line of the period has a `character_id` that resolves to one of the user's characters; otherwise `contributors` is null (dash). Never partial shares.
8. `lootSales` contributors cover the same income types as the column total (loot sale, loot contract, and corp project unless mining accounts for it).
9. A column without any line in the period has null contributors (dash).
10. Bounties, ESS and missions keep reading the character from the journal description; the net keeps null contributors.

## Examples

| Lines in period | Contributors |
|---|---|
| loot 70 (Main), 30 (Alt) | `[Main 70, Alt 30]` |
| loot 70 (Main), 30 (no character) | null |
| loot 70 (character id not among the user's characters) | null |
| two escalations by Main (40 + 60) | `[Main 100]` |
| expenses 10 (Main), 5 (Alt) | `[Main 10, Alt 5]` |
| corp project excluded (mining accounting), corp project 50 without character, loot 20 (Main) | `[Main 20]` |

## Out of scope

Backfill of history, per-character split of the net, editing the character of an existing line, manual entry character picker.

## Decisions

- D1 empty column is a dash (keeps the existing grid test valid; value is 0 anyway).
- D2 untrusted character ids on import are dropped to null rather than rejected (the line is still imported).
