# Note de câblage #273 : taxe lunaire, lot 1 back

Spec : `docs/specs/273-moon-mining-tax.md`. Hors lot : front, base retraitée, calendrier #274, relances, notifications. Calque : `CorporationMiningExtractionSyncService` (#274), `MiningSiteScanRecorder` (#267). Dossiers plats (ADR-0002) : `src/Service/MoonTax`, `src/ApiResource/MoonTax`, `src/State/{Provider,Processor}/MoonTax`.

## 1. Classes à créer (couche deptrac entre parenthèses)

| Classe | Rôle |
|---|---|
| `MoonTaxPeriodKind` (weekly, monthly), `MoonTaxSyncFailure` (`no_accountant_token`, `reconnect_required`, `esi_error`), `MoonTaxStatus` (T11), `MoonOreRarity` (R4 à R64, `fromGroupId()` 1884/1920/1921/1922/1923) (Enum) | `MiningOreKindResolver::MOON_ORE_GROUP_IDS` est `private` : l'enum porte le groupe vers la catégorie, sans toucher le résolveur (doublon de 5 entiers, à trancher Q3) |
| `MoonTaxPeriod`, `MoonTaxCalculator`, `MoonTaxSettlement`, `MoonTaxReference` (Service, purs) | T7 (bornes UTC, semaine ISO) ; T8 (`quantités`, `array<int,?MoonOreRarity>`, `array<int,?float>` prix, taux, retourne lignes, `?float` taxe, `unknownTypeIds`) ; T10 + T11 (dons, relevés, `now` vers payé, reste, statut ; constante `PAYMENT_GRACE_DAYS`) ; référence `TAX-…` (source aléatoire injectée en `Closure`) |
| `MoonTaxStatementCloser` (Service) | T9 : à la fin d'une sync du ledger réussie, pour chaque raffinerie ouverte et période finie dont `observersSyncedAt >= periodEnd + 1 h` ; calcule, fige, persiste ; collision de référence ou de clé unique : on retente / on saute, jamais de doublon |
| `MoonTaxStatementReader` (Service) | Compose à la lecture : relevés figés + période en cours `provisional` (sans référence) + T10 ; sert la vue mineur et la vue gestionnaire (filtres `period`, `late`) |
| `MoonTaxManagerAccess` (Service) | T3 : voir 3. |
| `MoonTaxAllianceResolver` (Service) | T14 : `getWithCache('/corporations/{id}/')['alliance_id']`, `post('/universe/names/', ids)` ; illisible : `outsideAlliance` `null`, nom `null` |
| `MoonTaxReaderCandidates` (Service/Sync) | Personnages de C, `isAuthValid`, `getEveToken()?->hasAllScopes(...)`, rôle, tri par `getEveCharacterId()` croissant ; réutilisé par les deux flux (même règle que `stationManagerCandidates`) |
| `MoonObserverLedgerSync`, `CorporationWalletJournalSync`, `MoonTaxSyncService` (Service/Sync) | T4, T5, et l'orchestrateur : `SYNC_TYPE = 'moon-tax'`, `sync(int $corporationId): MoonTaxSyncState`, Mercure `syncStarted` puis `syncCompleted`/`syncError` à chaque utilisateur de C (`userIdsOf`), état T6, puis `MoonTaxStatementCloser` |
| `TriggerMoonTaxSync`, `SyncMoonTax(corporationId)` (Message) ; handlers homonymes (MessageHandler) | Le trigger : `SyncTracker::start/complete/fail('moon-tax')`, dispatche une sync par `MoonTaxRefinerySettingRepository::findOpenCorporationIds()` (pas `findAll()` des personnages) |
| 5 entités + 5 repositories (Entity, Repository) | voir 2 ; `CorporationMiningExtractionRepository::existsForRefinery(corporationId, structureId)` pour T2 |
| Resources + Inputs + providers + processors | voir 4 |

## 2. Entités et migration `Version20261105080000` (horodatage réel à la génération)

Modèle : `CorporationMiningSyncState` (UuidType, `bigint` pour les identifiants EVE, `datetime_immutable`, enum en `string`). Argent en `float` (comme `MiningPayment`, `MiningSiteScan`).
- `MoonTaxRefinerySetting` (`moon_tax_refinery_settings`) : `corporation_id`, `structure_id` bigint, `open_to_alliance` bool, `period` string(10) enum, `rate_r4`..`rate_r64` float, `wallet_division` smallint. UNIQUE `(corporation_id, structure_id)`.
- `MoonObserverRecord` (`moon_observer_records`) : `corporation_id`, `observer_id`, `character_id` bigint, `type_id` int, `last_updated` `date_immutable`, `quantity` bigint, `recorded_corporation_id` bigint. UNIQUE `(observer_id, character_id, type_id, last_updated)` ; index `(corporation_id, last_updated)`.
- `CorporationWalletDonation` (`corporation_wallet_donations`) : `corporation_id`, `journal_id`, `first_party_id` bigint, `date`, `amount` float, `reason` text null, `attributed_character_id` bigint null, `attributed_by_user_id` uuid null FK `users` ON DELETE SET NULL, `attributed_at` null. UNIQUE `(corporation_id, journal_id)` ; index `(corporation_id, first_party_id)`.
- `MoonTaxSyncState` (`moon_tax_sync_states`) : `corporation_id` bigint unique, `observers_synced_at`, `wallet_synced_at` null, `observers_failure`, `wallet_failure` string(32) null enum.
- `MoonTaxStatement` (`moon_tax_statements`) : `corporation_id`, `structure_id`, `character_id` bigint, `period_start`, `period_end`, `reference` string(24) UNIQUE, `lines` json, `tax` float null, `unknown_type_ids` json, `recorded_corporation_id` bigint null, `outside_alliance` bool null, `marked_paid_at`, `cancelled_at` null, `acted_by_user_id` uuid null FK SET NULL, `created_at`. UNIQUE `(corporation_id, structure_id, period_start, character_id)` ; index `(corporation_id, period_start)`.
Aucune FK vers `users` en cascade : les relevés visent des `character_id` EVE. Les FK `SET NULL` apparaissent à 0 dans `AccountDeletionServiceTest` (voir 5).

## 3. Câblage

- **Sync** : `getPaginated()` aux deux listes d'observers et au journal (`/corporation/{C}/mining/observers/`, `/corporations/{C}/wallets/{d}/journal/`) ; échec `EsiApiException|EveAuthRequiredException` : candidat suivant, comme `synchronize()`. Réponse illisible : `esi_error`, rien d'écrit (valider toute la liste avant `persist`, `readableExtractions()`). `reconnect_required` : aucun candidat valide mais un personnage de C à token invalide ou scope manquant.
- **Scheduler** : `RecurringMessage::every('1 hour', new TriggerMoonTaxSync())` dans `SyncScheduler` (deptrac Scheduler vers Message ok). `config/packages/messenger.yaml` : `TriggerMoonTaxSync: async`, `SyncMoonTax: async`. `SyncTracker::EXPECTED_INTERVALS['moon-tax'] = 3600` et `LABELS['moon-tax']` (sinon `self::LABELS[$type]` plante). `CLAUDE.md` : ligne du tableau Scheduler.
- **Mercure** : `getTopicsForUser()` gagne `sprintf('/user/%s/sync/moon-tax', $userId)`. Publication par `syncStarted/syncCompleted/syncError` existants, sans nouvelle méthode.
- **Scope** : `AuthenticationService::REQUIRED_SCOPES` + `'esi-wallet.read_corporation_wallets.v1'` (bloc Wallet) ; 27 devient 28 dans `CLAUDE.md:40`, `README.md:60`, `.claude/skills/esi-api/SKILL.md:773` (+ ligne du tableau §10). Le scope lecteur du ledger : `esi-industry.read_corporation_mining.v1` + `esi-characters.read_corporation_roles.v1`.
- **Accès gestionnaire** : aucun Voter dans le dépôt (`src/Security` n'a que JWT/admin) ; la règle d'accès vit en `Service` (modèle `MiningOperationAccess`, qui lève `AccessDeniedHttpException`). `MoonTaxManagerAccess::assertManages(User, int $corporationId)` : pour chaque personnage de C, `CharacterService::getCharacterRoles()` (appel ESI non caché, relu à chaque requête) ; `Accountant` trouvé : accès ; sinon 403 listant nom et rôles lus ; `EsiApiException` sans accès accordé : `ServiceUnavailableHttpException` (503, modèle `StructureSearchProvider`). Appelée en tête de chaque provider/processor `{corporationId}` ; la vue mineur (`/mine`) ne l'appelle pas.
- **Prix** : réutiliser `MiningSiteUnitPrices::read(list<int>)` (#267), déjà `getBatchOrePrices($ids, null, 1.0, 0.0, MarketSide::Buy)['compressedEquivalentPrice']` ; Service vers Service, permis. Clé absente ou `null` : prix inconnu (T8).
- **SDE** : `Sde\InvTypeRepository::findByTypeIds()` puis `getGroup()->getGroupId()` pour la catégorie (type absent du SDE : inconnu).
- **deptrac** (`deptrac.yaml`, rapport seulement) : Service autorise Entity, Repository, Message, Enum ; StateProcessor autorise Service/Message. Seul ajout probable : `skip_violations` si un processor importe un mapper de provider (modèle `MiningSite`).

## 4. API Platform (`ROLE_USER`, `paginationEnabled: false`)

`standard_put: true` et `formats` globaux `jsonld`/`json` (`config/packages/api_platform.yaml`), pas de CSV. Ressources, chacune avec `uriVariables` en `Link` :
- `MoonTaxCorporationResource` (parent des `Link`, identifiant `corporationId` requis `\d+` pour ne pas capter `/mine`) : `GET /mining/moon-tax/{corporationId}` (état T6 `access` par flux, raffineries réglées).
- `MoonTaxStatementResource` : `GetCollection …/{corporationId}/statements` (filtres `period`, `late` lus dans `$context['filters']`) ; `POST …/statements/{id}/mark-paid` et `…/cancel` en `input: EmptyInput::class`, `id` en `Link(fromClass: self::class)`. CSV : `formats: ['json' => ['application/json'], 'csv' => ['text/csv']]` sur cette seule opération (encodeur CSV du Serializer, aucune dépendance), colonnes plates (`unknownTypeIds` en texte joint).
- `MoonTaxMineResource` : `Get /mining/moon-tax/mine` (provider seul).
- `MoonTaxRefineryResource` : `PUT …/{corporationId}/refineries/{structureId}` + `MoonTaxRefineryInput` (Assert Range 0-100, division 1-7, 422 si raffinerie inconnue) ; fournir un provider (lecture de l'item avant le PUT).
- `MoonTaxSyncResource` : `POST …/{corporationId}/sync`, `EmptyInput`, dispatche `SyncMoonTax`.
- `MoonTaxDonationResource` : `POST …/donations/{journalId}/attribute` + `AttributeDonationInput{characterId}` ; `journalId` en `Link`.
Test d'URI : ajouter `MoonTaxSubResourceUriVariablesTest` (modèle `MiningSiteSubResourceUriVariablesTest` sur `SubResourceUriVariablesDetector`).

## 5. Dépendances et harnais

| Dépendance | Harnais |
|---|---|
| Calculs purs, période, référence | Unit : littéraux E1 à E11, aucun double ; clé aléatoire passée en `Closure` fixe |
| `EsiClient` (observers, journal, rôles, corporations, `/universe/names/`) | Frontière : `createStub(EsiClient)` avec `get`, `getPaginated`, `getWithCache`, `post` ; calque `CorporationMiningExtractionHarness` (trait `answerEsi`, `failEsi`, jeton par personnage). Rôles : `esiResponses['/characters/{id}/roles/']`. Functional : `getContainer()->set(EsiClient::class, $stub)` (modèle `MiningOperationLedgerApiTest:539`) |
| `CharacterService` | réel, construit sur le stub ESI (comme le harnais #274) |
| Prix | Integration/Functional : `SeedsMiningSiteMarket` (stub `JitaMarketService::getBuyPrices`, types brut et `Compressed` de même `portionSize`, `InvGroup` 1884..1923 à ajouter) ; Unit : tableau `array<int,?float>` |
| Horloge | `MockClock` (Unit), `ClockSensitiveTrait::mockTime()` (Integration/Functional) ; E7 et E11 à la minute |
| Base, utilisateur | `IntegrationTestCase` ; Functional `WebTestCase`, `loginUser` ; seed `corporation_mining_extractions` pour T2 |
| Mercure, bus | stub `HubInterface` et `MessageBusInterface` du harnais #274 ; `SyncTracker` sur `ArrayAdapter` |

Fake aux frontières seulement : jamais de mock du calculateur, du règlement ni du closer.

## 6. Tests existants qui casseront (changement voulu)

- `tests/Unit/Service/Mercure/MercurePublisherServiceTest` : `testGetTopicsForUserReturnsAllExpectedTopics` (liste exacte, `assertSame`) et `…ReturnsCorrectCount` (`assertCount(18)` vers 19).
- `tests/Unit/Scheduler/SyncSchedulerIntervalsTest` : `SYNC_TRACKER_KEY_BY_SCHEDULED_MESSAGE` (assertArrayHasKey sur le nouveau message), `testScheduledSyncIntervalsInSeconds` (+ `'moon-tax' => 3600`), et l'égalité avec `EXPECTED_INTERVALS`.
- `tests/Unit/Scheduler/SyncSchedulerMissedRunsTest` : liste ordonnée des messages manqués après 5 h (nouveau message horaire, position selon l'ordre d'ajout dans `SyncScheduler`). `SyncSchedulerLockTest` : sa liste ne porte que les messages de 30 min, inchangé.
- `tests/Unit/Service/Admin/SyncTrackerTest`, `DataFreshnessProviderTest`, `DataFreshnessApiTest` : lisent `EXPECTED_INTERVALS` ; à relire, casse seulement si l'ordre ou la clé est supposé.
- Scopes : aucun test ne compte les 27 scopes (`AuthenticationServiceTest`, `ScheduledCharacterSyncFanOutTest` ne listent que des scopes isolés). Rien ne casse ; ajouter un test qui fige le nouveau scope dans `getRequiredScopes()` et dans l'URL d'autorisation.
- `tests/Integration/Service/Account/AccountDeletionServiceTest` : reste vert (FK `SET NULL` à 0) ; y seeder un relevé avec `acted_by_user_id` pour couvrir le RGPD. `MigrationsReplayTest` (groupe `slow`) : échoue si migration et mapping divergent.
- Ajouter `MoonTaxMessengerRoutingTest` (modèle `CorporationMiningMessengerRoutingTest`).

## 7. Questions ouvertes

1. 403 gestionnaire : `AccessDeniedHttpException` ne porte qu'un message ; les rôles lus par personnage passent dans le texte (proposé) ou dans une exception dédiée.
2. Personnage de C au token invalide ou sans scope des rôles : ignoré pour T3 (rôles `[]`), le 503 ne sert que si un lecteur éligible échoue sur l'ESI. `getCharacterRoles()` rend `[]` quand `roles` manque : un 403 trompeur est possible.
3. Rareté : enum `MoonOreRarity` (doublon de 5 ids avec `MOON_ORE_GROUP_IDS` privé) ou rendre la constante publique.
4. `recordedCorporationId` unique par relevé : un mineur qui a miné pour deux corporations enregistrées la même période (une ligne par `recorded_corporation_id` dans la clé de T4 ?) ; proposé : relevé avec la valeur la plus fréquente, à confirmer.
5. CSV : colonne `unknownTypeIds` en texte joint ; réponse d'erreur avec `Accept: text/csv` à vérifier (formats d'erreur).
6. Ajouter au glossaire (`docs/glossary.md:222`) : relevé de taxe lunaire, raffinerie ouverte, période de taxe.

## 8. Décisions du thread (mode nuit, 2026-11-04)

1. 403 gestionnaire : message texte listant, par personnage de C, nom et rôles lus.
2. Personnage au token invalide ou sans scope : ignoré pour T3 ; 503 seulement si un lecteur éligible échoue sur l'ESI et qu'aucun autre n'accorde l'accès.
3. Rareté : enum `MoonOreRarity`, `MOON_ORE_GROUP_IDS` reste privé (doublon de 5 entiers assumé, aucun changement du résolveur).
4. `recordedCorporationId` d'un relevé : celui de la ligne de ledger la plus récente (`lastUpdated`) de la période pour ce personnage et cette raffinerie.
5. CSV : colonne `unknownTypeIds` en texte joint par `;` ; erreurs en JSON.
6. Glossaire (relevé de taxe lunaire, raffinerie ouverte, période de taxe) : ajouté par le thread après le vert.
7. Bornes d'entrée obligatoires (leçon #267) : `characterId` entier > 0, `rate*` 0 à 100, `walletDivision` 1 à 7, liste de réponse ESI traitée en entier ; insertions concurrentes (sync simultanées, clôture de période) : unique + jamais de doublon, jamais de 500 sur l'UNIQUE.
