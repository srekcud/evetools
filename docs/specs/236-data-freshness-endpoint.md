# Spec #236 : Fraîcheur des données, endpoint utilisateur et date de dernière sync réussie

**Statut : validée en autonomie en mode nuit le 2026-10-31, à relire par Sylvain.**

Circuit : `circuit:m` (étiquette de l'issue : spec, phase rouge, phase verte, revue, pas de note de câblage). Source : issue #236 et `docs/specs/229-data-freshness.md` (M1, M2, M4, contrat proposé, cas limites). Lot backend seulement : le composant front `DataFreshness` n'est pas branché ici.

## Contexte

Les écrans de décision industrie et marché calculent sur des prix Jita, des cost index et des adjusted prices issus de **syncs globales**. Aucun endpoint utilisateur n'expose l'âge de ces données (spec #229, M1) : `SyncTracker::getAll()` n'est lu que par `AdminService` (`AdminService.php:33`, champ admin `AdminStatsResource::$schedulerHealth`, `AdminStatsResource.php:58`).

- **M2, pas de date de dernière réussite.** `SyncTracker` garde par type un état Redis de 7 jours (`SyncTracker.php:15`, `getState()` `:184-192`) : `started_at`, `completed_at`, `status` (`ok`, `error`, `running`, ou `unknown` par défaut), `message`. `fail()` écrase `completed_at` avec l'heure de l'échec (`:92-105`) : `completed_at` est la fin de la dernière exécution, réussie ou non. `start()` garde `completed_at` (`:69`), `complete()` le pose (`:77-90`).
- **M3, déjà corrigé.** `TriggerJitaMarketSyncHandler` lève une exception quand `syncJitaMarket()` rend `success: false` (`TriggerJitaMarketSyncHandler.php:31-33`) et appelle alors `fail()` (`:45`) ; `JitaMarketService::syncJitaMarket()` rend bien `success: false` sur exception (`JitaMarketService.php:96-107`). Correction livrée par le commit `3f077f48` (#237). Les trois sources de cette spec appellent donc `fail()` sur échec : `market-jita` (`:45`), `cost-indices` (`SyncCostIndicesHandler.php:40`), `adjusted-prices` (`SyncAdjustedPricesHandler.php:40`).
- **M4, la méta Jita n'est pas une source fiable.** La méta `syncedAt` expire après 2 h alors que `refreshPricesForTypes()` réécrit le cache des prix : l'âge vient de `SyncTracker` (état gardé 7 jours), pas de `JitaMarketService::getLastSyncTime()`.
- M5 (Mercure pour les utilisateurs) reste hors périmètre, comme dans la spec #229.

## Règles métier

- **R1 : Trois sources fixes.** La réponse contient exactement trois entrées, dans cet ordre : `market-jita`, `cost-indices`, `adjusted-prices`. La liste est une constante du provider, pas `SyncTracker::getAll()` : les autres types (industry, pve, wallet…) sont des syncs par utilisateur ou à la demande, sans sens pour la fraîcheur des écrans de décision. Chaque source est une clé de `SyncTracker::EXPECTED_INTERVALS` (`:19-32`).
- **R2 : Date de dernière réussite.** `SyncTracker` porte un champ d'état `last_success_at`, au format `format('c')` comme `completed_at`.
  - `complete()` le pose, à la même valeur que le `completed_at` qu'il écrit.
  - `start()` et `fail()` le gardent tel quel : un échec ou une exécution en cours ne modifie jamais la date de dernière réussite.
  - Il est écrit dans le même état que les autres champs : il vit autant qu'eux (voir « Cas limites », renouvellement du délai).
- **R3 : Etat écrit avant ce lot.** Un état sans `last_success_at` (clé absente ou `null`) donne :
  - `status` = `ok` : `lastSuccessAt` = son `completed_at`. C'est une donnée enregistrée (fin d'une exécution réussie), pas une valeur inventée ;
  - `status` = `error`, `running` ou `unknown` : `lastSuccessAt` = `null`. Le `completed_at` d'un échec n'est jamais présenté comme une réussite.
- **R4 : Dernière exécution en échec.** `lastRunFailed` est vrai quand le `status` est `error`, faux sinon. Une exécution `running` le rend faux : elle n'efface pas la date de réussite (`lastSuccessAt` reste celle de R2), seulement l'indication d'échec jusqu'à la fin de l'exécution.
- **R5 : Etat absent ou expiré.** Sans état (jamais lancée, Redis vidé, expiré après 7 jours sans écriture) : `lastSuccessAt` `null`, `lastRunFailed` faux, `expectedInterval` rendu normalement. Jamais d'erreur HTTP.
- **R6 : Pas de repli sur la méta Jita (M4).** Une date absente reste `null` : ni méta `syncedAt`, ni heure de la requête, ni date de `started_at`.
- **R7 : Contenu minimal.** Chaque entrée porte quatre champs : `source`, `lastSuccessAt`, `expectedInterval`, `lastRunFailed`. Pas de `message` d'erreur (il peut contenir du texte d'exception ESI), pas de `started_at`, pas de `status`, pas de déclencheur ni d'identifiant d'admin. L'endpoint est en lecture seule et ne déclenche aucune sync.
- **R8 : Accès.** `ROLE_USER` (`security: "is_granted('ROLE_USER')"`, comme `ColonyResource`). Un accès anonyme rend 401. L'endpoint ne dépend d'aucune donnée de l'utilisateur : tous les utilisateurs reçoivent la même réponse.

## Contrat de `GET /api/data-freshness`

API Platform : `GetCollection` d'une `DataFreshnessResource` avec un provider (jamais de contrôleur), `paginationEnabled: false` (trois entrées fixes ; même option que `GroupContainerVerificationResource.php:32`), identifiant `source` (`#[ApiProperty(identifier: true)]`, pas de `$id`).

| Champ | Type | Contenu |
|---|---|---|
| `source` | `string` | `market-jita`, `cost-indices` ou `adjusted-prices` (R1) |
| `lastSuccessAt` | `?string` | ISO 8601 avec décalage (`format('c')`), chaîne telle qu'enregistrée par `SyncTracker`, non reconvertie ; `null` si inconnue (R2, R3, R5) |
| `expectedInterval` | `int` | secondes, `SyncTracker::EXPECTED_INTERVALS[source]` : 3600, 7200, 86400 |
| `lastRunFailed` | `bool` | R4 |

Réponse (état vierge) : trois entrées `{source, lastSuccessAt: null, expectedInterval, lastRunFailed: false}` dans l'ordre de R1. Statuts : 200 ; 401 sans authentification (R8). Pas de 404 ni de 5xx liés à un état absent (R5).

## Exemples chiffrés

Date : 2026-10-31. Source `market-jita` (intervalle 3600) sauf mention. Les décalages sont écrits `+00:00` : le fuseau PHP du serveur n'est fixé nulle part dans `config/` (inconnu), la valeur suit `format('c')` du serveur ; les tests comparent les chaînes enregistrées, pas un fuseau.

| # | Etat enregistré / événement | `lastSuccessAt` | `lastRunFailed` |
|---|---|---|---|
| E1 | Aucun état (jamais lancée, ou expiré) | `null` | faux |
| E2 | `start()` 09:55, `complete()` 10:00 | `2026-10-31T10:00:00+00:00` | faux |
| E3 | Puis `start()` 10:30, `fail()` 10:30 (`completed_at` devient 10:30) | `2026-10-31T10:00:00+00:00` : l'échec ne la modifie pas | **vrai** |
| E4 | Puis `start()` 11:00 (exécution en cours, statut `running`) | `2026-10-31T10:00:00+00:00` | faux (R4) |
| E5 | Puis `complete()` 11:00 (réussite après l'échec) | `2026-10-31T11:00:00+00:00` | faux |
| E6 | Ancien état : `status` `ok`, `completed_at` 09:00, pas de `last_success_at` | `2026-10-31T09:00:00+00:00` (son `completed_at`, R3) | faux |
| E7 | Ancien état : `status` `error`, `completed_at` 09:00 (heure de l'échec) | `null` | **vrai** |
| E8 | Ancien état : `status` `running`, `completed_at` 08:00 | `null` | faux |
| E9 | État de E6, puis `start()` 09:30 (première exécution après le déploiement) | `null` : `running` sans `last_success_at` (R3) | faux |
| E10 | État de E9, puis `complete()` 09:35 | `2026-10-31T09:35:00+00:00` | faux |
| E11 | État de E9, puis `fail()` 09:35 | `null` | **vrai** |
| E12 | Aucun état, puis `fail()` 10:30 (premier lancement en échec) | `null` | **vrai** |
| E13 | `complete()` 2026-10-20T10:00, puis un échec par jour jusqu'au 2026-10-31 | `2026-10-20T10:00:00+00:00` (11 jours, voir « Cas limites ») | **vrai** |
| E14 | Sources `cost-indices` et `adjusted-prices` sans état | `null`, `expectedInterval` 7200 et 86400 | faux |

Réponse complète pour E3 sur `market-jita`, états vierges pour les deux autres :

```json
[
  {"source": "market-jita", "lastSuccessAt": "2026-10-31T10:00:00+00:00", "expectedInterval": 3600, "lastRunFailed": true},
  {"source": "cost-indices", "lastSuccessAt": null, "expectedInterval": 7200, "lastRunFailed": false},
  {"source": "adjusted-prices", "lastSuccessAt": null, "expectedInterval": 86400, "lastRunFailed": false}
]
```

Forme indicative : le format de collection (JSON-LD, tableau) suit la configuration d'API Platform comme pour `/api/planetary`.

Accès :

| # | Appel | Attendu |
|---|---|---|
| E15 | Sans jeton | 401 |
| E16 | Utilisateur ordinaire (non admin) | 200, mêmes données que pour tout utilisateur |

## Cas limites

- **Le délai de 7 jours est renouvelé à chaque écriture.** `saveState()` supprime puis recrée l'entrée avec un nouveau délai de 7 jours (`SyncTracker.php:195-203`) : `last_success_at` survit tant que des exécutions (même en échec) écrivent l'état. Une sync qui échoue chaque heure depuis 11 jours rend donc encore la date de la dernière réussite (E13), plus vieille que 7 jours. Cohérent avec la spec #229 (niveau « périmé »). L'état expire seulement après 7 jours **sans aucune exécution** (R5).
- **Première exécution après le déploiement.** `start()` écrit l'état `running` avant chaque exécution ; l'ancien état `ok` de R3 n'est donc lu que jusqu'au prochain tick (au plus 1 h pour `market-jita`, 2 h pour `cost-indices`, 24 h pour `adjusted-prices`). Pendant l'exécution, `lastSuccessAt` vaut `null` (E9) ; si elle échoue, `null` jusqu'à la première réussite (E11). Conséquence acceptée de la décision « `start()` conserve le champ tel quel » : pas de migration de l'ancien état.
- **Reprises Messenger.** Les handlers `cost-indices` et `adjusted-prices` appellent `fail()` puis relancent l'exception (`SyncCostIndicesHandler.php:40-42`), et le transport `async` rejoue le message jusqu'à 3 fois, délai 1 s doublé (`messenger.yaml:8-12`, routage `:37-38`). Entre deux reprises `start()` remet le statut à `running` : `lastRunFailed` repasse brièvement à faux avant de revenir à vrai. `lastSuccessAt` ne bouge pas.
- **Exécution `running` bloquée** (worker arrêté en cours de sync) : le statut reste `running`, `lastRunFailed` faux, `lastSuccessAt` garde la dernière réussite ; l'âge, calculé par le front, finit en niveau périmé (spec #229, R2).
- **Lecture d'un état absent.** `getState()` enregistre l'état par défaut (`unknown`) avec un délai de 7 jours quand la clé est absente (`SyncTracker.php:188-191`) : un GET sur état vierge écrit une entrée vide dans le cache, exactement comme `getAll()` le fait déjà. Sans effet sur la réponse.
- **Source hors `EXPECTED_INTERVALS`.** Impossible par construction (R1) ; un test le garantit pour que le provider ne lise jamais un index absent.
- **Fuseau.** La date reste une chaîne ISO 8601 avec décalage ; l'âge ne dépend pas du fuseau (spec #229).
- **`getAll()` inchangé.** Ses entrées (`buildEntry()`, `:143-156`) n'ont pas de nouvelle clé : le contrat admin `schedulerHealth` et les assertions exactes de `SyncTrackerTest` (`testNeverRunScheduledSyncIsUnknown`) ne bougent pas.

## Tests

Tous en `#[Group('issue-236')]`.

**Tests existants à garder verts** : `tests/Unit/Service/Admin/SyncTrackerTest.php` en entier (aucune assertion ne lit le nouveau champ ; `testNeverRunScheduledSyncIsUnknown` compare l'entrée de `getAll()` clé par clé), `tests/Unit/MessageHandler/TriggerJitaMarketSyncHandlerTest.php`, `SyncCostIndicesHandlerTest`, `SyncAdjustedPricesHandlerTest`.

**Unitaire, `SyncTrackerTest`** (ou classe sœur `SyncTrackerLastSuccessTest`). Le harnais garde la référence de l'`ArrayAdapter` (construit en ligne aujourd'hui, `SyncTrackerTest.php:50-53`) pour pré-écrire un état sous la clé `sync_tracker_market_jita` (`SyncTracker.php:13,186`, tirets remplacés par `_`) : l'horloge réelle ne distingue pas deux appels dans la même seconde, une date pré-écrite ancienne (2026-01-15T10:00:00+00:00) distingue « conservée » de « réécrite à l'instant ».

- U1 : `complete()` pose `lastSuccessAt` entre l'instant avant et après l'appel (E2, motif de `assertTimestampBetween`), `lastRunFailed` faux.
- U2 : `fail()` sur un état pré-écrit avec `last_success_at` ancienne : la date est inchangée, `lastRunFailed` vrai (E3).
- U3 : `start()` sur le même état : date inchangée, `lastRunFailed` faux (E4).
- U4 : `complete()` après un `fail()` : date mise à jour, `lastRunFailed` faux (E5).
- U5 : états d'avant le lot, pré-écrits sans `last_success_at` : `ok` avec `completed_at` 09:00 rend 09:00 ; `error` rend `null` et vrai ; `running` rend `null` et faux (E6, E7, E8).
- U6 : `start()` sur l'état `ok` d'avant le lot rend `null`, puis `complete()` la date de fin, puis, variante, `fail()` rend `null` et vrai (E9, E10, E11).
- U7 : aucun état et `fail()` : `null` et vrai (E12) ; aucun état et rien : `null` et faux (E1).
- U8 : le délai est renouvelé par chaque écriture : `complete()`, `cacheClock->sleep` de 6 jours, `fail()`, 6 jours de plus : la date de réussite est encore rendue (E13) ; sans écriture, `sleep` de 7 jours + 1 s : `null` (E1, motif de `testSyncStateExpiresAfterSevenDays`, `SyncTrackerTest.php:393-401`).
- U9 : `getAll()` n'a pas de clé `last_success_at` (contrat admin inchangé).

**Unitaire, provider** (`tests/Unit/State/Provider/DataFreshness/DataFreshnessProviderTest.php`, vrai `SyncTracker` sur `ArrayAdapter`) : l'ordre `market-jita`, `cost-indices`, `adjusted-prices`, les intervalles 3600, 7200, 86400 (E14), et chaque source de la constante est une clé de `SyncTracker::EXPECTED_INTERVALS`.

**HTTP fonctionnel** (`tests/Functional/Api/DataFreshness/DataFreshnessApiTest.php`, `WebTestCase`, base de test, `loginUser` comme `ImportLootSalesApiTest.php`). En environnement `test` le cache applicatif est un `ArrayAdapter` (`config/packages/cache.yaml:32-35`) : avec `disableReboot()` le test écrit l'état par le `SyncTracker` du conteneur de test (`complete()`, `fail()`) puis appelle l'endpoint.
- H1 : sans jeton, 401 (E15).
- H2 : utilisateur ordinaire, état vierge : 200, trois entrées dans l'ordre de R1, `lastSuccessAt` `null`, `lastRunFailed` faux, intervalles 3600, 7200, 86400 (E1, E14, E16).
- H3 : `complete('market-jita')` puis `fail('market-jita', …)` : `lastRunFailed` vrai, `lastSuccessAt` non nul et égal à la valeur lue après `complete()` ; les deux autres sources inchangées (E3).
- H4 : `complete()` puis `fail()` puis `complete()` : `lastRunFailed` faux (E5).
- H5 : chaque entrée a exactement les quatre clés `source`, `lastSuccessAt`, `expectedInterval`, `lastRunFailed` : ni `message`, ni `started_at`, ni `status` (R7), message d'échec contenant un texte reconnaissable absent du corps de la réponse.

Aucun test de front : le composant n'est pas branché dans ce lot.

## Fichiers de production à modifier

| Fichier | Changement |
|---|---|
| `src/Service/Admin/SyncTracker.php` | `last_success_at` dans l'état : posé par `complete()`, conservé par `start()` et `fail()`, `null` dans l'état par défaut de `getState()` ; nouvelle méthode publique `getFreshness(string $type): array{lastSuccessAt: ?string, lastRunFailed: bool}` qui applique R3, R4, R5 ; `getAll()` et `buildEntry()` inchangés |
| `src/ApiResource/DataFreshness/DataFreshnessResource.php` (nouveau) | `GetCollection` `/data-freshness`, `security: "is_granted('ROLE_USER')"`, `paginationEnabled: false`, `source` identifiant, `lastSuccessAt`, `expectedInterval`, `lastRunFailed` |
| `src/State/Provider/DataFreshness/DataFreshnessProvider.php` (nouveau) | constante `SOURCES` (R1), une ressource par source via `SyncTracker::getFreshness()` et `EXPECTED_INTERVALS` |

Aucun changement de `config/` : `^/api` exige déjà `IS_AUTHENTICATED_FULLY` (`security.yaml:34`, firewall `api` en `jwt`, `:22-25`). Pas de migration, pas de commande à lancer en production : les dates se remplissent aux prochains ticks des trois syncs, l'état d'avant le lot est repris par R3 d'ici là.

À faire à la phase verte, hors production :
- `docs/glossary.md`, ligne « Dernière sync réussie » : remplacer « Côté backend, rien ne la porte encore… » et le symbole backend « aucun » par `SyncTracker::getFreshness()` / `last_success_at` et `GET /api/data-freshness` ; ajouter le terme ci-dessous.
- `docs/specs/229-data-freshness.md` : la ligne « tant que M3 n'est pas corrigé » (« Cas limites ») et la phrase de M1 citant `TriggerSyncProcessor` sont périmées (voir le rapport).

## Décisions retenues en autonomie

Mode nuit, décisions validées avant l'écriture :

1. `last_success_at` est un champ d'état de `SyncTracker`, posé par `complete()`, conservé tel quel par `start()` et `fail()` (R2).
2. Etat d'avant le lot : `status` `ok` rend son `completed_at` ; `error` ou `running` sans `last_success_at` rend `null` (R3).
3. `lastRunFailed` = `status` `error` ; une exécution `running` ne l'efface pas côté date, le rend seulement faux (R4).
4. Etat absent ou expiré : `null`, faux, jamais d'erreur (R5).
5. Pas de repli sur la méta Jita (R6, M4).
6. Liste des sources figée dans une constante, pas tout `getAll()` (R1).
7. Accès anonyme : 401 (R8).
8. Mercure hors périmètre ; le front n'est pas branché dans ce lot. Issue séparée à ouvrir : « Brancher `DataFreshness` sur `GET /api/data-freshness` » (écrans de la décision 2 de la spec #229).

Choix d'implémentation, retenus par la même passe :

9. Méthode dédiée `getFreshness()` plutôt qu'une clé de plus dans `getAll()` : `getAll()` alimente `schedulerHealth` côté admin et ses entrées sont assertées clé par clé dans `SyncTrackerTest`.
10. `lastSuccessAt` rendu comme la chaîne enregistrée (`?string`), pas un `DateTimeImmutable` : évite une reconversion et un format de sérialisation propre à API Platform ; le format reste celui de `format('c')` voulu par la spec #229.
11. La constante `SOURCES` est dans le provider (seul lecteur), pas dans la resource.
12. Pas de migration de l'ancien état dans `start()` : la fenêtre de E9 à E11 est courte (durée d'une exécution) et ne se produit qu'une fois par source. Alternative écartée, une ligne dans `start()` : `last_success_at` repris du `completed_at` si l'ancien statut est `ok`. Elle contredit « conservé tel quel » ; à décider par Sylvain si la fenêtre gêne.
13. Pas d'en-tête de cache HTTP : l'endpoint est léger, le front le lit au chargement de l'écran (spec #229, « Hors périmètre »).

## Glossaire

« Sync globale », « Dernière sync réussie » et « Fraîcheur » existent (`docs/glossary.md:188-190`) ; seule la ligne « Dernière sync réussie » devient fausse à la phase verte (voir plus haut). Terme proposé, à valider :

| Terme | EN | Définition proposée | Symboles |
|---|---|---|---|
| **Dernière exécution en échec** | Last run failed | Vrai quand l'état enregistré d'une sync globale est `error` : la dernière exécution terminée a échoué et aucune autre n'a commencé depuis. Elle ne modifie pas la dernière sync réussie (spec #236, R4). | `DataFreshnessResource::$lastRunFailed`, `SyncTracker::fail()` |
