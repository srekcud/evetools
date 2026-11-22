# #1167 Stock du Pivot Advisor par station

Circuit m, chaîne C. Écran : l'onglet Pivot Advisor (`PivotAdvisorTab.vue`), blocs « Key components in stock » et matrice des composants. Prérequis sur `feat/industry-flow` : `PersonalStockReader` (#1324, `PersonalStock::$locationsByTypeId`, déjà lu par `PivotAdvisorService` pour les quantités), `CachedAssetRepository::getQuantitiesByUserWithLocations()`.

## Règles

1. `PivotAdvisorService::analyze()` lit le stock une seule fois (`PersonalStockReader::of()`), comme aujourd'hui, et garde `quantitiesByTypeId` pour tout calcul. La couverture (`covered` / `partial` / `none`), les quantités manquantes, la couverture pondérée, la marge et le classement ne changent pas.
2. Chaque ligne de `matrix` et chaque entrée de `sourceProduct.keyComponents` garde `inStock` (total) et gagne `stockByStation` : liste de `{locationId:int, locationName:string, systemName:string|null, quantity:int}`. Source : `PersonalStock::$locationsByTypeId[typeId]['locations']` (hangars perso et containers perso déclarés, #1324), telle quelle : ordre du lecteur (quantité décroissante), aucune réconciliation ajoutée.
3. Un type sans stock donne `inStock = 0` et `stockByStation = []`. Aucune entrée n'est inventée, aucune station « inconnue » n'est ajoutée.
4. Le stock corporation hors containers perso déclarés n'est jamais compté : la ventilation vient du lecteur, rien de plus.
5. Le chemin sans composant fabricable (`buildResultWithSourceOnly`) garde `keyComponents = []` et `matrix = []`.
6. `PivotAdvisorResource` expose `stockByStation` dans `sourceProduct.keyComponents` et `matrix` (types PHPDoc mis à jour). Aucun nouvel endpoint, aucune écriture, accès inchangé.
7. Front : types TS `PivotStockStation`, `PivotComponentCoverage.stockByStation`, `PivotSourceProduct.keyComponents[].stockByStation`. Le total « En stock » reste affiché tel quel.
   - Un composant dont `stockByStation` n'est pas vide affiche un bouton (`pivot-stock-toggle-matrix-<typeId>` dans la matrice, `pivot-stock-toggle-key-<typeId>` dans les composants clés) qui déplie la ventilation ; replié par défaut, `aria-expanded` à jour.
   - Matrice : la ventilation s'ouvre dans une ligne dédiée sous le composant (`pivot-stock-stations-matrix-<typeId>`) ; composants clés : sous la ligne du composant (`pivot-stock-stations-key-<typeId>`).
   - Une station par ligne : nom, système (omis si `null`), quantité formatée.
   - Un composant sans stock (liste vide) : ni bouton ni ligne dépliée.
   - Les états dépliés sont tenus dans un `Set` remplacé en entier (conventions front) et se réinitialisent quand le résultat de l'analyse change.
8. i18n fr et en : `industry.scanner.pivotAdvisor.stockByStation.{heading,toggle}` (titre de la liste, libellé accessible du bouton). Le glossaire gagne **Stock par station** (même commit).

## Exemples

Composant Robotics, besoin de 3 par run, stock perso : 40 à Jita (id 100), 15 à Ishtar (id 200) dans le système Ishtar.

| Cas | inStock | stockByStation |
|---|---|---|
| deux stations | 55 | Jita 40, Ishtar 15 (système Ishtar) |
| un container perso déclaré à Ishtar, 5 dedans | 60 | Jita 40, Ishtar 20 |
| aucun stock | 0 | `[]` |
| système inconnu d'une station | 15 | `systemName = null` |

La couverture de la matrice pour 55 en stock et un besoin de 50 reste `covered`, qu'une partie du stock soit loin du lieu de fabrication.

## Cas limites

- Même type dans les composants clés et dans la matrice : même ventilation (même lecture).
- Station sans nom résolu : le lecteur la fournit déjà avec un nom (`locationName`), pas de repli côté écran.
- Plusieurs runs ou ME différents : la ventilation est du stock, elle ne dépend ni des runs ni des candidats.

## Hors périmètre

Stock du projet par station (#1166, #1294), Slot Tracker (#1168), filtrage ou pondération de la couverture par la station de fabrication, état « stock illisible » d'un container perso (déjà porté par `PersonalStock::unknownReason()`, pas exposé par cet écran aujourd'hui), tout changement de calcul de marge.

## Décisions

- Ventilation brute du lecteur plutôt qu'une station « de fabrication » mise en tête : le Pivot Advisor compare des produits qui ne sont pas rattachés à un projet, donc sans station du plan (écart avec #1166 assumé).
- Dépliage (bouton) plutôt que bulle : accessible au clavier et lisible sur écran tactile.
