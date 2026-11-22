# #1293 — Read the project containers station by station

Circuit `m`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype. Back-end seulement : l'écran qui montre « sur place » par station est la page en flux (#1307) ; la ventilation des lignes de BOM par station est #1294.

## Contexte

Depuis #1292 un container de projet porte un lieu (`IndustryProjectContainer::$locationId`, station du plan). La lecture actuelle (`ProjectContainerService::containerStockOf`, `containerScopeOf`, `verifyContainer`) retrouve les containers par nom, toutes stations confondues, et additionne tout : elle ne dit pas ce qui est sur place à chaque station. Le flux a besoin d'une lecture par container et par station, qui distingue « vide » de « inconnu ».

Dans les assets corporation, un objet rangé dans un hangar a pour `locationId` l'item « bureau » de la corporation (`locationFlag` `CorpSAG1..7`, `locationType` `item`) ; l'item bureau (`locationFlag` `OfficeFolder`, `EveConstants::OFFICE_LOCATION_FLAG`) a pour `locationId` la station ou la structure. Les assets du bureau sont en cache mais `findByCorporationAndDivisions` ne les lit pas (seulement les `CorpSAG`).

## Règles

R1. **Une lecture par container de projet.** `ProjectContainerReadings::of(IndustryProject)` renvoie une `ProjectContainerReading` par `IndustryProject::getContainers()` (ordre du nom, comparaison `keyOf`), jamais une lecture globale du projet. Champs : `containerId`, `containerName`, `locationId` (celui du container, `null` sans lieu), `status` (`known` | `unknown`), `unknownReason` (`null` si `known`), `contents` (`list<{typeId, typeName: ?string, quantity}>`, `null` si `unknown`), `assetsSyncedAt` (`lastCachedAtOfCorporationAssets`, `null` si jamais synchronisé).

R2. **Lu = contenu du container à son lieu.** Pour un container avec lieu L : les assets visibles du propriétaire (`VisibleCorporationAssetsFinder::findVisibleAssets($corporationId, null, $owner)`) dont le nom (`keyOf`) est celui du container **et** dont la station résolue (R4) est L. Contenu = tout ce qui est rangé dedans, sous-containers compris, chaque asset compté une fois ; un asset qui est lui-même un container non vide n'est pas compté comme article (même règle que `containerStockOf`). Plusieurs assets de même nom à L (autres divisions) s'additionnent (décision #801). Un container trouvé et vide est `known` avec `contents = []` : seule lecture où zéro est une valeur.

R3. **Jamais zéro quand on ne sait pas.** Tout cas ci-dessous est `unknown`, `contents = null`, avec sa raison :
- `owner_without_corporation` : propriétaire sans corporation ;
- `no_director`, `corporation_assets_not_synced`, `no_visible_division` : raisons de `ProjectContainerService::unavailabilityReason`, reprises telles quelles ;
- `container_not_found` : aucun asset visible de ce nom ;
- `container_in_hidden_division` : un asset de ce nom existe dans une division que le propriétaire ne voit pas (règle de `notFoundStatusOf`) ;
- `corporation_assets_stale` : assets corporation plus vieux que 48 h et container introuvable (règle de `notFoundStatusOf`) ;
- `container_at_another_station` : des assets visibles portent ce nom mais aucun à L (leur station est résolue et différente) ;
- `station_unresolved` : au moins un asset visible porte ce nom, aucun n'a une station résolue égale à L, et la station d'au moins un n'a pas pu être résolue (R4). Priorité : un asset résolu égal à L donne `known` même si un autre de même nom n'est pas résolu.

R4. **Station d'un asset.** Remonter la chaîne `locationId` parmi les assets visibles tant que `locationType` est `item` et que le parent est visible ; le dernier maillon visible a pour `locationId` l'item bureau, cherché dans les assets corporation (une seule requête pour tous les bureaux : `CachedAssetRepository::findCorporationByItemIds`). La station = `locationId` de l'item bureau, si cet item existe et que son `locationType` n'est pas `item`. Chaîne cyclique, bureau absent du cache ou lui-même rangé dans un item : station non résolue (jamais une valeur par défaut, jamais le `locationId` du hangar pris pour une station).

R5. **Container sans lieu.** Lu par son nom seul, toutes stations confondues (comportement de #1292 R1 et de #801), sans résolution de station : `known` si trouvé, sinon `container_not_found`, `container_in_hidden_division` ou `corporation_assets_stale` ; `locationId = null` dans la lecture. Disparaît avec la migration #1320.

R6. **Lecture seule, sans effet de bord.** Une lecture ne modifie rien, n'appelle pas l'ESI (cache d'assets seulement), ne publie rien sur Mercure (aucune action asynchrone) et ne touche pas aux appelants existants : `containerStockOf`, `containerScopeOf`, `verifyContainer`, `ContainerDeliveryConfirmer`, `IntermediateStockProposer`, `T2BlueprintCopyProposer`, `ProjectJobLocationResolver` gardent leur comportement et leurs tests (leur bascule sur la lecture par station est #1294 et #1166/#1167/#1168).

R7. **Ressource.** `GET /industry/projects/{projectId}/container-readings` (`ProjectContainerReadingResource`, `Link` vers `IndustryProjectResource`, `paginationEnabled: false`, `skip_null_values: false`) : une entrée par lecture (R1), `contents` sérialisé en `items: [{typeId, typeName, quantity}]` ou `null`. Calculée à chaque lecture, rien de persisté.

## Exemples (tests rouges)

Projet « Ishtar », propriétaire de la corporation C, division 2 visible, assets corporation synchronisés, director connecté ; bureau B (`OfficeFolder`) à la station A (100) ; container « Ishtar A » en hangar de B (flag `CorpSAG2`).

| Containers du projet | Assets | Lecture |
|---|---|---|
| « Ishtar A » à 100 | « Ishtar A » en hangar de B (station 100) contenant 50 Tritanium + 10 Pyerite | `known`, 2 articles, `locationId` 100 |
| « Ishtar A » à 100 | « Ishtar A » vide | `known`, `contents = []` |
| « Ishtar A » à 100 | « Ishtar A » contient un sous-container « Vrac » avec 5 Zydrine | `known`, 5 Zydrine, le sous-container n'est pas un article |
| « Ishtar A » à 100 | « Ishtar A » en hangar d'un bureau B2 à la station 200 | `unknown`, `container_at_another_station`, `contents` null |
| « Ishtar A » à 100 | « Ishtar A » en hangar de B (station 100) et un autre « Ishtar A » en B2 (200) | `known`, seul le contenu de celui de 100 |
| « Ishtar A » à 100 | deux « Ishtar A » en B (100), divisions 2 et 3 visibles, 10 + 5 Tritanium | `known`, 15 Tritanium |
| « Ishtar A » à 100 | « Ishtar A » dont le bureau n'est pas en cache | `unknown`, `station_unresolved` |
| « Ishtar A » à 100 | « Ishtar A » en B (100) et un autre « Ishtar A » au bureau non résolu | `known` (celui de 100) |
| « Ishtar A » à 100 | aucun asset de ce nom | `unknown`, `container_not_found` |
| « Ishtar A » à 100 | « Ishtar A » en division 5, non visible du propriétaire | `unknown`, `container_in_hidden_division` |
| « Ishtar A » à 100 | assets plus vieux que 48 h, introuvable | `unknown`, `corporation_assets_stale` |
| « Ishtar A » à 100 | propriétaire sans corporation / director absent / assets jamais synchronisés / aucune division visible | `unknown`, `owner_without_corporation` / `no_director` / `corporation_assets_not_synced` / `no_visible_division` |
| « Ishtar A » à 100 et « Ishtar B » à 200 | chacun à son bureau | 2 lectures `known`, chacune son contenu |
| « Ishtar A » à 100 et « Vrac » sans lieu | « Vrac » dans B (100) avec 3 Pyerite | 2 lectures ; « Vrac » `known`, 3 Pyerite, `locationId` null |
| aucun container | | liste vide |

API : membre accepté → 200 avec la liste ; utilisateur étranger au projet → 403 ; projet inexistant ou uuid invalide → 404 ; non authentifié → 401.

## Cas limites

- `typeName` absent d'un asset : reste `null` dans l'article, jamais remplacé par un texte.
- Quantités additionnées en entier ; un article de quantité 0 n'existe pas.
- Deux conteneurs du projet n'ont jamais le même lieu (index #1292) ; deux conteneurs de même nom sont exclus par l'index sur le nom : une lecture ne double jamais le contenu d'un asset.
- Chaîne de rangement plus profonde que `CachedAssetRepository::MAX_CONTAINER_DEPTH` : le contenu au-delà n'est pas lu, comme aujourd'hui.

## Accès et fraîcheur

- Lecture : tout membre accepté du projet (`ProjectAccessChecker::assertAcceptedMember`), comme `container-verification` et `container-stations`. Les assets lus sont ceux de la corporation du **propriétaire** du projet, filtrés par les divisions visibles pour le propriétaire (`CorpAssetVisibility::divisionsVisibleTo($owner)`), donc un participant d'une autre corporation voit le contenu des containers de projet de la corporation du propriétaire : comportement existant de `container-verification`, repris ici sans changement.
- Fraîcheur : cache d'assets corporation (sync à la demande ou admin, pas de tâche planifiée) ; `assetsSyncedAt` est rendu pour que l'écran dise l'âge de la lecture. `Character.corporationId` du propriétaire se rafraîchit à sa connexion : un propriétaire qui a changé de corporation est lu dans sa dernière corporation connue (défaut préexistant, non traité ici).
- Les ids de container et de projet viennent de l'URL et du projet, jamais d'un corps de requête.

## Tests existants modifiés

Aucun attendu : `ProjectContainerService` garde son constructeur ; la logique de parcours des containers est extraite en méthodes publiques ou dans une classe voisine sans changer ses sorties (`ProjectContainerServiceTest`, `ProjectContainerServiceContainerStockTest` restent verts sans toucher une assertion).

## Hors périmètre

Ventilation des lignes de BOM par station (#1294) ; stock perso en division corporation (#1324) ; bascule des appelants existants ; création ou renommage des containers (#1292) ; migration des projets (#1320) ; écran (#1307) ; assets personnels (un container de projet est un container corporation, comme dans `ProjectContainerService`).

## Glossaire

Ajout : **Lecture d'un container** (Container reading), station d'un asset. Le terme **Container de projet** reste inchangé.

## Décisions

D1. Back-end seulement : le service et la ressource de lecture ; l'écran est #1307.
D2. Une lecture par container de projet (pas par station) : un container a au plus un lieu, et la station sans container du plan n'a rien à lire.
D3. Station d'un asset via l'item bureau (flag `OfficeFolder`) : c'est le seul lien entre un hangar corporation et la station dans le cache d'assets.
D4. Les appelants existants ne basculent pas ici : changer leur sémantique (par station, `unknown` au lieu de zéro) change des montants affichés, ce que portent #1294 et les écrans.
D5. Container sans lieu lu par nom seul jusqu'à #1320 (décision #801 conservée).
