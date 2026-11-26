# Spec #1406 : suivre un plan comme cible sur Colonies et Revenus

Mode nuit : validée en autonomie, décisions en bas. Pile sur #1405 (templates) et sur #1413 (#1380, besoins d'entrées, et #1382). Maquette locale non versionnée : bandeau « Plan suivi » de Colonies, boîte « Usines » du Planificateur, ligne « prévu contre réel » de Revenus. Décision déjà prise (parcours PI du 25/11) : le plan ne pilote jamais les besoins ; l'Approvisionnement et le Stock restent calculés sur les colonies et le stock réels. Un plan suivi est une cible : écarts d'usines et de ventes affichés, rien d'autre.

## Contexte

Après avoir décidé quoi installer (#1402 à #1405), le joueur installe en jeu puis veut savoir où il en est : combien d'usines manquent par tier, et si les ventes réelles rejoignent ce que le plan prévoyait.

## Backend

1. `PlanetaryPlanTemplate` gagne `followed` (booléen, faux par défaut, migration). Au plus un template suivi par utilisateur : suivre l'un arrête de suivre l'autre (décision, voir bas).
2. `PATCH /planetary/templates/{id}` (`application/merge-patch+json`, corps `{followed: bool}`, `ROLE_USER`) : `followed` obligatoire booléen strict (422 sinon) ; id inexistant, malformé ou d'un autre utilisateur : 404 ; mettre `true` met `false` sur tous les autres templates de l'utilisateur dans la même transaction ; mettre `false` sur un template non suivi ne change rien. Réponse 200 avec la ressource, qui expose maintenant `followed`. Un template créé par `POST` n'est jamais suivi.
3. `GET /planetary/planner/account` (#1403) gagne `factories` : nombre d'usines installées dans les colonies de l'utilisateur, par tier de leur produit (`{P1, P2, P3, P4}`, entiers, 0 si aucune). Une usine est une installation dont `PlanetaryPin::isFactory()` est vrai ; son tier est celui du produit du schéma (`PlanetaryProductionCalculator::findProductTier()` sur la sortie de `getSchematicMap()`). Un schéma inconnu ou un tier introuvable lève une erreur explicite (pas de comptage silencieux).

## Frontend : calcul (`frontend/src/utils/planetaryFollow.ts`, fonctions pures)

4. `followedTargets(template, catalog)` : avec `buildPlan` (#1402) aux réglages du template : `factoriesByTier` (`{P1..P4}`), `salesPerDay` = `Σ objectif/j × prix de vente` de la source du template (`null` si un prix manque), `profitPerDay` (total de `buildPlan`, `null` si incomplet), `incomplete`.
5. `compareFactories(planned, real)` : par tier P1 à P4 `{tier, planned, real, gap, state}` avec `gap = real − planned` ; `state` : `missing` si `gap < 0`, `free` si `gap > 0`, `ok` si 0, `unknown` si `real` est inconnu (compte non chargé). Un tier planifié à 0 et réel à 0 est `ok` et n'est pas affiché dans le bandeau.
6. `compareSales(plannedSalesPerDay, revenue)` : réel = `realisedIsk / days` de `/planetary/revenue` (ventes réelles du wallet par jour sur la période) ; `{planned, real, gap, ratio}` avec `gap = real − planned`, `ratio = real / planned` ; `planned` nul (0) ou inconnu : `ratio` `null`. Les ventes réelles (brutes) se comparent aux ventes prévues (brutes), jamais au bénéfice.

## Frontend : état et écran

7. Store : `templates` portent `followed` ; getters `followedTemplate` (le template suivi ou `null`), `factoryComparison` (`compareFactories` sur le plan courant du Planificateur contre le compte), `followedFactoryComparison` (idem sur le template suivi) ; actions `followTemplate(id)`, `unfollowTemplate(id)` (PATCH, met à jour la liste locale : un seul `followed` vrai), `followCurrentPlan(name?)` (enregistre le plan courant comme template puis le suit ; nom vide : « Plan du JJ/MM/AAAA » ou le nom anglais équivalent). Un échec laisse la liste inchangée et pose l'erreur du store. `loadFollowContext()` charge catalogue, compte et templates (appelée par Colonies et Revenus pour ne pas dépendre de l'ouverture du Planificateur).
8. Planificateur : bouton « Suivre ces plans » (désactivé sans production) et, dans « Mes templates », un bouton Suivre / Ne plus suivre par ligne ; boîte « Usines : plan contre tes colonies » (par tier P1 à P4 : plan, colonies, écart « manque n » ou « n libres » ou « ok », « inconnu » sans compte) sur le plan courant.
9. Colonies : bandeau « Plan suivi » (nom, tiers avec écarts via `followedFactoryComparison`, bouton « Ouvrir le plan » : bascule sur le Planificateur avec ce template chargé) affiché seulement s'il existe un plan suivi ; chargement et erreur sans bloquer la liste des colonies.
10. Revenus : ligne « Prévu contre réel » (ventes prévues par jour, ventes réelles par jour, écart, ratio ; bénéfice prévu par jour en information) avec lien vers le Planificateur, seulement s'il existe un plan suivi ; « inconnu » pour un prix manquant ; la période suit la période choisie de Revenus.
11. Direction HUD EVE, noms longs, montants avec `formatIsk`.

## Exemples chiffrés (tests rouges)

| Cas | Attendu |
|---|---|
| Écart d'usines | plan P4 1, P3 3, P2 8 ; colonies P4 1, P3 1, P2 10 : P4 `ok`, P3 manque 2, P2 libres 2 |
| Compte inconnu | plan P3 3 sans compte chargé : `state` `unknown`, `gap` `null` |
| Tier à zéro | plan et colonies à 0 en P1 : `ok`, absent du bandeau |
| Ventes prévues | `B` 120/j à 1 000 : 120 000 par jour ; un prix manquant : `null` |
| Ventes réelles | `realisedIsk` 900 000 sur 30 jours : 30 000 par jour |
| Écart de ventes | prévu 120 000, réel 30 000 : écart −90 000, ratio 0,25 ; prévu 0 : ratio `null` |
| Un seul suivi | suivre B alors que A est suivi : A `followed` faux, B vrai |
| API : PATCH | `{followed: true}` : 200 avec `followed` vrai ; `{followed: "yes"}` ou corps sans `followed` : 422 ; id d'un autre : 404 ; mauvais content type : 415 |
| API : compte | 2 usines P2, 1 P3 sur les colonies d'Alice : `factories` `{P1: 0, P2: 2, P3: 1, P4: 0}` ; colonies de Bob jamais comptées |

## Cas limites

- Plan suivi dont un produit a disparu de la SDE : bandeau « recette introuvable », aucun écart calculé.
- Suppression du template suivi : plus aucun plan suivi, bandeau et ligne disparaissent.
- Deux onglets : l'état suivi vient du serveur, le dernier PATCH gagne.

## Accès et fraîcheur

Même périmètre que #1405 (utilisateur du jeton, 404 sur l'id d'un autre) ; `factories` ne lit que les colonies de l'utilisateur (`findByUser`) ; fraîcheur des colonies : dernière synchro (30 min).

## Tests existants modifiés

Fixtures et mocks de `piPlannerTab.spec.ts` et des specs de Colonies et Revenus (appels `/planetary/planner`, `/planetary/planner/account`, `/planetary/templates` quand Colonies ou Revenus chargent le contexte) ; constructeur de `PlanetaryPlannerAccountReader` (nouvelles dépendances) ; JSON des templates (`followed`).

## Hors périmètre

Liens « Filtrer les colonies du plan » et « Colonies filtrées sur le tier » : ils demandent le tier d'une colonie et un filtre par tier, livrés par #1383 (PR #1423, non fusionnée, filtre par état) ; plusieurs plans suivis ensemble ; alertes sur écart ; cible de planètes ; lien depuis Revenus vers un bénéfice net réel (bénéfice de production de #1388).

## Décisions (mode nuit)

- Un seul plan suivi par utilisateur (la question « plusieurs plans suivis » reste ouverte : recommandation, une liste quand le besoin se confirme) ; suivre un plan est un attribut serveur d'un template, pas un état de navigateur.
- Suivre le plan courant l'enregistre d'abord comme template.
- Revenus compare des ventes brutes prévues et réelles (le bénéfice net réel n'existe pas encore sur cette base) ; le bénéfice prévu est affiché à titre d'information.
- Le plan ne pilote toujours ni l'Approvisionnement ni le Stock.
