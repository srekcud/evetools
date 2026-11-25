# Spec #1403 : planètes nécessaires aux productions planifiées

Mode nuit : validée en autonomie, décisions en bas. Pile sur #1402 (`docs/specs/1402-pi-planner-productions.md`, `frontend/src/utils/planetaryPlanner.ts`, store `planetaryPlanner`). Maquette locale non versionnée : `layout`, `planets`, `renderPlanner` (`plPlanets`, `plSlots`). Aucune constante de la maquette n'est recopiée : tout vient de la SDE importée (types, groupes, attributs de dogma).

## Contexte

Le Planificateur dit combien d'usines et d'extraction un plan demande. Il doit dire sur combien de planètes cela tient : par rôle (usine P4, P3, P2, extraction par ressource P0), types de planète possibles, disposition par planète, nombre de planètes, total, personnages nécessaires et comparaison aux emplacements du compte. #1384 (maximum d'emplacements du compte) n'est pas livré : on montre les planètes utilisées par les colonies, le total du plan, et le maximum seulement quand le skill Interplanetary Consolidation (SDE 2495) est déjà en base.

## Backend

1. `GET /planetary/planner` (ressource #1402) gagne deux listes publiques, construites par `PlanetaryPlannerCatalog` depuis la SDE :
   - `planetTypes` : un élément par type de planète, `{name, commandCenter: {cpu, pg}, launchpad: {cpu, pg, capacity}, facilities: {basic, advanced, highTech}}` (chaque installation `{cpu, pg}` ou `null` si ce type de planète n'en a pas). Planète : nom lu sur les types du groupe « Spaceports » (`<Planète> Launchpad`). Command center : celui de plus haute production de CPU pour ce type (niveau 5). Installations : types du groupe « Processors » nommés `<Planète> Basic Industry Facility`, `... Advanced Industry Facility`, `... High-Tech Production Plant`. CPU et PG : attributs de dogma de sortie du command center, de charge des installations ; capacité du launchpad : `InvType::$capacity`. Une donnée absente lève une erreur explicite (jamais de valeur par défaut).
   - `extractionPlanetTypes` : `{typeId, planetTypes: list<string>}` par P0, lu sur les extracteurs du groupe « Extractors » (attribut `harvesterType` = typeId du P0, type de planète = préfixe du nom).
2. Nouvelle ressource `PlanetaryPlannerAccount`, `GET /planetary/planner/account`, `ROLE_USER`, limitée aux personnages de l'utilisateur connecté (colonies via `PlanetaryColonyRepository::findByUser`). Contenu : `usedPlanets` (nombre de colonies), `characters: [{characterId, characterName, usedPlanets, maxPlanets}]` avec `maxPlanets = 1 + niveau d'Interplanetary Consolidation` lu dans `CachedCharacterSkill` (2495), `null` si ce skill n'est pas en base pour le personnage ; `extractionYields: [{typeId, perHourPerPlanet, planets}]`.
3. Rendement d'extraction d'une ressource : pour chaque colonie ayant des extracteurs de ce P0, somme par heure de `ExtractorYieldCalculator::programDailyAverage()` (rendement décroissant de #1381, programme complet de l'extracteur, divisé par 24) de ses extracteurs ; `perHourPerPlanet` = moyenne sur ces colonies, `planets` leur nombre. Une colonie dont un extracteur du P0 n'a pas son rendement calculable (quantité, durée de cycle, installation ou échéance absentes, facteurs SDE absents) est exclue du calcul (pas de valeur par défaut). Mêmes extracteurs et même formule que « Production théorique PI » et « Besoin d'entrées PI », pas de second calcul.

## Frontend : calcul (`frontend/src/utils/planetaryPlanets.ts`, fonctions pures)

4. Volume par jour d'une usine d'un tier : pour chaque produit du plan fabriqué à ce tier, `max(Σ entrées × volume SDE, quantité sortie × volume sortie) × 86400 / cycleSeconds` ; le plus grand du tier. Un volume SDE `null` rend le tier « inconnu ».
5. Disposition d'une planète usine P2, P3 ou P4 : pour `k` de 1 à 6 launchpads, usines = `max(0, min(⌊(CPU command center − k × CPU launchpad) / CPU installation⌋, ⌊(PG command center − k × PG launchpad) / PG installation⌋, ⌊k × capacité launchpad / (volume par jour × jours entre passages)⌋))` ; on garde le `k` qui loge le plus d'usines, le plus petit `k` à égalité. Installation par tier : P2 et P3 `advanced`, P4 `highTech`. Types de planète possibles : ceux dont l'installation du tier n'est pas `null`. Si le calcul diffère selon les types, on retient le plus petit nombre d'usines (prudent). 0 usine : « ne tient pas N jours, passer plus souvent », pas de nombre de planètes.
6. Planètes d'un tier usine : `ceil(usines du tier (règle 7 de #1402) / usines par planète)`.
7. Planètes d'extraction d'une ressource P0 : `ceil(P0 par heure / rendement par planète)`. Rendement : saisi par le joueur s'il y en a un, sinon `perHourPerPlanet` du compte, sinon « inconnu » (aucune planète comptée, total marqué incomplet). Les usines P1 qui consomment ce P0 tournent sur ces planètes (nombre affiché, pas de planète en plus).
8. Total du plan : somme des planètes usine et extraction ; personnages nécessaires `ceil(total / 6)` ; incomplet si une ligne est inconnue. Planètes d'extraction de ressources différentes ne sont jamais fusionnées (types de planète possibles, rendements différents).
9. Planètes ajoutées par production : total du plan sur les productions `0..i` moins le total sur `0..i-1`, avec par tier la ligne « n usines sur emplacements libres, m sur de nouvelles planètes, r libres restantes » (emplacements libres = usines possibles du tier déjà ouvertes par les productions précédentes, moins leurs usines).
10. Barre des emplacements : planètes occupées par production (ajoutées), planètes libres = maximum du compte − total du plan quand tous les personnages ont un `maxPlanets`, sinon pas de barre de libres (« maximum inconnu »). Les colonies déjà utilisées (`usedPlanets`) s'affichent à part, sans être soustraites du plan.

## Frontend : écran et état

11. Dans l'onglet Planificateur : tableau « Planètes nécessaires » (rôle, types de planète possibles, besoin, disposition `k launchpads + f installations · volume sur capacité en N jours`, planètes), ligne total (personnages, emplacements du compte), bandeau de manque, colonne « Planètes ajoutées » dans la liste des productions, barre d'emplacements.
12. Le store charge `/planetary/planner/account` avec le catalogue, garde un rendement saisi par P0 (`extractionYieldOverrides`, état de page) et expose le plan des planètes ; `daysBetweenRounds` (réglage de #1402) est enfin consommé ; minimum 0,5.
13. Rendement saisi invalide (vide, ≤ 0, non numérique) : refusé, valeur précédente conservée.
14. API du compte en erreur : le tableau reste calculé sur le catalogue, rendement et maximum « inconnu », bandeau d'erreur existant.

## Exemples chiffrés (tests rouges)

Command center 25 415 CPU / 19 000 PG ; launchpad 3 600 / 700, capacité 10 000 ; installation `advanced` 500 CPU / 700 PG ; `highTech` 1 100 / 400 ; volume par jour d'une usine 0,75 × 5 × 24 = 90 (P2), jours = 4.

| Cas | Attendu |
|---|---|
| P2, k = 1 | min(⌊21 815/500⌋ = 43, ⌊18 300/700⌋ = 26, ⌊10 000/360⌋ = 27) = 26 |
| P2, k = 2 | min(36, ⌊17 600/700⌋ = 25, 55) = 25 : on garde k = 1 (26) |
| Égalité de k | le plus petit k gagne |
| Volume élevé | volume par jour 3 000, jours 4 : 0 usine pour k = 1 (⌊10 000/12 000⌋), 5 pour k = 6 (min(7, 21, 5)) : disposition k = 6, 5 usines |
| Ne tient pas | volume par jour 30 000, jours 4 : 0 usine pour tout k : « ne tient pas 4 j », aucune planète comptée |
| P4 | `highTech` 1 100 / 400 : k = 1 donne min(19, 45, volume) |
| Planètes d'un tier | 52 usines, 26 par planète : 2 ; 53 usines : 3 |
| Types possibles | P4 : seulement les types dont `highTech` est non null |
| Extraction | 28 100 P0/h demandés, rendement 6 000/h : `ceil` = 5 ; sans rendement ni saisie : « inconnu », total incomplet |
| Personnages | 13 planètes : 3 personnages |
| Ajoutées | production 1 : 5 planètes ; production 1 + 2 : 7 ; la deuxième ajoute 2 |
| Maximum du compte | 2 personnages avec `maxPlanets` 6 et 4 : maximum 10 ; un `null` : maximum inconnu |
| Rendement du compte | deux colonies dont le rendement du calculateur vaut 3 000 et 5 000 P0/h : `perHourPerPlanet` 4 000, `planets` 2 ; une colonie sans installation, échéance ou durée de cycle exclue |

## Accès et fraîcheur

`/planetary/planner/account` : `ROLE_USER`, seulement les colonies et skills des personnages de l'utilisateur (`findByUser`) ; aucun identifiant reçu en paramètre. Fraîcheur : colonies à la dernière synchro (30 min), skills à la dernière synchro des skills (valeur absente = `null`). `/planetary/planner` reste public (SDE).

## Tests existants contredits ou modifiés

Le JSON de `GET /planetary/planner` grandit de deux listes : `PlanetaryPlannerCatalogTest`, `PlanetaryPlannerApiTest` et la fixture front `planetaryPlannerFixtures.ts` doivent fournir les nouvelles listes (ajout, aucune assertion retirée).

## Hors périmètre

Maximum d'emplacements du compte persistant et son écran (#1384) ; rendement décroissant des extracteurs ; liens non comptés ; layouts de colonie (#933) ; réutilisation interne d'une production consommée par une autre (reste « produit en plus », mode « total produit » non décidé) ; classement et templates (#1404, #1405).

## Décisions (mode nuit)

- Types d'installation et de planète lus par groupe et suffixe de nom SDE, sans migration ni table de pins : l'import de `pins` par schéma demanderait une migration et une réimportation de la SDE en production ; à reprendre si le nommage CCP change.
- Maximum d'emplacements = 1 + niveau d'Interplanetary Consolidation, seulement quand le skill est en base ; sinon inconnu.
- Planètes libres = maximum − total du plan ; les colonies existantes sont affichées sans être soustraites.
- Après la fusion de #1381, le rendement du compte vient de `ExtractorYieldCalculator` (avec décroissance) et non d'une formule constante : un seul calcul d'extraction dans l'application.
- Calcul côté front, comme #1402 ; le backend sert les constantes SDE et les données du compte.
