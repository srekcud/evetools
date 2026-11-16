# #1019 — Structure health read with the corporation Director token

## Rules

1. The viewer's own characters are tried first, exactly as today (`Station_Manager` or `Director`, scopes `esi-corporations.read_structures.v1` and `esi-characters.read_corporation_roles.v1`).
2. When that lookup does not hold (role absent, reconnect required, ESI error), the reader falls back to the other connected characters of the same corporation (any user, `CharacterRepository::findByCorporationId`, the viewer's own characters excluded) and accepts only a `Director`, as corporation assets do (`AssetsSyncService::getCorpAssetsCharacter()`).
3. The fallback goes through `CorporationRoleReader::readAsHolder()` with an empty role list, so scope checks, role reads, the remembered holder and the 403 handling are shared.
4. The fallback runs at most once per corporation and per `read()`; the corporation characters are loaded only when the fallback is needed.
5. A successful read of `/corporations/{id}/structures/` is cached per corporation for 5 minutes in a dedicated pool, whichever path produced it: a second viewer or a second call within the window costs no ESI request. A failed read is never cached.
6. Exposure is unchanged: `read()` only maps the configurations it is given (the planned structures of the project owner), so no structure outside a project configuration is ever returned.
7. No Director connected, Director without the scopes, 403 or ESI error: the structure stays `unknown`, never `ok`.

## Examples

| Viewer characters | Corporation characters | Result |
|---|---|---|
| Station_Manager | Director | viewer token used, Director not read |
| Accountant only | Director with scopes | Director token used, health assessed |
| none | Director without the structures scope | unknown |
| none | Accountant only | unknown, no structures read |
| Accountant only | Director, second viewer within 5 minutes | one ESI structures read in total |

## Out of scope

Front wording, new endpoint, new scope, scheduled sync.
