# #865 — Rafraîchissement des assets corporation : délai et doublon en vol

Issue : #865 (circuit:m, group:assets-sync). Mode nuit. Les assets de la corporation sont supprimés et recréés à chaque sync (environ 20 000 lignes) et chaque sync dépense le budget partagé `corp-asset` de l'ESI. Deux corpmates qui rafraîchissent à quelques secondes d'écart lancent deux syncs complètes, l'ESI servant la même liste en cache.

## Faits vérifiés

- Cache ESI de `GET /corporations/{id}/assets/` : `x-cache-age` = `x-client-cache-ttl` = `x-server-cache-ttl` = 3600 s, spec OpenAPI publique `https://esi.evetech.net/meta/openapi.json` lue le 2026-11-13 (groupe `corp-asset`, 1800 jetons par 15 min).
- La date de dernière sync est déjà en base : `CachedAssetRepository::lastCachedAtOfCorporationAssets()` (MAX de `cached_at`, rempli par le constructeur de chaque ligne recréée). Aucune colonne ni migration.
- `SyncTracker` suit un état par type de sync (`corporation-assets`), pas par corporation : inutilisable pour « cette corporation est déjà en cours ». `LockFactory` n'est utilisé que dans le worker (`MoonTaxSyncService`), donc ne couvre pas « en file ».

## Règles

1. **Délai.** `CorporationAssetsRefreshGate::COOLDOWN_SECONDS = 3600` (durée de cache de l'ESI, constante : le sync jette l'`Expires` et la lire demanderait de toucher `AssetsService`, voir #863). `nextRefreshAt(corporationId)` = `lastCachedAtOfCorporationAssets + 3600 s` tant que cette date est dans le futur (horloge `Psr\Clock\ClockInterface`), sinon `null`. Aucune ligne en base : `null`.
2. **Refus sans dispatch.** `POST /me/corporation/assets/refresh`, une fois le Director trouvé : si `nextRefreshAt` n'est pas `null`, rien n'est dispatché ni synchronisé (ni en `async=true` ni en `async=false`). Réponse 200, `SyncStatusResource` : `status = up_to_date`, `message` explicite, `syncedAt` (dernière sync, ISO 8601 `c`), `nextRefreshAt` (ISO 8601 `c`). Le refus « No Director connected » reste prioritaire et inchangé.
3. **Pas de doublon en vol.** `CorporationAssetsRefreshGate::claim(corporationId): bool` pose un marqueur par corporation dans `CacheInterface` (clé `corporation_assets_refresh_in_flight_<id>`, durée de vie `IN_FLIGHT_TTL_SECONDS = 900`) et renvoie `true` seulement si aucun marqueur n'existait. Chemin `async=true`, délai écoulé : si `claim` renvoie `false`, rien n'est dispatché, réponse 200 `status = in_progress`, message explicite. Si `claim` renvoie `true`, le message est dispatché comme aujourd'hui (`status = pending`) ; si le dispatch lève, le marqueur est libéré (`release`) et l'exception remonte.
4. **Libération.** `SyncCorporationAssetsHandler` appelle `release(corporationId)` quand le message est traité, succès, échec ou sortie anticipée (personnage introuvable, ne peut pas synchroniser, exception relancée). Sans libération (worker tué), le marqueur expire seul après 900 s.
5. **Déclenchement admin inchangé.** `TriggerCorporationAssetsSyncHandler` dispatche toujours `SyncCorporationAssets` sans délai ni marqueur. Le `release` du handler peut alors lever le marqueur d'un refresh joueur en vol : accepté, le délai de la règle 1 reprend la main après la sync.
6. **GET.** `CorporationAssetsResource` gagne `nextRefreshAt: ?string` (ISO 8601 `c`, `null` quand le délai est écoulé ou sans ligne), calculé par `nextRefreshAt` pour la corporation du personnage principal. Rien d'autre ne change.
7. **Front, vue Assets (corporation).** `nextRefreshAt` vient du GET et de la réponse `up_to_date` ; en mode personnage il vaut `null`. Tant qu'il est dans le futur, le bouton de refresh est désactivé et son libellé indique l'heure EVE (UTC, `formatEveClock`) de la prochaine actualisation possible (`assets.refreshAvailableAt`, « Refresh available at {time} » / « Actualisation possible à {time} ») ; il se réactive à cette heure sans recharger la page. Réponse `up_to_date` : pas d'erreur, pas de spinner, `nextRefreshAt` mis à jour. Réponse `in_progress` : pas d'erreur, pas de spinner, message d'information `assets.syncAlreadyRunning` (« A sync of the corporation assets is already running » / « Une synchro des assets de la corporation est déjà en cours »).
8. **Front, autres appelants.** `ContainerBanner` (group industry) traite `up_to_date` comme `completed` (arrêt du spinner puis `verifyAll`) et `in_progress` comme un arrêt sans erreur. Le store `miningOperation.refreshCorporationAssets` renvoie le `status` ; `MiningOperationDetail` ne reste en attente (`awaitingAssetsSync`) que si le statut n'est ni `up_to_date` ni `in_progress`.

## Exemples

| Cas | État | Réponse |
|---|---|---|
| Sync il y a 10 min (t0 + 3600 s dans le futur) | refresh async | `up_to_date`, `syncedAt = t0`, `nextRefreshAt = t0 + 1 h`, 0 dispatch |
| Sync il y a exactement 1 h | refresh async | délai écoulé : `pending`, 1 dispatch |
| Jamais synchronisé | refresh async | `pending`, 1 dispatch |
| Délai écoulé, marqueur posé par un autre refresh | refresh async | `in_progress`, 0 dispatch |
| Deux refresh à 5 s d'écart, jamais synchronisé | async | le premier `pending` (1 dispatch), le second `in_progress` |
| Délai écoulé, `async=false` | refresh synchrone | sync exécutée (comportement actuel, sans marqueur) |
| Aucun Director | refresh | `error`, `hasAccess = false` (inchangé) |

## Cas limites

- Le délai se calcule sur la corporation du personnage principal de l'appelant : deux corpmates partagent le même délai.
- Une sync en échec ne modifie pas les lignes (récupération avant suppression) : le délai ne se déclenche pas, le refresh reste possible.
- `claim` n'est pas strictement atomique entre deux workers Redis : une fenêtre de quelques millisecondes reste possible, sans conséquence (une sync de trop).

## Hors périmètre

- `AssetsService` et le filtre des ids de noms (#863), `AssetsSyncService`, sync des assets personnage (`shouldSync` inchangé).
- Lecture de l'en-tête `Expires` de la réponse ESI.
- Le topic Mercure d'un corpmate qui n'est pas destinataire de la sync en vol (il voit `in_progress` et recharge à la main).
- Aucune migration, aucun nouvel endpoint, aucun réglage retiré.

## Tests

Unit : `CorporationAssetsRefreshGateTest` (délai, bornes, claim/release, TTL), `RefreshCorporationAssetsProcessorTest` (up_to_date, in_progress, dispatch, libération sur échec de dispatch, `async=false`), `SyncCorporationAssetsHandlerTest` (release sur chaque sortie), `CorporationAssetsProviderTest` (`nextRefreshAt`). Front : `Assets` (bouton désactivé avec l'heure, réactivation, `up_to_date`, `in_progress`), `ContainerBanner`, store `miningOperation`.
