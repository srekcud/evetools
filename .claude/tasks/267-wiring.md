# Note de câblage #267 : estimation, historique, API de scan structuré

Spec : `docs/specs/267-site-depletion-estimate.md`. Hors lot : parseur du collage, front, opération, partage, notifications.
Pas d'action asynchrone : aucun message Messenger, aucun Scheduler, **aucun topic Mercure**, pas de `SyncTracker`. Le prix Jita est lu en base (cache du marché) dans la requête.

## 1. Classes à créer

Convention : le minage reste dans les dossiers plats (ADR-0002 réserve Domain/Application à Industry, Corp Industry, Pricing). Le calcul pur vit donc en `Service`, sans import d'`Entity`, de Doctrine ni de Symfony (pureté vérifiée par les tests Unit, qui construisent tout à la main).

| Classe | Dossier | Couche deptrac | Rôle |
|---|---|---|---|
| `MiningSiteStatus` (Active, Closed) | `src/Enum` | Enum | R13 |
| `MiningSiteEtaUnknownReason` (`not_depleting`, `single_scan`) | `src/Enum` | Enum | R5, R7, R8 |
| `SiteScanSample` (at, `array<int,int>` unités par typeId, types présents seulement) | `src/Service/MiningSite` | Service | VO readonly, entrée pure |
| `SiteTypeEstimate` | idem | Service | VO readonly par type : débits, ETA, écart max (u, %), `irregular`, motif, `depletedBetween`, unités, m3?, prix?, valeur? |
| `SiteEstimate` | idem | Service | VO readonly site : types, ETA récente/tendance du site, `notDepletingTypeIds`, valeur restante?, `missingPriceTypeIds`, type le plus rentable (R10), `allDepleted` |
| `SiteDepletionEstimator` | idem | Service | `estimate(list<SiteScanSample>, array<int,?float> $unitPrices, array<int,?float> $volumes): SiteEstimate`. Sans état, sans constructeur. ETA arrondie à la seconde (R12) ici, un seul endroit |
| `MiningSiteHistorySummarizer` + `MiningSiteHistorySummary` | idem | Service | R18 pur : entrées = premier et dernier scan enregistrés, sortie = durée, volume/valeur au 1er scan, débit moyen. Rien recalculé |
| `MiningSiteUnitPrices` | idem | Service | `read(list<int> $typeIds): array<int,?float>` : `OreValueService::getBatchOrePrices($ids, null, 1.0, 0.0, MarketSide::Buy)[$id]['compressedEquivalentPrice']`, même clé que `MiningOperationUnitPrices` (#259) |
| `MiningSiteScanRecorder` | idem | Service | `record(MiningSite, list<ScanLine>)` : actif ? (R17), horodatage serveur, volumes SDE, prix, scans précédents, estimateur, persistance, clôture. Transaction unique |
| `MiningSiteRequest` | idem | Service | `siteOf(repo, user, id)`, 404 si absent ou autre propriétaire (même message). Imite `MiningOperationRequest`, réutilise `UuidOrNotFound`, `PersistedUuid` |
| `MiningSite`, `MiningSiteScan` | `src/Entity` | Entity | voir 3 |
| `MiningSiteRepository`, `MiningSiteScanRepository` | `src/Repository` | Repository | `findOwnedBy(user, ?solarSystemId)` récent d'abord, `findOwnedById`, `findBySiteChronological`, `existsAt(site, instant)` |
| `MiningSiteResource`, `MiningSiteScanResource` | `src/ApiResource/MiningSite` | ApiResource | voir 4 |
| `CreateMiningSiteInput`, `RecordMiningSiteScanInput` (+ ligne `{typeId, units}`) | `src/ApiResource/Input/MiningSite` | Dto-like (dossier ApiResource) | validation Assert |
| Providers : collection et item de site, collection et item de scan | `src/State/Provider/MiningSite` | StateProvider | scoping propriétaire |
| Processors : `CreateMiningSite`, `CloseMiningSite`, `RecordMiningSiteScan` | `src/State/Processor/MiningSite` | StateProcessor | |
| `MiningSiteResourceMapper` | `src/State/Provider/MiningSite` | StateProvider | les processors l'importent : ajouter les `skip_violations` habituels dans `deptrac.yaml` (rapport seulement) |
| Migration `Version202611xxxxxxxx` | `migrations/` | | 2 tables |

## 2. Dépendances et harnais

| Dépendance | Source réelle | Harnais |
|---|---|---|
| Estimateur | aucune | Unit : valeurs littérales E1-E13, aucun double |
| Volumes SDE | `Sde\InvTypeRepository::findByTypeIds()` puis `getVolume()` (`?float`, null = m3 inconnu) | Unit : tableau `array<int,?float>` passé tel quel. Integration/Functional : lignes `InvType` persistées (modèle `seedStaticData()` de `MiningOperationValuationApiTest`, à extraire dans un trait de `tests/` en phase rouge) |
| Prix | `OreValueService` + `JitaMarketService` | Unit : `array<int,?float>`. Integration/Functional : `JitaMarketService` remplacé dans le conteneur par un stub (`getBuyPrices` par typeId, `getLastSyncTime`), motif `replaceMarket()` de ce même test ; `OreValueService` réel, avec types bruts et `Compressed <nom>` de même `portionSize` pour que le prix équivalent égale le prix lu |
| Horloge | `Symfony\Component\Clock\ClockInterface` (alias PSR) | `ClockSensitiveTrait::mockTime()` ; le recorder tronque à la seconde (R1) |
| Base | Doctrine / PostgreSQL | Integration : `IntegrationTestCase` (transaction annulée, dama) |
| Utilisateur connecté | `Security` | Functional : `loginUser`, `MiningOperationRequest::userOf` réutilisable |
| Unités SDE des types | `findByTypeIds` ; type inconnu du SDE = 422, jamais ignoré | idem Integration |

Fake aux frontières seulement : jamais de mock de l'estimateur ni du recorder.

## 3. Schéma

Choix le plus simple conforme à R15 : un scan = une ligne, métriques par type dans une colonne JSON figée à l'enregistrement, agrégats du site en colonnes (lus par R18 sans recalcul). Pas de table de lignes.

`mining_sites` : `id` uuid PK ; `owner_id` uuid NOT NULL FK `users` **ON DELETE CASCADE** (index) ; `label` varchar(100) ; `solar_system_id` int NULL (pas de FK SDE, comme `mining_entries`) ; `status` varchar(20) enum ; `created_at` datetime_immutable.

`mining_site_scans` : `id` uuid PK ; `site_id` uuid NOT NULL FK `mining_sites` **ON DELETE CASCADE** ; `scanned_at` datetime_immutable ; **UNIQUE (`site_id`, `scanned_at`)** (R2 aussi garanti en base) ; `total_units` bigint ; `total_volume_m3` float NULL ; `remaining_value` float NULL ; `missing_price_type_ids` json ; `recent_eta`, `trend_eta` datetime_immutable NULL (site) ; `types` json.

`types` : liste d'objets `typeId`, `status` (`present` | `depleted`), `units`, `volumeM3?`, `unitPrice?`, `remainingValue?`, `recentRate?`, `recentEta?`, `trendRate?`, `trendEta?`, `maxDeviationUnits?`, `maxDeviationPercent?`, `etaUnknownReason?`, `depletedBetween?` ({from, to}). Dates ISO 8601 UTC, ETA à la seconde, débits non arrondis. Les entrées `depleted` portent 0 unité ; pour reconstruire les `SiteScanSample` suivants, seules les entrées `present` comptent.

R19 : le cascade en base suffit, `AccountDeletionService` ne change pas (même mécanisme que `mining_operation_recurrences`). R14 : l'API ne reçoit aucun texte libre, rien à masquer dans les logs.

## 4. API (API Platform, `security: is_granted('ROLE_USER')`, `paginationEnabled: false`)

| Opération | Chemin | Détail |
|---|---|---|
| POST | `/mining-sites` | `CreateMiningSiteInput` (label, solarSystemId?) ; 201 |
| GET (collection) | `/mining-sites?solarSystemId=` | R18, récent d'abord (par `created_at`) |
| GET | `/mining-sites/{id}` | site + valeurs du dernier scan enregistré, jamais recalculées |
| POST | `/mining-sites/{id}/close` | `input: EmptyInput::class` (clôture manuelle R17) |
| POST | `/mining-sites/{siteId}/scans` | `RecordMiningSiteScanInput` `{lines:[{typeId, units}]}`, 201, `uriVariables: siteId => Link(fromClass: MiningSiteResource::class)` |
| GET (collection) | `/mining-sites/{siteId}/scans` | chronologique (courbe R16, évolution des ETA), même `Link` |
| GET | `/mining-sites/{siteId}/scans/{id}` | `siteId` et `id` en `Link` (IRI du 201) |

Scoping : chaque provider/processor passe par `MiningSiteRequest::siteOf(..., $user, $id)` ; l'identifiant d'un site d'autrui répond 404, comme un inexistant. La collection ne renvoie que les sites du propriétaire. Pas de DELETE de site (hors spec). Pas de PATCH.

## 5. Tests existants voisins

Aucun test existant n'échoue de façon certaine ; trois sont à compléter ou à surveiller.
- `tests/Integration/Service/Account/AccountDeletionServiceTest::testNoForeignKeyColumnReferencesTheDeletedAccountAndTheOtherAccountKeepsAllItsRows` : `rowsReferencing()` découvre les FK par `information_schema`, donc `mining_sites.owner_id` apparaît seul à 0 avant et après, et le test reste vert. Pour couvrir R19 : persister 1 site avec 1 scan dans `seedPersonalData()` et ajouter `'mining_sites.owner_id' => 1` dans `$seededColumns` (valeur 1 pour Kaelen avant, et pour Brann avant et après, car les deux sont seedés).
- `tests/Integration/Migrations/MigrationsReplayTest` (groupe `slow`) : compare le schéma des migrations au mapping (`SchemaValidator`). Échoue si l'index, la taille de colonne ou le type JSON de la migration diffère des attributs ORM.
- `tests/Unit/ApiResource/MiningOperationSubResourceUriVariablesTest` (liste explicite) et `IndustrySubResourceUriVariablesTest` (glob de dossiers Industry) : ne voient pas `ApiResource/MiningSite`. Ajouter `MiningSiteSubResourceUriVariablesTest` sur `SubResourceUriVariablesDetector` (3 opérations sous `{siteId}`).
- Rien à changer : topics Mercure (`getTopicsForUser`), `SyncSchedulerIntervalsTest`, deptrac (rapport seulement).

## 6. Découpage des tests

| Niveau | Contenu |
|---|---|
| Unit `SiteDepletionEstimatorTest` | un test par exemple E1 à E11 et E13 (12 tests, valeurs de la spec) ; H1 (estimateur appelé sur 1, 2 puis 3 scans) |
| Unit `MiningSiteHistorySummarizerTest` | H5 (20 min, volume `30 000 × volume Veldspar`, valeur 450 000, 600 u/min) |
| Unit | `MiningSiteSubResourceUriVariablesTest` |
| Integration `MiningSiteScanRecorderTest` | persistance des métriques par scan (R15) ; H2 (prix changé, 1er scan inchangé) ; E12 (même seconde : refusé, rien écrit) ; troncature à la seconde ; H4 (dernier type épuisé : `closed`, scan suivant refusé) ; clôture manuelle ; type inconnu du SDE refusé |
| Integration `AccountDeletionMiningSiteTest` | R19 : sites et scans du compte supprimés, ceux d'un autre compte intacts |
| Functional `MiningSiteApiTest` | 1 test par rôle : propriétaire (POST site, POST scans, GET collection, GET scans), autre utilisateur (404 sur GET, scans, close), anonyme (401). H3 : la réponse ne contient aucun champ texte libre |

## 7. Questions de spec ouvertes

1. R18 « débit de tendance moyen » : proposé `(unités du 1er scan − unités du dernier) / durée`, lu sur les colonnes `total_units` ; vs somme des débits de tendance par type. Identiques sur H5.
2. R6 : les zéros des scans suivants entrent-ils dans la régression d'un type épuisé ? Proposé : non, un type épuisé n'a ni débit ni ETA, seulement `depletedBetween`. ETA du site quand tous sont épuisés : inconnue, site `closed`.
3. Type présent, absent, puis présent : non couvert par R6 (« absent de tous les suivants »). Proposé : scans où il est présent seulement (R7).
4. Entrée : `units` ≤ 0, `typeId` en double (somme ou refus ?), liste vide (refus au 1er scan, acceptée ensuite pour épuiser). Proposé : 0 et doublon refusés (422), liste vide autorisée après le 1er scan.
5. Code HTTP d'un scan sur site fermé : le minage utilise `BadRequestHttpException` (400) pour ce type d'état ; R2 reste en 422 (`ValidationException`).
6. R11 dit « une unité compressée = une unité brute » alors que `compressedEquivalentPrice` applique le rapport des `portionSize`. À vérifier avec le prix E1 (15 ISK) sur le SDE réel. Un minerai sans variante compressée a un prix inconnu.
7. R10 : un type au volume inconnu a une valeur au m3 inconnue, donc exclu de la mise en avant.
8. R13 : système optionnel, validé contre `MapSolarSystem` (422 s'il n'existe pas) ; le pré-remplissage dépend du format du collage (hors lot).

## 8. Décisions du thread (mode nuit) sur les questions du 7
1. R18 : débit de tendance moyen = `(unités du 1er scan − unités du dernier) / durée observée`, sur les colonnes `total_units`.
2. R6 : un type épuisé n'a ni débit ni ETA, seulement `depletedBetween` ; ses zéros n'entrent pas dans la régression. Tous épuisés : ETA du site inconnue, site `closed`.
3. Type présent, absent, présent : scans où il est présent seulement (R7), `depletedBetween` seulement s'il reste absent jusqu'au dernier scan.
4. `units` ≤ 0 et `typeId` en double : 422. Liste vide : refusée au 1er scan, acceptée ensuite (elle épuise tous les types).
5. Scan sur site fermé : 400 (`BadRequestHttpException`) ; même horodatage : 422.
6. Prix : `compressedEquivalentPrice` tel quel (R11 de la spec, #259) ; type sans variante compressée = prix inconnu.
7. R10 : volume inconnu = valeur au m³ inconnue, exclu de la mise en avant.
8. Système solaire facultatif, validé contre `MapSolarSystem` (422 si inexistant).
