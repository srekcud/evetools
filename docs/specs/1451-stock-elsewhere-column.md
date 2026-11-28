# Spec #1451 — Colonne « Ailleurs » dans Stock : le stock du compte hors home et hub, compté dans la couverture

Mode nuit : validée en autonomie. Décision de Sylvain (2026-11-28, dernier commentaire de l'issue) : une colonne « Ailleurs » dans Stock, qui somme le stock du compte hors home et hub, comptée dans la couverture mais marquée « pas au hub ». Pas de nouveau réglage. Lecture seule : aucune migration, aucune sync, aucun appel ESI, aucune action async (donc pas de Mercure).

## Contexte vérifié

- La colonne Hub lit les assets dont la racine est exactement `hubStructureId` (`PlanetaryStockReader::quantitiesByRootLocation`, conteneurs suivis par `AssetBrowseRepository::treeCte()`). Un P1 gardé dans une autre station, même du système du hub, n'est lu par aucune colonne : le customs office est la seule autre lecture (`customsOfficeQuantities`, #1390).
- Les assets ne sont pas planifiés : la lecture est celle de la dernière synchro assets.
- Prérequis grep sur origin/main : `PlanetaryStockRow::$customsOffice`, `PlanetaryStockReadings::$customsOfficeQuantities`, `PiStockCoverageGrid.vue` (colonne `stock-cell-customs-*`), `GET /planetary/stock` (`PlanetaryStockProvider`, champ `customsOffice`) existent ; aucun champ `elsewhere`.

## Règles backend

1. **Lecture** : `AssetBrowseRepository::elsewhereQuantities(AssetScope $scope, list<int> $excludedRootLocationIds, list<int> $typeIds): array<int, int>` rend typeId → quantité, somme des assets (conteneurs inclus) du périmètre dont la racine n'est ni l'un des lieux exclus ni un customs office au sens de `customsOfficeQuantities` (même condition, niée avec `IS NOT TRUE` pour qu'un nom de lieu NULL ne sorte pas la ligne). Liste de types vide : tableau vide. Un asset en `solar_system`, dans une structure nommée ou une autre station compte.
2. **Stock** : `PlanetaryStockReader` somme cette lecture sur tous les personnages de l'utilisateur, pour les types des lignes `weekly[]`, en excluant `homeLocationId` (s'il est connu) et `hubStructureId`, et la passe à `PlanetaryStockReadings::$elsewhereQuantities` (typeId → quantité, dernier paramètre, défaut `[]`).
3. **Ligne** : `PlanetaryStockRow` gagne `elsewhere` (int, 0 si absent), à la suite de `customsOffice`. `held = home + hub + corp + customsOffice + elsewhere` dans la couverture, la couverture avec ordres et `toBuy` ; la règle de nullité (`home`, `corp`, consommation inconnus) ne change pas. Les ordres ouverts restent ceux du hub seul.
4. **Ressource** : `GET /planetary/stock` ajoute `elsewhere` à chaque ligne (le provider mappe le champ).
5. **Accès** : assets des seuls personnages de l'utilisateur, aucun identifiant reçu. Fraîcheur : dernière synchro assets. Le stock corpo (colonne Hangar corpo, home seul) n'est pas lu par cette colonne.

## Exemples chiffrés

| Cas | Attendu |
|---|---|
| 600 Robotics dans un conteneur d'une station autre que home et hub | `elsewhere` 600 |
| 66 Robotics en `solar_system`, 777 en structure nommée, 400 en home | `elsewhere` 843, `home` 400 |
| 25 Bacteria dans un conteneur de la station home | pas dans `elsewhere` |
| 1 200 Robotics sous `Structure #…` non résolue | `customsOffice`, pas `elsewhere` |
| Home = hub | une seule exclusion, le reste compte |
| Home inconnu | seul le hub est exclu ; `elsewhere` lu, couverture inconnue comme avant |
| Deux personnages, 700 et 500 ailleurs | 1 200 |
| Assets corpo (`isCorporationAsset`) ou d'un autre utilisateur | jamais comptés |
| Couverture : conso 1 000/sem., home 1 500, hub 500, ailleurs 400, cible 3 | couverture 2,4 ; `toBuy` `ceil(3 000 − 2 400)` = 600 |
| Type absent partout | `elsewhere` 0, jamais inconnu |
| Seulement du stock ailleurs, aucun home ni hub | couverture et `toBuy` comptent l'ailleurs |

## Règles front

6. **Stock** : `PiStockCoverageGrid.vue` ajoute la colonne « Ailleurs » (EN « Elsewhere », `pi.stock.grid.elsewhere`, FR et EN) juste après « Hangar corpo » (l'ordre Hub, Customs office, Hangar corpo reste intact), cellule `stock-cell-elsewhere-<typeId>`, toujours un nombre. L'en-tête (`stock-header-elsewhere`) affiche le libellé (`pi.stock.grid.elsewhere`) et, sur une ligne en dessous, la mention visible « pas au hub » (`pi.stock.grid.notAtHub`) ; pas d'attribut `title` (le test existant du Stock exige un seul `title` dans l'en-tête, celui du customs office). Une cellule dont la valeur est positive porte `data-not-at-hub="true"` et une teinte distincte de la colonne Hub. Le type du store gagne `elsewhere: number`.
7. La colonne Hub, l'épingle « à rapatrier » (qui lit `row.hub` seul) et la colonne « À acheter » (backend) ne changent pas.

## Tests existants modifiés

- `PlanetaryStockApiTest::testCustomsOfficeStockOfAllTheCharactersIsHeldStockInTheCoverageAndWhatToBuy` et `testUsersWithoutCustomsOfficeStockGetAZeroOnEveryRowAndNeverTheStockOfAnotherUser` : le stock de la structure nommée (4 000 Bacteria d'Erin) entre dans `elsewhere`, donc `toBuy` baisse ; valeurs recalculées dans le lot. Raison : décision #1451.
- Fixtures front `frontend/tests/planetaryStockFixtures.ts` : champ `elsewhere` obligatoire.

## Hors périmètre

- Plusieurs lieux de stock déclarés par l'utilisateur (alternative écartée), nouveau réglage.
- Détail par lieu de la colonne Ailleurs (liste des stations), épingle « à rapatrier » pour l'ailleurs, effet sur Approvisionnement et sur les prix.
- Rendre les assets planifiés.

## Termes du glossaire

« Ailleurs » (Elsewhere) : stock du compte (personnages seulement) dont la racine n'est ni le home, ni le hub, ni un customs office ; compté dans la couverture de l'onglet Stock, jamais « au hub ». Symbole : `PlanetaryStockRow::$elsewhere`, `AssetBrowseRepository::elsewhereQuantities()`.

## Décisions

- Les assets en `solar_system` (objets en espace, vaisseaux) comptent dans « Ailleurs » : la décision dit « hors home et hub », sans autre filtre. Question laissée à Sylvain au journal.
