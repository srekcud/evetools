# Spec #1380 — Besoins d'entrées par colonie et par launchpad (backend)

Mode nuit : validée en autonomie. Lecture seule, aucune migration, aucune sync nouvelle (donc ni Mercure ni ESI). Suit #1407.

## But

Un joueur qui importe des P1/P2/P3 sur des planètes usines ne trouve ni ce qu'il doit apporter, ni le stock de ses launchpads. Cette fondation sert les onglets Approvisionnement, Stock, Revenus et le comparatif du Planificateur.

## Ressource

`GET /api/planetary/input-needs`, `PlanetaryInputNeedsResource` (id fixe `input-needs`, comme `PlanetaryProductionResource`), `ROLE_USER`, provider `PlanetaryInputNeedsProvider` qui ne lit que `PlanetaryColonyRepository::findByUser($user)`. Calcul dans `App\Service\Planetary\PlanetaryInputNeedsCalculator` ; `PlanetaryProductionCalculator` n'est pas modifié (#1378 y change `resolveRunRates()`).

Champs de premier niveau : `colonies`, `launchpads`, `weekly`, `complete` (bool).

## Règles

1. **Besoin d'une usine** : pour chaque pin usine (`PlanetaryPin::isFactory()`) au schéma présent dans `getSchematicMap()` et dans `PlanetSchematicRepository`, chaque entrée vaut `quantité SDE × 86400 / cycleTime` par jour, **à plein régime** (jamais multiplié par un taux de marche).
2. **Colonie** (`colonies[]`, seulement les colonies avec au moins un pin usine) : `colonyId` (uuid), `planetId`, `planetName`, `planetType`, `solarSystemName`, `characterId` (EVE), `characterName`, `complete`, `needs[]`. Une ligne de `needs` par type d'entrée requis, triée par `typeId` : `typeId`, `typeName`, `dailyRequired` (somme des usines de la colonie), `dailyProducedLocally`, `dailyNetImport`, `volumePerUnit`.
3. **Production locale d'un type** = sorties à plein régime des usines de la colonie + débit des extracteurs de la colonie (`extractorQtyPerCycle × 86400 / extractorCycleTime`, produit `extractorProductTypeId`). Un extracteur dont `expiryTime` est passé (horloge `ClockInterface`) produit 0 ; sans `expiryTime`, il compte. Pas de rendement décroissant ici (#1381 le remplace).
4. **Besoin net d'import** : `dailyNetImport = max(0, dailyRequired − dailyProducedLocally)`. Le surplus n'est pas exposé (le front le déduit).
5. **Inconnu reste inconnu** : un extracteur dont `extractorProductTypeId` est connu mais `extractorCycleTime` ou `extractorQtyPerCycle` est null rend `dailyProducedLocally` et `dailyNetImport` null pour son type. Un pin usine dont le schéma est absent de la SDE (ou sans cycle) rend `complete` false sur la colonie et au premier niveau ; ses entrées ne sont pas devinées. Un `volumePerUnit` est null si `InvType::getVolume()` est null. Jamais de `?? 0` sur ces valeurs.
6. **Genre de pin déduit de la SDE** : un pin est un launchpad quand le groupe SDE de son `typeId` est le groupe « Spaceports » (`InvType::getGroup()->getGroupId()`, constante nommée, aucun id de type en dur). Les storage facilities, command centers, usines et extracteurs ne sont pas des launchpads et n'entrent pas dans `launchpads`.
7. **Launchpad** (`launchpads[]`, tous ceux des colonies du joueur, même sans route) : `colonyId`, `planetId`, `planetName`, `pinId`, `typeId`, `typeName`, `capacityM3` (SDE `InvType::getCapacity()`, null si absente), `usedVolumeM3` (Σ stock × volume des `contents`, null si un volume est inconnu), `dailyVolumeM3` (Σ débit × volume des entrées alimentées, null si un volume est inconnu), `feeds[]`.
8. **Entrées alimentées** (`feeds[]`, une ligne par type, triée par `typeId`) : `typeId`, `typeName`, `dailyRate`, `stock`, `volumePerUnit`. Un launchpad alimente un type quand une route de la colonie part de ce pin (`sourcePinId`), porte ce type (`contentTypeId`) et arrive sur un pin usine dont le schéma consomme ce type. `dailyRate` = besoin quotidien de cette usine pour ce type (règle 1). Si plusieurs routes de ce type arrivent à la même usine, son besoin est réparti entre les sources au prorata de `PlanetaryRoute::getQuantity()` (le quantity sert de poids, jamais d'unité). `stock` = somme des `amount` du type dans `contents` du launchpad (0 si le type n'y est pas : l'ESI omet les stocks vides). Les routes vers un pin non usine sont ignorées.
9. **Utilisateur** (`weekly[]`, une ligne par type consommé, triée par `typeId`) : `typeId`, `typeName`, `weeklyConsumption` (Σ `dailyRequired` de toutes les colonies × 7), `weeklyExtraction` (Σ débit des extracteurs actifs × 7, null si un extracteur de ce type est inconnu), `weeklyFactoryOutput` (Σ sorties d'usines × 7), `weeklyNetNeed = max(0, weeklyConsumption − weeklyExtraction − weeklyFactoryOutput)`, null si `weeklyExtraction` est null.
10. **Noms** : `TypeNameResolver::findName()` ; null reste null.

## Exemples chiffrés

| Cas | Entrée | Sortie |
|---|---|---|
| Usine à 1 entrée | schéma 40 ×1, cycle 3600 | `dailyRequired` 960 |
| Usine P4 | 3 entrées de 6, cycle 3600 | 144 par entrée et par jour |
| Extracteur couvrant | 4 400 par cycle de 1800 s, usine à 960 | produit 211 200, net 0 |
| Extracteur expiré | idem, `expiryTime` hier | produit 0, net 960 |
| Chaîne interne | usine A sort 20 P2 /h, usine B consomme 40 P2 /h | produit local 480, requis 960, net 480 |
| Cycle inconnu | extracteur sans `extractorCycleTime` | produit null, net null |
| Schéma absent | usine au `schematicId` inconnu | `complete` false, aucune entrée devinée |
| Deux routes | 1 800 et 600 vers une usine à 960 | 720 et 240 |
| Utilisateur | 2 colonies à 960 de P1 requis, extraction 211 200 | consommation 13 440, net 0 |

## Hors périmètre

- Taux de marche, autonomie, projection (#1378, #1382), rendement décroissant (#1381).
- Storage facilities comme sources, quantités à apporter, prix, m³ par passage (Approvisionnement, #1406).
- Front, i18n, aide : aucune évolution (l'écran vient dans une issue suivante).
- Suppression ou modification de `VOLUME_BY_TIER` et de `PlanetaryProductionCalculator`.

## Décisions prises en autonomie

- Le net utilisateur soustrait l'extraction **et** la sortie des usines : sans cela, un P2 fabriqué puis consommé dans la même colonie compterait comme besoin à acheter. Les deux composantes restent exposées.
- Le débit d'une route vient de la SDE (besoin de l'usine destinataire) : l'unité de `quantity` côté ESI n'est pas documentée ici, elle ne sert qu'au prorata.
- Une seule ressource pour les trois vues (colonie, launchpad, utilisateur) : un seul passage sur les colonies.

## Tests

- Unit `PlanetaryInputNeedsCalculatorTest` : un cas par règle (1, 3, 4, 5, 6, 8, 9) plus une garde (un storage facility absent de `launchpads`).
- Fonctionnel API : 401 sans jeton ; 200 et portée utilisateur (la colonie d'un autre joueur n'apparaît pas).

## Note de câblage

Fichiers à créer (aucun existant modifié, donc aucun test existant appelé à casser) :
- `src/Service/Planetary/PlanetaryInputNeedsCalculator.php`
- `src/State/Provider/Planetary/PlanetaryInputNeedsProvider.php`
- `src/ApiResource/Planetary/PlanetaryInputNeedsResource.php` (modèle : `PlanetaryProductionResource`, `shortName` propre, id `input-needs`, `uriTemplate: '/planetary/input-needs'`)
- `tests/Unit/Service/Planetary/PlanetaryInputNeedsCalculatorTest.php`, `tests/Functional/Api/Planetary/PlanetaryInputNeedsApiTest.php`

Dépendances du calculateur (toutes autowirées : `services.yaml` charge `../src/` avec `autowire: true`, aucune édition) : `PlanetSchematicRepository`, `PlanetSchematicTypeRepository` (`getSchematicMap()`), `InvTypeRepository`, `TypeNameResolver`, `Symfony\Component\Clock\ClockInterface` (même alias que `CloseMiningSiteProcessor`). Pas de `JitaMarketService`. Provider : `Security`, `PlanetaryColonyRepository`, le calculateur (copie du provider Production, y compris le `UnauthorizedHttpException`).

Deptrac : `Service` -> Entity, Repository, Vendor autorisés ; `StateProvider` -> Service, Repository, Entity, Security ; `ApiResource` -> StateProvider. `ClockInterface` est Vendor. Aucun `skip_violations` à ajouter, à condition que la ressource ne référence pas de classe `Service`.

Horloge : `ClockInterface` injectée, jamais `new \DateTimeImmutable()` (le calculateur existant le fait, ne pas copier). Unit : `new Symfony\Component\Clock\MockClock('2026-11-25 12:00:00')` passé au constructeur. Fonctionnel : `use ClockSensitiveTrait` et `self::mockTime(...)` dans `setUp()` (modèle `tests/Functional/Api/MiningSite/MiningSiteCompressionApiTest.php`).

Pièges :
- N+1 : `findByUser` ne fetch-join ni pins, ni routes, ni character ; `getPins()`, `getRoutes()` et `getCharacter()` chargent en lazy par colonie. Acceptable pour le volume (même coût que `PlanetaryProductionProvider`) ; ne pas appeler un repository dans les boucles de pins.
- SDE en une requête : collecter d'abord tous les `typeId` (pins, `contentTypeId` des routes, entrées de schéma, `extractorProductTypeId`), un seul `InvTypeRepository::findByTypeIds()` (joint groupe et catégorie). Les `TypeNameResolver::findName()` suivants passent par `find()` et sont servis par l'identity map sans SQL. `getSchematicMap()` et `findBySchematicId()` : précharger une fois par appel, pas par pin ; vérifier que `getSchematicMap()` n'est pas déjà mis en cache (état partagé entre tests).
- bigint : `pinId`, `sourcePinId`, `destinationPinId` sont des colonnes `bigint`, que Doctrine rend en string côté DBAL. Caster les deux côtés en `(int)` à la construction des index (`pinId => PlanetaryPin`) et ne jamais comparer avec `===` des valeurs brutes. Idem pour les clés d'un `contents` JSON (`type_id` int, `amount` int).
- Groupe Spaceports : constante nommée `SPACEPORTS_GROUP_ID`. Valeur non confirmée dans le dépôt (aucun id de groupe nulle part ; 1030 de mémoire) : la lire dans le SDE importé avant de figer les tests. Le type 2256 du `SeedPlanetaryCommand` ne doit pas apparaître dans le calculateur.
- Les `contents` null (`?array`) comptent pour un stock 0 sur les feeds, pas pour une erreur ; un volume inconnu rend `usedVolumeM3` null (règle 7), pas 0.

Modèle Functional à copier : `tests/Functional/Api/Market/MarketAlertUpdateApiTest.php` (`WebTestCase`, `loginUser($user, 'api')`, `persistPilot()`, 401 sans jeton). Fixtures à persister : `InvGroup`, `InvType` (volume, capacité), `PlanetSchematic` et `PlanetSchematicType`, `Character`, `PlanetaryColony` avec pins et routes, pour deux utilisateurs.
