# #1399 POCO taxes from the wallet journal, per colony

## Goal
The import and export taxes a character pays at a customs office (POCO) are kept from its wallet journal and can be read per colony and per day. Read-only. Prerequisite of the PI daily profit (#1400).

## Rules
1. `WalletJournalSyncService` keeps the journal lines whose `ref_type` is `planetary_import_tax` (CCP ref type 96) or `planetary_export_tax` (97), in addition to the contract fee lines and the project donations. Other `ref_type`s stay dropped.
2. A tax line is stored like a contract fee line: `amount` (negative: ISK paid), `date`, `context_id` (the `planet_id`), `context_id_type` as sent by the ESI, no reason, no first party. Already stored lines (same character and entry id) are not stored twice.
3. `CachedWalletJournalEntry` exposes the constants `PLANETARY_IMPORT_TAX`, `PLANETARY_EXPORT_TAX`, `PLANETARY_TAX_REF_TYPES`.
4. `CachedWalletJournalEntryRepository::planetaryTaxesByColonyAndDay(list<Character>, \DateTimeImmutable $since)` returns a list of `PlanetaryTaxDay` (`eveCharacterId`, `planetId`, `day` as `Y-m-d` UTC, `importTax`, `exportTax`), one per character, planet and day, for the lines dated at or after `$since`. Taxes are positive ISK paid (the sign of the journal amount is flipped), summed per day. A day with only one kind of tax has 0.0 for the other: this is a real sum of zero lines, not a missing value.
5. Only lines of the given characters, only the two tax ref types, and only lines with a `context_id` count. Sorted by day, then planet, then character.
6. No character: empty list. A colony with no tax line has no row: the consumer reads "no tax known" as absence, never as a default 0.

## Examples
| Journal lines of the character | Rows |
|---|---|
| planet 40 000 001, import -12 000 and export -3 000 on 2026-11-20 | `{40 000 001, 2026-11-20, 12 000, 3 000}` |
| planet 40 000 001, import -5 000 on 2026-11-20 and import -7 000 the same day | `{.., 2026-11-20, 12 000, 0}` |
| `contract_brokers_fee` line on a contract id | no row |

## Out of scope
API exposure, per-colony profit, the corporation wallet (POCO of a corporation), any change to the wallet journal sync schedule or scopes.

## Open decisions
None. No migration: the table and the `context_id` index already exist.

## Prod actions
None: the next wallet journal sync keeps the tax lines of the last 30 days the ESI returns.
