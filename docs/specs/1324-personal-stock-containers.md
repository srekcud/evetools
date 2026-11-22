# #1324 — Personal stock containers in corporation divisions count as my stock

Circuit `m`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et de la spec #1293. L'écran de réglage est la page Usine et stock (#1314) ; ici le modèle, le lecteur unique et l'API.

## Contexte

« Mon stock » ne lit que les hangars perso (`CachedAssetRepository::getAggregatedQuantitiesByUser` et `getQuantitiesByUserWithLocations`, `is_corporation_asset = false`). Les joueurs rangent aussi leur stock dans des containers des divisions corporation. Trois stocks, jamais mélangés : **container de projet** (#1292, #1293), **stock perso** (hangars perso + containers déclarés comme miens), **stock corpo** (le reste des divisions visibles, jamais compté comme mien).

## Règles

R1. **Déclaration.** `PersonalStockContainer` (table `personal_stock_containers`) : utilisateur, `itemId` (bigint, **unique globalement** : un container appartient à un utilisateur au plus), `name`, `divisionName` (nullable), `stationId` et `stationName` (nullables), `createdAt`. Nom, division et station sont l'instantané pris à la déclaration et ne servent qu'à l'affichage d'un container devenu introuvable ; la lecture (R3) relit toujours les assets. Station non résolue à la déclaration : `stationId` et `stationName` `null`, la déclaration reste valide.

R2. **API** (sous les réglages industrie, `PersonalStockContainerResource`, identifiant = uuid de la déclaration ; `PersonalStockContainerDeclarer::declare(User, itemId)` porte les refus par exceptions `ContainerNotVisibleException` 404, `NotAContainerException` 422, `ContainerDeclaredByAnotherUserException` 409, `ContainerNamedLikeProjectContainerException` 409) :
- `GET /industry/settings/personal-stock-containers` : mes containers déclarés, chacun avec `id`, `itemId`, `name`, `divisionName`, `stationId`, `stationName`, `status` (`known` | `unknown`), `unknownReason` (`null` si `known`), `itemCount` (nombre de types distincts rangés dedans, sous-containers non comptés, `null` si `unknown`) ;
- `POST` `{itemId}` : déclare un container ; `201` avec la ressource, y compris s'il est déjà déclaré par moi (idempotent, renvoie l'existant, comme l'épinglage de container) ;
- `DELETE /…/{id}` avec `provider` qui renvoie la ressource, limité à mes déclarations (le container d'un autre : `404`).
Refus : `404` si l'asset n'est pas un asset corporation visible de moi (`PinnedContainerCatalog::visibleAssetOf`) ; `422` « not a container » si `isSingleton()` n'est pas `true` ; `409` si le container est déclaré par un autre utilisateur ; `409` « project container » si son nom (`IndustryProjectContainer::keyOf`) est celui d'un container d'un projet ouvert (propriétaire ou participant accepté, comme `PinnedContainerCatalog`) ; `401` sans authentification.

R3. **Lecture d'un container déclaré.** Assets visibles de moi (`VisibleCorporationAssetsFinder::findVisibleAssets($user->getCorporationId(), null, $user)`), container retrouvé **par item id** (plus de recherche par nom). Contenu = tout ce qui est rangé dedans, sous-containers compris, chaque asset une fois, un sous-container non vide n'étant pas un article (`ProjectContainerService::contentsOfContainers`, même règle que #1293 R2). Trouvé et vide : `known`, contenu vide (seule lecture où zéro est une valeur). Station = `AssetStations` (#1293 R4) ; nom de station et système lus sur l'item bureau (`locationName`, `solarSystemName`).

R4. **Jamais zéro quand on ne sait pas.** `unknown`, contenu `null`, avec la raison :
- `owner_without_corporation`, `no_director`, `corporation_assets_not_synced`, `no_visible_division` : raisons de `ProjectContainerService::unavailabilityReason` (à rendre publiques pour un utilisateur, comportement inchangé) ;
- `corporation_assets_stale` : assets corporation plus vieux que `CORPORATION_ASSETS_STALE_AFTER` (48 h), **même si le container est trouvé** (décision D2) ;
- `container_in_hidden_division` : l'item existe dans les assets corporation mais pas parmi les assets visibles de moi ;
- `container_not_found` : l'item n'existe plus dans les assets corporation ;
- `station_unresolved` : container trouvé mais station non résolue (jamais le hangar pris pour une station).
Ordre de priorité : indisponibilité de la corporation, péremption, puis introuvable/caché, puis station.

R5. **Un seul lecteur de « mon stock ».** `PersonalStockReader::of(User): PersonalStock` (service, jamais de base ni d'ESI hors cache d'assets) :
- `quantitiesByTypeId` : `array<int,int>`, hangars perso + contenu des containers `known` ;
- `locationsByTypeId` : même forme que `getQuantitiesByUserWithLocations` (`total`, `locations: [{locationId, locationName, systemName, quantity}]`, tri par quantité décroissante), contenu des containers rangé sous la station du container ; une quantité de hangar et une de container à la même station s'additionnent ;
- `containers` : une lecture par container déclaré (R3, R4), ordre du nom ;
- `unknownReason()` : `null` si tous les containers sont `known` (ou aucun déclaré), sinon la raison du premier container `unknown` dans l'ordre des lectures.
Le stock corpo n'entre jamais dans ces quantités : seul un container déclaré y entre.

R6. **Appelants basculés sur le lecteur** : `IntermediateStockProposer`, `PivotAdvisorService` (2 sites), `SlotTrackerService`, `StockpileService`. Chacun garde sa sortie ; les quantités viennent de `quantitiesByTypeId` (ou `locationsByTypeId` pour le stockpile). Sans container déclaré, le résultat est identique à aujourd'hui, test par test. `OreSaleService` (vente de minerai des hangars perso) garde `getAggregatedQuantitiesByUser`.
`IntermediateStockProposer` : si `unknownReason()` n'est pas `null`, le résultat est `unknown` avec cette raison (le proposer a déjà un état `unknown`). Les trois autres n'affichent pas encore l'état « inconnu » d'un container : ses quantités n'y entrent pas, l'affichage par station est #1166, #1167, #1168 (décision D3).

R7. **Container de projet et container de stock perso s'excluent.** Dans l'autre sens de R2 : poser un container de projet (`ProjectContainerPlacer::place`) dont le nom (`keyOf`) est celui d'un container que j'ai déclaré est refusé (`409`, « a personal stock container has this name »). Les déclarations d'un autre utilisateur ne sont pas comparées (défaut du nommage par nom, #801, signalé, non traité).

R8. **Pas de Mercure** : déclarer ou retirer est synchrone, aucune action asynchrone ; aucune sync planifiée nouvelle ; aucun appel ESI nouveau.

## Exemples (tests rouges)

Corporation C, division 2 visible, assets synchronisés, director connecté, bureau B à la station 100 (nom « Jita IV »), container « Perso A » (singleton) en hangar de B.

| Déclarés | Assets | Lecteur |
|---|---|---|
| aucun | hangars perso : 10 Tritanium | `quantitiesByTypeId` {Tritanium:10}, `unknownReason` null |
| « Perso A » | hangars 10 Tritanium ; « Perso A » : 5 Tritanium + 2 Pyerite | {Tritanium:15, Pyerite:2} ; `containers[0]` `known` |
| « Perso A » | idem, plus 50 Zydrine en division 2 hors de tout container déclaré | Zydrine absent (stock corpo) |
| « Perso A » | « Perso A » contient « Vrac » avec 3 Pyerite | Pyerite 3, « Vrac » pas un article |
| « Perso A » | hangar perso 10 Tritanium à la station 100, container 5 Tritanium | `locationsByTypeId[Tritanium]` : total 15, une location 100 de quantité 15 |
| « Perso A » vide | | `known`, contenu vide, quantités = hangars |
| « Perso A » | item supprimé des assets | `unknown`, `container_not_found`, quantités = hangars seuls |
| « Perso A » | item en division 5 non visible de moi | `unknown`, `container_in_hidden_division` |
| « Perso A » | assets corporation de plus de 48 h | `unknown`, `corporation_assets_stale`, même trouvé |
| « Perso A » | bureau absent du cache | `unknown`, `station_unresolved` |
| « Perso A » | director absent / assets jamais synchronisés / aucune division visible / sans corporation | `unknown`, `no_director` / `corporation_assets_not_synced` / `no_visible_division` / `owner_without_corporation` |
| 2 containers dont 1 `unknown` | | `unknownReason()` = raison de l'`unknown` ; le `known` compte dans les quantités |

Proposer : un container déclaré `unknown` (`container_not_found`) donne `STATE_UNKNOWN` avec cette raison ; un container `known` avec 5 d'un composant fait entrer ces 5 dans le stock proposé.

API : POST d'un asset visible, singleton → `201` ; deuxième POST du même → `201`, même id, une seule ligne en base ; asset non visible ou inconnu → `404` ; asset non singleton → `422` ; déclaré par un autre → `409` ; nom d'un container d'un projet ouvert → `409` ; DELETE du mien → `204`, du container d'un autre → `404` ; GET liste uniquement les miens ; non authentifié → `401`. Placement d'un container de projet au nom d'un container déclaré → `409`.

## Cas limites

- `typeName` absent d'un asset : reste `null`, jamais remplacé par un texte.
- Un container renommé en jeu : lu par item id, affiché avec son nom actuel ; le nom d'instantané ne sert qu'à l'`unknown`.
- Un container déclaré dans une division qui cesse d'être visible : `container_in_hidden_division`, jamais zéro.
- Un container déclaré en même temps projet et perso par renommage en jeu : le nom d'un container de projet n'est pas revérifié à la lecture ; le lecteur perso lit par item id, la lecture projet par nom (#801), double lecture possible, signalée au journal, non traitée.

## Accès et fraîcheur

- Écriture et lecture limitées à mes déclarations ; le container doit être visible de moi (`CorpAssetVisibility::divisionsVisibleTo($user)`, corporation du personnage principal, `Character.corporationId` rafraîchie à la connexion : défaut préexistant, non traité). L'id du container vient du corps de la requête mais est validé contre les assets visibles ; l'id de la déclaration vient de l'URL et est filtré par utilisateur.
- Fraîcheur : cache d'assets corporation (sync à la demande, pas de tâche planifiée) ; 48 h comme `ProjectContainerService`.

## Tests existants modifiés

Les 18 fichiers qui construisent `PivotAdvisorService` (5), `SlotTrackerService` (2), `StockpileService` (10), `IntermediateStockProposer` (1) reçoivent le lecteur (helper de test commun `PersonalStockReaderStub`, qui lit les stubs `getAggregatedQuantitiesByUser` / `getQuantitiesByUserWithLocations` existants et sans container déclaré) ; aucune assertion existante ne change.

## Hors périmètre

Écran de réglage et sélecteur de containers (#1314) ; ventilation par station dans Pivot, Slot Tracker, stockpile (#1166, #1167, #1168) ; fourniture par un membre de ses intermédiaires (#1318) ; migration des projets (#1320) ; `OreSaleService` ; comparaison avec les containers de projet d'autres utilisateurs.

## Glossaire

Ajouts : **Container de stock perso** / personal stock container, **Stock perso** / personal stock, **Stock corpo** / corporation stock.

## Décisions

D1. Stockage : entité dédiée (liste de N containers), exposée sous `/industry/settings/` ; `IndustryUserSettings` et `UserSettingsResource` ne changent pas.
D2. Péremption stricte : assets corporation de plus de 48 h donnent `unknown` même container trouvé (lecture littérale de l'issue) ; la sync corpo étant à la demande, le stock perso en division est souvent `unknown` tant qu'elle n'a pas tourné.
D3. Les écrans Pivot, Slot Tracker et stockpile excluent un container `unknown` de leurs quantités sans l'afficher (l'affichage par station est #1166/#1167/#1168) ; le proposer, qui a un état `unknown`, le remonte.
D4. Un container se déclare par item id, jamais par nom ; un asset non singleton est refusé.
