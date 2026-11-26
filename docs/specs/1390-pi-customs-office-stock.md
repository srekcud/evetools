# Spec #1390 — Stock en customs office, compté dans la couverture et placé sur une planète probable

Mode nuit : validée en autonomie. Décisions de Sylvain (2026-11-25) : le stock en customs office compte dans la couverture de l'onglet Stock sur une ligne « en customs office », sans lieu ; dans l'Approvisionnement, la planète est déduite (une seule colonie concernée : « probable », sinon « non localisé ») ; pas de liaison manuelle. Pile sur #1388, #1389 (PR #1432) et #1395 (PR #1433, onglet Stock), fusionnée dans cette branche. Lecture seule : aucune migration, aucune sync, aucun appel ESI, aucune action async (donc pas de Mercure).

## Contexte vérifié

- Les items d'un customs office sont des assets du personnage dont `location_id` est l'id du customs office, que l'ESI ne résout pas : `AssetsService` les nomme `Structure #<id>` ou `Location #<id>` (`locationType` `other`). Un asset en `solar_system`, dans un conteneur (`item`) ou dans une station résolue n'est pas concerné ; un conteneur ou un vaisseau rangé dans un customs office remonte à sa racine par `AssetBrowseRepository::treeCte()`.
- Limite assumée : une structure joueur non résolue (accès perdu) porte le même nom ; seuls les types PI de l'utilisateur sont lus, ce qui rend l'ambiguïté improbable. L'écran dit « customs office ».

## Règles backend

1. **Lecture** : `AssetBrowseRepository::customsOfficeQuantities(AssetScope $scope, list<int> $typeIds): array<int, int>` rend typeId → quantité, somme des assets (conteneurs inclus) dont la racine est en `locationType` `other` et dont le nom de lieu commence par `Structure #` ou `Location #` (constantes nommées, dans `AssetLocationLabel` pour `Structure #` qui y existe déjà). Liste de types vide : tableau vide. Les lieux résolus (station, structure nommée, lieu maison, hub) ne comptent jamais.
2. **Stock (#1395)** : `PlanetaryStockReader` somme cette lecture sur tous les personnages de l'utilisateur pour les types des lignes `weekly[]` et la passe à `PlanetaryStockReadings::$customsOfficeQuantities` (typeId → quantité, défaut `[]`). `PlanetaryStockRow` gagne `customsOffice` (int, 0 si absent). `held = home + hub + corp + customsOffice` dans la couverture, la couverture avec ordres et `toBuy` ; la règle de nullité (`home`, `corp`, consommation inconnus) ne change pas. `GET /planetary/stock` ajoute `customsOffice` à chaque ligne (le provider mappe le champ).
3. **Approvisionnement** : `PlanetaryCustomsOfficeStockReader::read(User $user, array $colonies): list<array<string, mixed>>` (service dédié, lecture seule). Types lus : types consommés ou produits par les colonies de l'utilisateur (entrées et sortie des schémas des pins usine, produits des extracteurs ; schéma absent : ses types ne sont pas devinés). Une ligne par type avec quantité > 0 : `typeId`, `typeName` (`TypeNameResolver`, null reste null), `quantity` (somme des personnages), `volumePerUnit` (SDE, null si absent), `volumeM3` (null si le volume est null), `probablePlanet` (`{colonyId, planetName}` ou null). Triée par `typeId`.
4. **Planète probable** : pour un type, les colonies candidates sont celles qui le consomment (une usine dont le schéma l'a en entrée), le produisent (sortie d'un schéma ou produit d'un extracteur). Exactement une candidate : `probablePlanet` = cette colonie. Zéro ou plusieurs : null (« non localisé »). Évaluée par type, jamais par customs office.
5. **Ressource** : `GET /planetary/pending-stock` (#1389) ajoute `customsOfficeStocks` (liste de la règle 3) ; `storages` et `pendingOutputs` ne changent pas. `PlanetaryPendingStockCalculator` n'est pas modifié ; le provider appelle le lecteur avec les colonies de `findByUser`.
6. **Accès** : assets, colonies des seuls personnages de l'utilisateur, aucun identifiant reçu. Fraîcheur : assets à leur dernière synchro.

## Exemples chiffrés

| Cas | Attendu |
|---|---|
| 1 200 Robotics sous un lieu `Structure #1020000000001`, `other` | `customsOffice` 1 200 dans la ligne Stock |
| Même type dans un conteneur de ce lieu (300) | compté : 1 500 |
| Même type en station résolue (home) | compté dans `home`, pas dans `customsOffice` |
| Même type en structure nommée `other` | non compté |
| Deux personnages, 700 et 500 | 1 200 |
| Couverture, conso 1 000/sem., maison 1 500, hub 500, customs 400, cible 3 | couverture 2,4 ; `toBuy` `ceil(3 000 − 2 400)` = 600 |
| Une seule colonie consomme Silicon | `probablePlanet` = cette colonie |
| Deux colonies consomment Silicon | `probablePlanet` null |
| Aucune colonie ne touche au type (P1 d'un autre usage) | ligne absente (type non lu) |
| Quantité 0 | ligne absente |
| Volume SDE absent | `volumeM3` null |
| Autre utilisateur | ses assets n'apparaissent pas |

## Règles front

7. **Stock** : `PiStockCoverageGrid.vue` ajoute la colonne « Customs office » (`pi.stock.*`, FR et EN) entre « Hub » et « Hangar corpo » ; la valeur est toujours un nombre (0 si rien) ; la couverture et « À acheter » viennent du backend. Une infobulle sur l'en-tête dit : stock sans lieu connu.
8. **Approvisionnement** : `buildPickupPlan` reçoit `customsOfficeStocks` en plus et, pour chaque type à apporter, ajoute une source par ligne de customs office : planète `probablePlanet.planetName` avec le suffixe « (probable) » ou, sans planète, « Customs office, non localisé » (`pi.supply.pickup.customsOffice*`) ; sa quantité compte dans `pending` (donc dans `à ramasser = min(à apporter, en attente)`) ; une source sans quantité n'existe pas (la quantité est toujours connue). `PiSupplyPendingBlocks.vue` l'affiche parmi les sources, avec le m3, et marque la source « customs office » par `data-kind="customs"`. Pas de nouveau bloc.
9. Store `planetaryPendingStock` : le type de réponse gagne `customsOfficeStocks`. Une réponse sans ce champ est une erreur de forme (aucune valeur par défaut) : le store l'expose dans `error`.
10. i18n FR et EN, parité vérifiée par les tests de clés existants ; glossaire : « Stock en customs office » / « Customs office stock », « Planète probable » / « Probable planet ».

## Hors périmètre

- Liaison manuelle d'un customs office à une planète. Retrait ou récupération du stock. Position d'un customs office (nom de système).
- Le customs office dans « À rapporter » (ce sont des entrées, pas des sorties).

## Décisions prises en autonomie

- Détection par le nom non résolu du lieu racine plutôt que par un drapeau : l'ESI n'en fournit pas ; limitée aux types PI.
- `customsOffice` vaut 0 en l'absence de stock (comme `hub`), pas null : une lecture d'assets réussie sans ligne est une quantité nulle.
- Planète probable évaluée par type : un customs office mélange des types de colonies différentes, une planète par office serait fausse.
- La branche contient #1395 par fusion (PR #1433) tant qu'elle n'est pas fusionnée : à fusionner après #1432 et #1433.

## Tests

- Unit : `PlanetaryStockCalculatorTest` (couverture et `toBuy` avec customs office, garde sans customs), `PlanetaryStockReaderTest` (somme des personnages), `PlanetaryCustomsOfficeStockReaderTest` (types lus, planète probable, ambiguïté, volume absent, quantité 0). Integration `AssetBrowseRepositoryTest` : lieu non résolu, conteneur, station résolue, structure nommée, autre personnage. Fonctionnel : `PlanetaryStockApiTest` (champ), `PlanetaryPendingStockApiTest` (`customsOfficeStocks`, portée utilisateur).
- vitest : colonne de la grille, `buildPickupPlan` avec source customs, bloc, store, parité i18n.

## Note de câblage

Backend : `AssetBrowseRepository`, `AssetLocationLabel`, `Stock/{PlanetaryStockReader,PlanetaryStockReadings,PlanetaryStockRow,PlanetaryStockCalculator}`, `PlanetaryStockProvider` (mapping de ligne), nouveau `src/Service/Planetary/PlanetaryCustomsOfficeStockReader.php` (dépendances : `AssetBrowseRepository`, `PlanetSchematicTypeRepository`, `InvTypeRepository`, `TypeNameResolver`), `PlanetaryPendingStockResource` (champ) et son provider. Deptrac : la ressource ne référence pas de classe `Service`.
Front : `utils/planetaryPickup.ts`, `stores/planetaryPendingStock.ts`, `components/planetary/{PiSupplyPendingBlocks,PiStockCoverageGrid}.vue`, `stores/planetaryStock.ts` (type de ligne), locales.
