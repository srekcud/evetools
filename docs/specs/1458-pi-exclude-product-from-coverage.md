# Spec #1458 : exclure un produit de la couverture du Stock, la liste d'achat suit

Mode nuit : validée en autonomie, décisions en bas. Pile sur #1394 (réglages PI, `docs/specs/1394-pi-settings.md`) et #1395 (couverture et liste d'achat, `docs/specs/1395-pi-stock-coverage.md`), après #1452.

## Contexte

La grille de couverture propose des produits (P1 à P3) que le joueur fournit autrement, par exemple une planète qui consomme le surplus P2 d'une autre. Il ne peut pas les retirer et la liste d'achat les garde. Le joueur veut les exclure, par utilisateur et de façon réversible.

## Backend

1. Colonne `User::$planetaryExcludedTypeIds` : JSON non nul, défaut base et entité `[]`, liste d'entiers positifs sans doublon, triée. Migration `Version20261127230000` (ajout de la colonne, `down` la retire, aucune donnée touchée). Accesseurs `getPlanetaryExcludedTypeIds(): list<int>` et `setPlanetaryExcludedTypeIds(list<int>)` qui trie et dédoublonne.
2. `PlanetarySettingsResource` gagne `excludedTypeIds: list<int>` (lecture et écriture), rempli par `forUser`. `PATCH /planetary/settings` (merge-patch) le remplace en entier ; absent du corps : inchangé.
3. Validation du PATCH (422, violation sur `excludedTypeIds`, message en anglais, rien n'est écrit si une règle échoue) : chaque élément est un entier ≥ 1 ; au plus 200 éléments (`MAX_EXCLUDED_TYPES`). Une liste vide est valide (tout restaurer). Aucun identifiant d'un autre périmètre n'est reçu : un type PI n'est pas une donnée d'un autre utilisateur.
4. `PlanetaryStockSettings` gagne `excludedTypeIds` (liste d'entiers, 4e paramètre, défaut `[]`). `PlanetaryStockReader::read` retire de `weekly` les lignes des types exclus avant toute lecture (assets, ordres, customs office) : ces types n'ont plus de ligne, donc ni couverture ni `toBuy`, et la liste d'achat du front (somme des `toBuy`) les omet. `complete` se calcule sur les lignes restantes.
5. `PlanetaryStockProvider` passe `$settings->excludedTypeIds` au reader et `PlanetaryStockResource` expose `excludedTypeIds` (liste d'entiers) pour que le front liste ce qui est exclu même quand le type n'a plus de ligne.
6. Hors de la couverture, rien ne change : « Prix et signaux » (`/planetary/stock-prices`), besoins de projets, Approvisionnement et Revenus lisent les mêmes besoins sans filtre.

## Frontend

7. Store `planetarySettings` : `PlanetarySettings.excludedTypeIds`, actions `excludeType(typeId)` et `restoreType(typeId)` (PATCH de la liste complète, même gestion d'erreur que `saveCoverageWeeks` : en cas d'échec, `error` posé et liste précédente conservée). Ajouter un type déjà exclu, ou restaurer un type absent : aucun appel, retour `true`.
8. `PiStockCoverageGrid.vue` : dans la cellule de décision de chaque ligne, un bouton « Exclure » (`data-testid="stock-exclude-<typeId>"`) ; après l'enregistrement, la grille se recharge (le `saveRevision` du store déclenche déjà le rechargement de `PiStockTab`) et la ligne disparaît avec sa part de la liste d'achat.
9. Nouveau composant `PiStockExcluded.vue` (`data-testid="stock-excluded"`), affiché dans `PiStockTab` au-dessus de la grille seulement quand `excludedTypeIds` n'est pas vide : texte « n produit(s) exclu(s) de la couverture » et une puce par type, avec le nom (catalogue du Planificateur, sinon `#typeId`) et un bouton « Rétablir » (`data-testid="stock-restore-<typeId>"`). Direction design HUD EVE, noms longs testés (`LONG_TYPE_NAME`).
10. Textes FR et EN sous `pi.stock.excluded.*` et `pi.stock.grid.exclude`.

## Exemples chiffrés (tests rouges)

| Cas | Attendu |
|---|---|
| Utilisateur neuf, `GET /planetary/settings` et `/planetary/stock` | `excludedTypeIds` `[]` |
| `PATCH {excludedTypeIds: [3, 1, 3]}` | 200, `[1, 3]` stocké et rendu |
| `PATCH {excludedTypeIds: []}` après `[1, 3]` | 200, `[]` |
| `PATCH {coverageWeeks: 4}` après `[1, 3]` | `excludedTypeIds` toujours `[1, 3]` |
| `PATCH {excludedTypeIds: [0]}` / `[-5]` | 422 sur `excludedTypeIds`, liste précédente conservée |
| `PATCH` de 201 éléments | 422 ; de 200 éléments : 200 |
| Besoins hebdomadaires P1 `A` 700 et P2 `B` 140, `B` exclu | une seule ligne (`A`), `toBuy` de `A` inchangé, aucune ligne `B`, `excludedTypeIds` `[B]` |
| Aucun type exclu | lignes identiques à #1395 |
| Exclusion d'un type qui n'a plus de besoin | accepté, listé dans `excludedTypeIds`, aucune ligne |
| Front : clic « Exclure » sur la ligne `B` | PATCH `{excludedTypeIds: [B]}`, ligne retirée après rechargement, puce `B` affichée |
| Front : « Rétablir » sur la puce `B` | PATCH `{excludedTypeIds: []}`, puce retirée, ligne revenue après rechargement |
| Front : PATCH refusé (422) | `error` posé, ligne et liste inchangées |

## Cas limites

Type exclu absent du catalogue du Planificateur : puce `#typeId`. Liste d'achat vide parce que tout est exclu : l'état vide existant s'applique. Deux onglets : le dernier PATCH gagne (remplacement complet), le rechargement montre l'état serveur.

## Accès et fraîcheur

Lecture et écriture limitées à l'utilisateur connecté (réglage sur `User`), aucun identifiant reçu en paramètre d'URL. Anonyme : 401 comme #1394. Pas de source externe : aucune fraîcheur à dire.

## Tests existants contredits ou modifiés

Aucun attendu : le JSON de `GET /planetary/settings` et de `GET /planetary/stock` grandit d'un champ (`PlanetarySettingsApiTest`, `PlanetaryStockApiTest`, `PlanetarySettingsProviderTest`, fixtures front `planetarySettingsFixtures.ts` et `planetaryStockFixtures.ts` reçoivent `excludedTypeIds: []` : ajout, aucune assertion retirée). À vérifier en phase rouge (5a) : listes de colonnes de `UserPlanetarySettingsTest`.

## Hors périmètre

Exclusion des « Prix et signaux », de l'Approvisionnement et des besoins de projet ; exclusion par planète ou par personnage ; filtre par tier (#1457) ; reprise du surplus d'une autre planète comme source (la planète qui consomme le P2 d'une autre reste exclue à la main).

## Décisions (mode nuit)

- Persistance sur `User` (JSON) : le réglage suit le compte sur tous les appareils, comme le lieu maison et la couverture ; un état de page se perdrait au rechargement et la liste d'achat ne le suivrait pas côté serveur.
- Filtrage dans le reader (avant les lectures d'assets) : la couverture, `toBuy` et `complete` restent cohérents sans second calcul côté front.
- L'API ne valide pas qu'un type est un produit PI : un type sans besoin n'a aucun effet, la borne de 200 évite l'abus.
- Aucun réglage, champ ou écran existant n'est retiré.
