# 784 — Resolve the missing custom name of one container from its row

Issue #784 (group:assets-sync). Decision of 2026-11-12: option A (stored singleton flag).

## Goal

On an assets row of an unnamed singleton item (ship, container), a button resolves that one name from ESI without a full sync, stores it on the cached asset and updates the row in place.

## Rules

1. **Singleton flag.** `cached_assets.is_singleton` (nullable boolean, migration). `AssetDto::$isSingleton` is filled from the ESI `is_singleton` field in `AssetsService::processAssets()`, persisted by both sync methods of `AssetsSyncService`. Rows cached before the migration stay `null` until the next full sync; `null` is never treated as `true`.
2. **Eligibility** (`CachedAsset::canResolveItemName()`): `isSingleton === true`, `itemName === null`, `locationFlag !== 'OfficeFolder'` (offices cannot be named, see #779). `AssetItemResource` exposes `isSingleton` (?bool) and `canResolveItemName` (bool); both asset providers fill them.
3. **Operation.** API Platform `Post` on a new resource `AssetItemNameResource` (`src/ApiResource/Assets/`), `uriTemplate: '/me/assets/{id}/item-name'`, `{id}` = the `CachedAsset` UUID (the `id` already exposed by the asset providers), `uriVariables` with `Link(fromClass: self::class)`, `input: EmptyInput::class`, `status: 200`, `security: is_granted('ROLE_USER')`, processor `ResolveAssetItemNameProcessor` (`src/State/Processor/Assets/`). Output fields: `id`, `status` (`named` | `unnamed`), `itemName` (?string). Synchronous, no Mercure.
4. **Scoping (security, the guard that matters).** The asset is loaded by UUID, then:
   - personal asset (`isCorporationAsset = false`): `asset.character.user` must be the current user;
   - corporation asset: `asset.corporationId` must equal the corporation of the user's main character AND the asset must be in `VisibleCorporationAssetsFinder::findVisibleAssets($corporationId, null, $user)` (division visibility, a hidden division is not resolvable);
   - unknown UUID, malformed UUID, other user's asset, other corporation's asset, hidden division: all the same `404 Not Found` (no existence leak);
   - no main character on a corporation asset: `403` like the existing corporation provider.
5. **Eligibility guard.** A found and accessible asset that fails rule 2 answers `422` with a message naming the reason (not a singleton / already named / office). A `null` flag answers `422` too (« singleton flag unknown, refresh the assets »).
6. **Token and ESI call.** Personal asset: token of `asset.character` (`getEveToken()`), endpoint `/characters/{eveCharacterId}/assets/names/`. Corporation asset: token of `AssetsSyncService::getCorpAssetsCharacter($corporationId)` (Director holder), endpoint `/corporations/{corporationId}/assets/names/`. No token or no Director: `409` with an explicit message (no fallback to another character).
7. **One id.** A new public method on `AssetsService` posts exactly `[itemId]` to the names endpoint and returns the name or `null`. It does not reuse the batch/split path of `resolveItemNames()` (that path swallows rejections) and it does not catch `EsiApiException`: an ESI failure reaches the processor.
8. **Result mapping.**
   - ESI returns a non-empty name other than `'None'` (same filter as the batch path): `setItemName`, flush, respond `status: named` with `itemName`.
   - ESI returns no entry, an empty name or `'None'`: nothing stored, respond `status: unnamed`, `itemName: null` (explicit state, not an error).
   - ESI failure (any `EsiApiException` / transport error): `502 Bad Gateway` carrying the ESI message; nothing stored, no default value.
9. **Full sync unchanged.** `AssetsSyncService` keeps replacing all rows; a name stored by this operation is overwritten by the sync's own result (it also resolves it, or leaves it null).
10. **Frontend.** `Asset` type gets `isSingleton` and `canResolveItemName`. In `AssetLocationGroup.vue` (rows and container contents) a small button shows only when `asset.canResolveItemName` is true. Click: `POST /api/me/assets/{id}/item-name`, then per-row states: loading (button disabled), `named` (the row shows `name (type)` in place, the button disappears; `Assets.vue` updates its `assets` ref entry), `unnamed` (button replaced by a muted text « no custom name in game »; stays until reload), error (the ESI message shown on the row, button available again). i18n keys fr/en. HUD-EVE style, small icon button with `aria-label` and `title`.

## Examples

| Cached row | Result of the POST |
|---|---|
| ship, singleton, itemName null, ESI `[{item_id, name: "Hauler One"}]` | 200 `named`, row stored as « Hauler One » |
| container, singleton, ESI `[{item_id, name: "None"}]` or `[]` | 200 `unnamed`, nothing stored |
| container, singleton, ESI 404/5xx | 502 with the ESI message, nothing stored |
| stack of 500 Tritanium (`isSingleton = false`) | 422, no ESI call |
| row with `isSingleton = null` (pre-migration) | 422, no ESI call |
| `OfficeFolder` office | 422, no ESI call |
| asset of another user's character | 404, no ESI call |
| corporation asset of another corporation, or in a hidden division | 404, no ESI call |

## Edge cases

- Corporation asset and the Director's token expired or revoked: ESI error, mapped by rule 8 (no silent fallback).
- `itemName` already filled by a concurrent sync between display and click: 422 « already named ».
- Fitted modules and other unnamed singletons also get the button (the cache does not carry the nameable categories); ESI answers `unnamed`. See open decision 1.

## Tests (proportionate)

- Unit processor: one case per rule 4 (own character, other user, other corporation, hidden division, malformed UUID), rule 5 (three 422), rule 6 (token source, no Director 409), rule 8 (named, 'None', empty, ESI failure) and that the ESI call receives exactly one id.
- Unit: `AssetsService` single-name method (one id posted, endpoint per owner type, exception propagates), `AssetDto` flag filled in `processAssets()`, both sync methods persist `is_singleton`, `canResolveItemName()` truth table, providers expose the two new fields.
- Functional (`tests/Functional/Api/Assets/`): POST end to end with the ESI client stubbed: 200 named stores the name, 404 for another user's asset, 422 for a non-singleton.
- Vitest: button shown only when `canResolveItemName`, row updated after `named`, `unnamed` text, error shown.

## Out of scope

Bulk resolution of all unnamed rows, persisting the « unnamed in game » state, a category filter for the button, any change to the batch naming of #779, Mercure.

## Open decisions

1. The button is not limited to ships and containers (no nameable-category data in the cache). Proposed follow-up: filter by SDE category/groups once a source of truth is chosen. Not decided at night.
2. Constant `OfficeFolder` is now used by `AssetsService` and `CachedAsset`; extract to a shared constant (e.g. `EveConstants`) during implementation if one exists for location flags.

## Production actions

Migration (`is_singleton`), then a full assets sync (character and corporation) to fill the flag.
