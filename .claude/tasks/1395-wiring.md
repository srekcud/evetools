# #1395 — câblage (onglet Stock : grille de couverture, liste d'achat, `GET /planetary/stock`)

Spec : `docs/specs/1395-pi-stock-coverage.md`. Base vérifiée : 85f23b42a.

## Hypothèses de la spec à corriger avant le rouge

- **Multibuy** : le séparateur existant est une tabulation (`frontend/src/utils/multibuy.ts:2`, `${typeName}\t${quantity}`), réutilisé par `MaterialSourcing.vue:46` et `MultibuyBridgeActions.vue:24`. Réutiliser `multibuyText` et attendre `Robotics\t1001\nPlasma\t600` (EVE accepte les deux), pas l'espace de l'exemple.
- **« inconnu »** : `UnknownValue.vue:13` rend `—` avec `title` = `common.unknownValue`, pas le mot « inconnu ». La Décision sans choix affiche aussi `—` : les tests distinguent par `findComponent(UnknownValue)`, jamais par le texte.
- **Conteneur corpo dans un bureau d'une structure non corpo** : `AssetStations::locationOf()` rend null (`src/Service/Industry/Project/AssetStations.php:58`), donc `station_unresolved` (`PersonalStockReader.php:132-134`) et, par la règle 5, `corp` null sur toute la grille. Cas courant si la maison est une structure d'alliance. Comportement #1324 conservé ; à noter sur l'issue.
- **Volume** : `weekly[]` ne porte pas `volumePerUnit` (`PlanetaryInputNeedsCalculator.php:341-350`). Le lecteur le lit par `InvTypeRepository::findByTypeIds()` (une requête), `InvType::getVolume()` null reste null.
- **`complete` de #1380** : faux quand un schéma manque (`PlanetaryInputNeedsCalculator.php:158-160`), et les entrées de cette usine sont absentes de `weekly`. La règle 9 l'ignore : proposé de l'inclure (`complete = needs.complete && …`).
- **Tri** : `typeName` peut être null (`TypeNameResolver::findName`, calculateur l.343) ; proposé : nom null en dernier, puis `typeId`.

## Classes proposées

| Couche deptrac | Classe | Rôle |
|---|---|---|
| Service | `Planetary/Stock/PlanetaryStockReadings` (VO) | `list<PlanetaryWeeklyNeed{typeId, ?typeName, ?weeklyNetNeed, ?volumePerUnit}>`, `?int homeLocationId`, `int hubStructureId`, `int coverageWeeks`, `array<int locationId, array<int typeId,int>> quantitiesByRootLocation`, `list<PersonalStockContainerContents> corporationContainers`, `list<PlanetaryBuyOrder{typeId, locationId, volumeRemain}> buyOrders`, `bool needsComplete` |
| Service | `Planetary/Stock/PlanetaryStockCalculator` (pur, constructeur vide) | `calculate(PlanetaryStockReadings): PlanetaryStock` — règles 2 à 9, hub == maison, filtre des ordres par hub, corp par `reading->stationId === home` |
| Service | `PlanetaryStock{rows, ?corpUnknownReason, complete}`, `PlanetaryStockRow` (VO de sortie) | champs de la règle 2 |
| Service | `Planetary/Stock/PlanetaryStockReader` | `read(User, PlanetaryStockSettings): PlanetaryStock` : colonies → calculateur #1380 → `weekly`, SDE, assets par personnage, conteneurs, ordres ; appelle le calculateur pur |
| Service | `PlanetaryStockSettings` (VO) | `?homeLocationId`, `hubStructureId`, `coverageWeeks` |
| StateProvider | `Planetary/PlanetaryStockProvider` | `UnauthorizedHttpException` (modèle `PlanetarySettingsProvider.php:28-31`), `PlanetarySettingsResource::forUser($user, $defaultMarketStructureId, $defaultMarketStructureName)` (binds `config/services.yaml:15-17`) → `PlanetaryStockSettings`, mapping VO → ressource (noms maison et hub repris de `forUser`) |
| ApiResource | `Planetary/PlanetaryStockResource` | `Get /planetary/stock`, id `stock`, `ROLE_USER`, `normalizationContext: ['skip_null_values' => false]` (modèle `PlanetarySettingsResource.php:16-36`), `rows` en `list<array<string,mixed>>` |
| Repository | `AssetBrowseRepository::quantitiesByRootLocation()` (ajout) | voir plus bas |
| Service (touché) | `PersonalStockReader::containerContentsOf(User): list<PersonalStockContainerContents>` + VO `PersonalStockContainerContents{reading, articles}` | voir plus bas |

Le hub est calculé une seule fois dans `PlanetarySettingsResource::forUser` (l.55-74) ; `Service` ne peut pas importer `ApiResource` (`deptrac.yaml:112`), d'où le VO de réglages passé par le provider. `Service → Service` (Industry\Stock) est interne à la couche. Aucun `skip_violations`.

## Stock par lieu racine (`AssetBrowseRepository`)

`public function quantitiesByRootLocation(AssetScope $scope, list<int> $rootLocationIds, list<int> $typeIds): array<int, array<int, int>>` ; listes vides → `[]` sans SQL. SQL : `treeCte($scope)` (l.217-245) puis `SELECT root_location_id, type_id, SUM(quantity) AS quantity FROM tree WHERE root_location_id IN (:rootLocationIds) AND type_id IN (:typeIds) GROUP BY root_location_id, type_id`. Liaisons : `bindings($scope)` (l.286) puis ajout des deux listes en `ArrayParameterType::INTEGER` (le paramètre `$extra` est typé `int|string`). Casts `(int)` sur les trois colonnes (SUM rend un bigint).

Vérifié : les enfants sont suivis par `c.location_type = 'item' AND c.location_id = tree.item_id` jusqu'à `MAX_CONTAINER_DEPTH` = 5 (l.15, 242-243) ; un asset est racine si `location_type <> 'item'` ou si son parent n'est pas un asset du même personnage (`rootSql`, l.270-279) : station (`station`), structure (`item`, id de structure absent des assets), espace (`solar_system`, id de système, jamais égal à une station). Le contenu d'un vaisseau à quai compte aussi au lieu (même règle que `summarizeLocations`, qui fournit les lieux candidats #1394). Filtre de types hors CTE : le conteneur lui-même n'est pas un type PI. Appel une fois par personnage (`AssetScope::forCharacter($character->getId()->toRfc4122())`, comme `PlanetaryHomeLocationCatalog.php:23-25`), somme dans le lecteur. Ne pas utiliser `PersonalStock::locationsByTypeId` : il groupe par `a.location_id`, donc par conteneur (`CachedAssetRepository.php:402-407`).

Test : `tests/Integration/Repository/AssetBrowseRepositoryTest.php` (helpers `personal()`, `persistSde()`), `#[Group('issue-1395')]` : maison avec un item direct, un conteneur, un sous-conteneur ; un item dans un conteneur d'un autre lieu ; un item d'un autre personnage ; un type hors liste.

## Colonne corp (`PersonalStockReader`)

`of()` fusionne hangars et conteneurs (l.30-50) : inutilisable. Ajout non régressif : `containerContentsOf(User)` = `readingsOf($user)` (l.63) mappé en `PersonalStockContainerContents(reading, articles)`. Une lecture connue porte `stationId` = station résolue (l.145) ; inconnue : `status = unknown`, `unknownReason` (`station_unresolved`, `container_not_found`, `container_in_hidden_division`, `corporation_assets_stale`, `owner_without_corporation`, `no_director`…) et `articles` vide. Le calculateur : première lecture inconnue dans l'ordre des noms → `corpUnknownReason`, `corp` null partout (même ordre que `PersonalStock::unknownReason()`, l.29-38) ; sinon somme des `articles` des lectures dont `stationId === homeLocationId` ; aucune déclaration → 0. `of()` et `containersOf()` inchangés : les 23 tests de `tests/Unit/Service/Industry/Stock/PersonalStockReaderTest.php` et `StubsPersonalStockReader` restent verts.

## Fourniture au harnais

| Dépendance | Harnais |
|---|---|
| `PlanetaryStockCalculator` | aucun double : tous les exemples chiffrés sauf « conteneur de la station maison » (intégration) et « autre utilisateur » (fonctionnel) dans `tests/Unit/Service/Planetary/Stock/PlanetaryStockCalculatorTest.php`, VO construits à la main, `PersonalStockContainerReading` direct |
| `PlanetaryColonyRepository::findByUser`, `InvTypeRepository::findByTypeIds`, `CachedCharacterBuyOrderRepository::findByCharactersAndTypes`, `AssetBrowseRepository` | `createStub` (classes non finales) |
| `PlanetaryInputNeedsCalculator` | `createStub` (classe non finale, `calculate()` renvoie un tableau `weekly` écrit à la main) |
| `PersonalStockReader` (`final readonly`) | construit sur stubs, trait `tests/Unit/Service/Industry/Stock/StagesCorporationAssets` ou `StubsPersonalStockReader` (aucune déclaration) |
| Bout en bout | `tests/Functional/Api/Planetary/PlanetaryStockApiTest.php` (modèle `PlanetaryInputNeedsApiTest`) : 401, isolation entre deux utilisateurs, une ligne complète ; `make test-db` |

Tests existants appelés à casser : `frontend/tests/components/planetary/piStockSettings.spec.ts:135` (liste exacte des appels : `GET /planetary/stock` s'ajoute) ; tous les montages de `mountStockTab` reçoivent une erreur `Unexpected request GET /planetary/stock` (`frontend/tests/planetarySettingsFixtures.ts:93`) : ajouter la route dans `serve`, réponses dans un nouveau `frontend/tests/planetaryStockFixtures.ts`. `PlanetaryInteractionPlannerTab.spec.ts:79` stubbe `PiStockTab` : intact. Aucun backend cassé (ajouts seuls, calculateur #1380 non modifié).

## Front

- Store `planetaryStock` : `load()`, `stock` (en-tête), `rows`, `purchaseList` (computed, `toBuy > 0`), `purchaseVolume` (null si un volume l'est), `isLoading`, `error`.
- Rechargement : `planetarySettings` gagne `saveRevision` incrémenté après un PATCH réussi (`patchSettings` l.86, `saveHub` l.111) ; `PiStockTab` charge au montage et `watch(saveRevision)`. Un `watch(settings)` rechargerait aussi au `load()` de `PiStockSettings` (l.66), donc deux GET.
- Composants : `PiStockBanner`, `PiStockCoverageGrid` (classes de `PiProductionTable.vue:40-51`), `PiStockPurchaseList` (`multibuyText` + `useCopyFeedback`, pas `MultibuyBridgeActions` qui exige le routeur et ajoute « Évaluer »).
- Décision : `usePlanetaryPlannerStore().choices[typeId]` (`'produce' | 'buy'`, `utils/planetaryPlanner.ts:4`). Lien : la grille émet `open-planner`, `PiStockTab` le relaie, `PlanetaryInteraction.vue:160` fait `activeTab = 'planner'` (`useTabQuery` met `?tab`). Pas de `RouterLink` : `piStockSettings.spec` monte sans routeur.
- Motifs corpo : réutiliser `industryFactory.personalStock.unknownReason.*` (FR et EN existants).
- Clés à créer (FR, EN) sous `pi.stock` : `banner.noHome`, `banner.goToSettings`, `banner.corpUnknown`, `banner.consumptionUnknown`, `grid.title`, `grid.type`, `grid.home`, `grid.hub`, `grid.corp`, `grid.weeklyConsumption`, `grid.coverage`, `grid.weeksValue`, `grid.openOrders`, `grid.coverageWithOrders`, `grid.toBuy`, `grid.decision`, `grid.produce`, `grid.buy`, `grid.openPlanner`, `purchase.title`, `purchase.totalVolume`, `purchase.copy`, `purchase.copied`, `purchase.copyFailed`, `purchase.empty`, `loadError`.

## Pièges

- `ceil` flottant : `ceil(round($deficit, 6))` (constante `QUANTITY_DECIMALS = 6`) : 333,4 × 3 = 1 000,2 → 1 001, et un 1 000,000000001 issu de `86400 / cycle × 7` → 1 000.
- Couverture : `weeklyConsumption` null ou `<= 0.0` → null avant la division ; `toBuy` 0 seulement si conso == 0.0.
- Hub == maison : `hub` 0 (règle 4) mais `openOrders` lus au hub.
- N+1 : une requête d'assets par personnage, une SDE, une pour les ordres ; `readingsOf` charge les assets corpo visibles une fois.
- bigint : `locationId` d'ordre typé `int` (entité l.35) ; résultats DBAL castés `(int)`.
- Pas de `ClockInterface` ici ; celle du calculateur #1380 suffit (fonctionnel : `ClockSensitiveTrait` si extracteur avec `expiryTime`).
- Accès : aucun identifiant d'URI, tout part de `Security::getUser()` et `User::getCharacters()`.

## Questions de spec ouvertes

- Une lecture corpo inconnue hors maison (`stationId` déclaré ≠ maison) doit-elle masquer la colonne ? Spec : oui ; alternative : seulement si `stationId` est la maison ou null.
- Sans lieu maison, `corp` vaut 0 (aucun conteneur « au lieu maison ») sauf lecture inconnue : à confirmer.
