# Spec #1395 : onglet Stock, grille de couverture et liste d'achat unique

Mode nuit : validée en autonomie, décisions en bas. Pile sur #1394 (réglages : lieu maison, hub, couverture visée ; `docs/specs/1394-pi-settings.md`), #1393 (ordres d'achat stockés, `CachedCharacterBuyOrderRepository`) et #1380 (`PlanetaryInputNeedsCalculator`, ligne `weekly`). Maquette locale non versionnée : onglet Stock, grille `covGrid` (colonnes Maison, Hub à rapatrier, Hangar corpo, Conso/sem., Couverture, Ordres d'achat, Couverture avec ordres, À acheter). Aucun prix ici (#1396, #1400).

## Contexte

- Consommation : `PlanetaryInputNeedsCalculator::calculate()` rend `weekly[]` par type ; la consommation de la grille est `weeklyNetNeed` (consommation des usines moins extraction propre moins sorties d'usines, règle 9 de #1380), `null` quand l'extraction d'un type est inconnue.
- Stock par lieu : les assets d'un conteneur gardent l'id du conteneur comme `locationId`. `AssetBrowseRepository::treeCte()` (privé) remonte déjà chaque asset à son lieu racine (`root_location_id`, profondeur 5). Il est réutilisé, pas recopié.
- Hangar corpo : les conteneurs corpo déclarés par l'utilisateur (`PersonalStockContainer`, lus par `PersonalStockReader::containersOf()` / `of()`, #1324) ; pas de lecture libre des assets corpo.
- Le customs office (#1390) n'est pas livré : pas de colonne, voir décisions.

## Backend

1. Ressource `PlanetaryStockResource` (`GET /planetary/stock`, id fixe `stock`, `ROLE_USER`, provider dédié, limité à l'utilisateur connecté) : `homeLocationId`, `homeLocationName`, `hubStructureId`, `hubStructureName`, `coverageWeeks` (réglages #1394), `corpUnknownReason` (?string), `complete` (bool), `rows[]`.
2. Lignes : une par type de `weekly[]` (entrées consommées par les usines), triée par `typeName` puis `typeId`. Champs : `typeId`, `typeName`, `volumePerUnit` (?float, SDE), `weeklyConsumption` (?float = `weeklyNetNeed`), `home`, `hub`, `corp` (?int), `openOrders` (int), `coverageWeeks` (?float), `coverageWithOrdersWeeks` (?float), `toBuy` (?int).
3. `home` : quantité du type, tous personnages de l'utilisateur, dont le lieu racine est `homeLocationId` (conteneurs inclus). `null` quand aucun lieu maison n'est choisi.
4. `hub` (« à rapatrier ») : même lecture au lieu racine `hubStructureId`. Quand le hub est le lieu maison, `hub` vaut 0 (la quantité est déjà dans `home`, jamais comptée deux fois).
5. `corp` : quantité du type dans les conteneurs corpo déclarés dont la station est le lieu maison. Aucun conteneur déclaré (ou aucun au lieu maison) : 0. Une lecture de conteneur inconnue (`PersonalStock::unknownReason()`, division masquée, assets corpo périmés, station non résolue) : `corp` null sur toutes les lignes et `corpUnknownReason` renseigné.
6. `openOrders` : somme de `volumeRemain` des ordres d'achat stockés des personnages de l'utilisateur, pour ce type, dont `locationId` est le hub (`findByCharactersAndTypes`). Les ordres d'un autre lieu ne comptent pas.
7. `coverageWeeks` = (`home` + `hub` + `corp`) / `weeklyConsumption` ; `coverageWithOrdersWeeks` = (`home` + `hub` + `corp` + `openOrders`) / `weeklyConsumption`. `null` si `home`, `corp` ou `weeklyConsumption` est null, et `null` si `weeklyConsumption` vaut 0 (type couvert par la production propre : pas de couverture à afficher).
8. `toBuy` = `ceil(max(0, weeklyConsumption × coverageWeeks_réglage − (home + hub + corp + openOrders)))`. `null` si `home`, `corp` ou `weeklyConsumption` est null (jamais 0 par défaut) ; 0 si `weeklyConsumption` vaut 0.
9. `complete` est faux si l'extraction d'un type est inconnue (une ligne a `weeklyConsumption` null), si aucun lieu maison n'est choisi ou si `corpUnknownReason` est renseigné.
10. Accès : colonies, assets, conteneurs et ordres des seuls personnages de l'utilisateur (aucun identifiant reçu). Fraîcheur : assets à la dernière synchro, ordres à la synchro des ordres (5 min), colonies à la synchro des colonies (30 min). Pas de Mercure, pas de synchro nouvelle, aucun appel ESI.

## Frontend

11. `PiStockTab.vue` (créé par #1394, au-dessus des réglages) affiche, dans l'ordre : le bandeau d'incomplétude (rien de choisi : lien vers les réglages ci-dessous ; `corpUnknownReason` ; consommation inconnue), la grille de couverture, la liste d'achat, puis `PiStockSettings.vue`. Store `planetaryStock` (`load()`, `rows`, `purchaseList`, `error`), rechargé quand un réglage de #1394 est enregistré.
12. Grille : une ligne par type (icône, nom), colonnes Maison, Hub, Hangar corpo, Conso / sem., Couverture (barre à la couverture visée, rouge sous la cible, texte en semaines à une décimale), Ordres d'achat, Couverture avec ordres, À acheter, Décision. Une valeur `null` s'affiche « inconnu » (composant `UnknownValue`), jamais 0.
13. Liste d'achat unique : lignes où `toBuy` > 0, total en m3 (`toBuy × volumePerUnit`, « inconnu » si un volume l'est), bouton « Copier (multibuy) » qui met dans le presse-papier une ligne `<nom> <quantité>` par type, sans séparateur de milliers. Liste vide : message « Rien à acheter ». Une ligne dont `toBuy` est null n'entre pas dans la liste (affichée « inconnu » dans la grille).
14. Colonne Décision : « Produire » ou « Acheter » selon `choices` du store `planetaryPlanner` pour ce type (état de page, #1402), « — » sans choix, avec un lien vers l'onglet Planificateur. Stock n'offre aucun bouton de décision.
15. Aucun libellé, onglet ou réglage existant n'est retiré. FR et EN.

## Exemples chiffrés (tests rouges)

Cible 3 semaines, hub ≠ maison.

| Cas | Attendu |
|---|---|
| Conso 1 000, maison 1 500, hub 500, corp 0, ordres 0 | couverture 2,0 sem. ; à acheter `ceil(3 000 − 2 000)` = 1 000 |
| Même, ordres 400 | couverture avec ordres 2,4 ; à acheter 600 |
| Maison 3 000 | à acheter 0 (jamais négatif), couverture 3,0 |
| Conso 333,4, stock 0 | à acheter `ceil(1 000,2)` = 1 001 |
| Hub = maison, 1 200 au lieu | `home` 1 200, `hub` 0 |
| Items dans un conteneur de la station maison | comptés dans `home` ; un conteneur d'un autre lieu : non |
| Ordres : 400 au hub, 250 à Jita | `openOrders` 400 |
| Conteneur corpo déclaré au lieu maison avec 700 | `corp` 700 |
| Aucun conteneur déclaré | `corp` 0 |
| Division masquée (lecture inconnue) | `corp` null partout, `toBuy` null, `corpUnknownReason` |
| Aucun lieu maison | `home` null, `toBuy` null, `complete` false |
| Extraction inconnue pour un type | `weeklyConsumption` null, couverture et `toBuy` null |
| Conso 0 (couverte par l'extraction) | couverture null, `toBuy` 0 |
| Autre utilisateur | ses assets, conteneurs et ordres n'apparaissent pas |
| Liste d'achat | lignes à 1 001 et 600 → `Robotics 1001\nPlasma 600`, total m3 = Σ quantité × volume |

## Hors périmètre

Colonne customs office et stock « non localisé » (#1390, non livré) ; prix payé, sell hub, signal (#1396, #1400) ; produits d'industrie demandés (#1398) ; colonne hangar corpo hors conteneurs déclarés ; décision produire ou acheter (reste dans le Planificateur).

## Décisions prises en autonomie

- Issue partielle : la colonne customs office dépend de #1390, bloquée ; pas de `Closes`, commentaire sur l'issue.
- Consommation de la grille = `weeklyNetNeed` de #1380 (la maquette soustrait l'extraction propre) ; un type dont l'extraction est inconnue reste « inconnu ».
- Hangar corpo = conteneurs corpo déclarés (#1324), seule lecture corpo déjà scopée sur l'utilisateur ; une lecture inconnue rend la colonne et le « à acheter » inconnus plutôt que de les compter à 0.
- Le hub n'est jamais compté deux fois quand il est le lieu maison.
