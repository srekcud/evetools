# #580 Preferred market structure access control

## Rules
1. `PATCH /api/me/settings` refuses with 422 a non-null `preferredMarketStructureId` that is not accessible (rule 2); nothing is written.
2. A structure is accessible when one of these holds:
   - it is the structure already stored as the user's preferred structure (unchanged values are never rejected);
   - the structure cache (`cached_structure`, filled by the structure selector from ESI search) holds it;
   - a personal asset (not corporation asset) of one of the user's own characters is located in it.
3. `null` is accepted and clears the id and the name.
4. Stored values are not touched by the check; an inaccessible stored structure is left as is.
5. The 422 is an API Platform `ValidationException` on `preferredMarketStructureId`.

## Examples
| Stored | Sent | Cache | Assets | Result |
|---|---|---|---|---|
| none | 60003760 | empty | none | 422, nothing stored |
| none | 60003760 | holds it | none | 200 |
| none | 1030000000001 | empty | own character asset there | 200 |
| none | 1030000000001 | empty | other user's asset there | 422 |
| 1030000000001 | name only | empty | none | 200, id kept |
| 1030000000001 | `null` | any | any | 200, id and name cleared |

## Out of scope
- `marketStructures` (favourites list) is not validated here; it does not feed the sync or the price alerts.
- No migration, no frontend change: the selector already goes through ESI search, which fills the cache.
