# #863 — Send only nameable types to the asset names endpoint

Issue #863 (group:assets-sync, circuit:m), related #779, #784, #861. Night mode: gates validated autonomously.

## Problem

`POST /{characters|corporations}/{id}/assets/names/` rejects the whole batch (404 `Invalid IDs in the request`, proven in prod) when it holds one id ESI cannot name. `AssetsService::processAssets()` sends every singleton outside an office (about 10,400 ids, mostly blueprints and fitted modules), so each batch is rejected, the shared bisection budget (50 calls) is spent in the first batch and almost no custom name is stored.

## Rules

1. **Nameable type** (the item name is the custom name of an assembled item; the glossary has no entry, to add). A type is nameable when its category id is 6 (ships), 22 (deployables), 23 (starbases) or 65 (structures), or its group id is 12, 340, 448 or 649 (containers). The ids are constants in `EveConstants`; the rule is one pure policy class (`NameableAssetType::accepts(?int $categoryId, ?int $groupId): bool`) in a layer reachable from Entity and Service (check `deptrac.yaml`). Category or group unknown (type absent from the SDE) is not nameable, never a default `true`.
2. **Sync**: `processAssets()` keeps as name candidates only items that are singleton, outside `OfficeFolder` and of a nameable type. Category and group come from the SDE through one `InvTypeRepository::findByTypeIds()` call on the unique type ids. Other items get `itemName = null` and are never sent. Chunks of 1,000 are cut after the filter.
3. **Bisection budget per batch**: `nameBisectionCallsLeft` is reset to `NAMES_BISECTION_CALL_BUDGET` (50) at the start of each chunk, so one rejected batch cannot starve the next. A 420/429 still stops name resolution for the rest of the sync (unchanged).
4. **Button** (decision 1 of `docs/specs/784-resolve-container-name.md`): `CachedAsset::canResolveItemName(?int $categoryId = null, ?int $groupId = null)` is true only when the type is nameable (rule 1), the row is a singleton (or an unflagged legacy row of quantity 1), unnamed and not an office. This replaces the legacy ship/celestial test. The two assets providers pass the group id of each type; `ResolveAssetItemNameProcessor` uses the same call and answers 422 `This type cannot have a custom name` for a non-nameable type, checked last: the unknown-flag, non-singleton, already-named and office reasons keep their messages and win.
5. `AssetsService` receives the SDE type lookup as a new constructor dependency (existing unit tests adapt their helper).

## Examples (sync, corporation assets)

| Raw asset | Singleton | Type | Sent to names endpoint |
|---|---|---|---|
| Rifter | yes | cat 6 | yes |
| Station Warehouse Container | yes | group 12 | yes |
| Singleton blueprint | yes | cat 9 | no |
| Fitted module | yes | cat 7 | no |
| Starbase tower | yes | cat 23 | yes |
| Upwell structure | yes | cat 65 | yes |
| Rifter stack | no | cat 6 | no |
| Container in `OfficeFolder` | yes | group 12 | no |
| Type absent from the SDE | yes | none | no |

1,500 nameable plus 5,000 blueprints: two calls (1,000 and 500 ids), no blueprint id in any body. Batch 1 rejected on every call (budget 50 spent), batch 2 still gets its own 50 calls and stores its names.

## Edge cases

- No nameable item: no names call at all.
- Button: flagged singleton of category 9 or 7 is not eligible (was eligible); unflagged legacy row of a container group is eligible; unknown category and group not eligible.

## Out of scope

#862 (other endpoints with the same body-less cause), bulk resolution of unnamed rows, persisting the unnamed state, Mercure, any change to the 1,000 chunk size.

## Open decisions

None.

## Production actions

No migration. Names appear after the next corporation and character assets sync (refresh or admin `TriggerAssetsSync`).
