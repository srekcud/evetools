# #1166 Stock intermédiaire du projet par station

Circuit m, chaîne C. Écran : le tableau « Utiliser mon stock » (`IntermediateStockProposal.vue`). Prérequis sur `feat/industry-flow` : `PersonalStockReader` (#1324, `PersonalStock::$locationsByTypeId`), `IndustryProjectBomLine::plannedStructureId`, `ProjectContainerStations` (résolution d'une structure du plan en `locationId`).

## Règles

1. Chaque ligne du proposeur (`IntermediateStockProposalLine`) garde son total perso et gagne `stockByStation` : liste de `{locationId:int, locationName:string, systemName:string|null, quantity:int, onSite:bool|null}`. Source : `PersonalStock::$locationsByTypeId[typeId]['locations']` (hangars perso et containers perso déclarés, #1324). Un type sans entrée donne une liste vide.
2. Le stock des containers du projet (`containerQuantity`) n'entre pas dans la ventilation : c'est le stock du projet, déjà dans sa colonne.
3. Station de la ligne : `plannedStructureId` (UUID d'une configuration de structure) résolu parmi les configurations du propriétaire (`IndustryStructureConfigRepository::findByUser`), puis `getLocationId()`. La ligne gagne `plannedStationName: string|null` (nom de la configuration).
4. Station résolue : chaque entrée reçoit `onSite = (locationId === station)`, l'entrée de la station passe en tête, les autres gardent l'ordre du lecteur (quantité décroissante). Aucune entrée à la station : la liste ne contient que des `onSite=false`, `plannedStationName` reste renseigné.
5. Sans `plannedStructureId`, UUID invalide, configuration absente chez le propriétaire ou sans `locationId` : `plannedStationName = null`, `onSite = null` partout, ordre inchangé. Une station inconnue n'est pas « hors site ».
6. Rien d'autre ne change : `personalQuantity`, `containerQuantity`, `stockQuantity`, `proposedQuantity`, le choix des lignes, leur tri, les états `not_solo` et `assets_unknown`. Aucune filtration par station.
7. `ProjectIntermediateStockProposalResource::$lines` expose `stockByStation` et `plannedStationName` (null conservés, `skip_null_values: false` déjà en place). Aucun nouvel endpoint, aucune écriture, accès inchangé (`assertOwner`).
8. Front : types TS `IntermediateStockProposalLine` (`stockByStation`, `plannedStationName`) et `IntermediateStockStationQuantity`. Sous chaque ligne du tableau, une sous-ligne `intermediate-stock-proposal-stations-<bomItemId>` liste les stations : nom, système, quantité formatée.
   - `onSite === true` : mise en évidence cyan et badge « Sur place ».
   - `onSite === false` : atténuée et badge « Hors site ».
   - `onSite === null` : ni badge ni mise en évidence.
   - Station connue sans aucune entrée sur place : une mention « Rien sur place à {station} ».
   - Liste vide : pas de sous-ligne.
   - Le champ quantité et la case ne changent pas.
9. i18n fr et en : `groupIndustry.intermediateStockProposal.stations.{onSite,offSite,noneOnSite}`. Le glossaire gagne **Stock perso sur place** (même commit).

## Exemples

Ligne Hydrogen Fuel Block, station du plan Ishtar (id 200). Stock perso : 40 à Jita (id 100), 15 à Ishtar.

| Cas | plannedStationName | stockByStation |
|---|---|---|
| station du plan 200 | `Ishtar` | Ishtar 15 onSite=true, Jita 40 onSite=false |
| aucune ligne à Ishtar | `Ishtar` | Jita 40 onSite=false |
| pas de structure prévue | null | Jita 40 null, Ishtar 15 null (ordre du lecteur : 40 puis 15) |
| config sans locationId | null | tout `onSite=null` |
| UUID de structure d'un autre utilisateur | null | tout `onSite=null` |
| aucun stock du type | `Ishtar` | liste vide |

## Cas limites

- Deux lignes d'un même type ne coexistent pas (le proposeur retient la plus grosse ligne du composant) : une station par ligne.
- Un container perso déclaré à la station du plan compte sur place (le lecteur l'ajoute déjà aux locations).
- Total perso et somme de la ventilation : les deux viennent de la même lecture, aucune réconciliation ajoutée.

## Hors périmètre

Pivot Advisor (#1167), Slot Tracker (#1168), ventilation du stock du projet par station (#1294, #1307), filtrage ou changement de la quantité proposée, structures d'un autre utilisateur.

## Décisions

- Résolution de la station par les configurations du propriétaire, comme `ProjectContainerStations` (pas de lecture inter-utilisateurs).
- Station non résolue : `null` (inconnu), jamais `false`, jamais de valeur par défaut plausible.
- Pas de nouveau service : le proposeur reçoit `IndustryStructureConfigRepository` ; ventilation construite par un petit objet valeur `IntermediateStockStationQuantity`.
