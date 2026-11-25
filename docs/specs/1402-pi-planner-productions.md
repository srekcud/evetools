# Spec #1402 : onglet Planificateur PI, productions, arbre, produire ou acheter

Mode nuit : validée en autonomie, décisions notées en bas. Maquette locale non versionnée : `pi-production-mockup-2026-11-25.html` (`aggregate`, `makeCost`, `renderTree`). Les constantes de la maquette ne sont jamais recopiées : recettes, tiers, volumes et noms viennent de la SDE importée.

## Contexte

Le module PI suit l'extraction mais ne dit pas quoi installer. Le Planificateur est la première brique du simulateur PI : un joueur choisit des produits P1 à P4 à sortir par jour, voit l'arbre complet jusqu'au P0, décide pour chaque étape de produire ou d'acheter, et lit le coût, le bénéfice et les usines nécessaires. Aucune donnée de joueur n'entre dans ce lot.

## Backend

1. Ressource API Platform `PlanetaryPlanner`, `GET /planetary/planner`, `security: is_granted('ROLE_USER')`, provider `PlanetaryPlannerProvider`, identifiant fixe `planner` (modèle : `PlanetaryRevenueResource`). Pas d'écriture, pas de Mercure (aucune action asynchrone : lecture de la SDE et du cache de prix).
2. Contenu de la réponse, construit par un service `PlanetaryPlannerCatalog` (`src/Service/Planetary/`) :
   - `recipes` : un élément par schéma SDE `PlanetSchematic` (68), `{outputTypeId, outputQuantity, cycleSeconds, inputs: [{typeId, quantity}]}` ;
   - `types` : chaque type cité par une recette (sorties et entrées), `{typeId, name, tier, volume}` ;
   - `prices` : `{typeId, sell, buy}` pour chaque type de `types`, depuis `JitaMarketService::getPrices()` et `getBuyPrices()` (cache, pas de repli ESI à la requête). Un type sans prix : `null`, jamais `0`.
3. Tier d'un type : `PlanetaryProductionCalculator::findProductTier()` (groupes de marché P1 à P4), `P0` pour un type d'entrée qui n'est sorti d'aucun schéma. Un type de sortie sans tier trouvé : erreur explicite (donnée SDE incohérente), pas de P0 par défaut.
4. Volume : `InvType::getVolume()`, `null` si absent (non remplacé par une constante). Nom : `InvType::getTypeName()`.
5. Un schéma sans sortie ou sans entrée (SDE incomplète) lève une erreur explicite : la SDE importée est la source de vérité, rien n'est écarté en silence.

## Frontend : calcul (`frontend/src/utils/planetaryPlanner.ts`, fonctions pures)

6. Un produit à `q` par jour demande, pour chaque entrée, `q × quantitéEntrée / quantitéSortie` par jour.
7. Usines d'un produit fabriqué : `parJour / (quantitéSortie × 86400 / cycleSeconds)` (fractionnaire). Par tier : somme fractionnaire sur tous les produits fabriqués du tier, puis plafond (`ceil`, tolérance `1e-9`). Les usines d'un même tier de productions différentes sont donc regroupées.
8. Choix produire ou acheter : un par produit, partagé par toutes les productions et appliqué partout où le produit apparaît comme entrée. Défaut : P1 acheté, P2 à P4 produits. Le produit visé d'une production est toujours produit (le choix ne s'y applique pas, il s'applique là où ce produit sert d'entrée à une autre production). Un produit acheté n'a pas d'usine et son sous-arbre n'est ni compté ni affiché actif.
9. Coût unitaire. Produit acheté : prix de la source. Produit fabriqué P2 à P4 : `Σ(entrée × coût unitaire de l'entrée selon son propre choix) / quantitéSortie + prix × taxe`. P1 fabriqué : `prix × taxe` seulement (extraction non valorisée, pas comparé à l'achat). Un prix manquant sur un maillon rend le coût `null` (« inconnu »), jamais 0 ; le `null` remonte à toute la chaîne.
10. Bénéfice par jour d'une production : `q × (prix − coût unitaire)`, `null` si l'un des deux est inconnu. Total : somme des bénéfices connus ; si une production est inconnue, le total est marqué incomplet.
11. Achats par semaine : `Σ(parJour × 7 × prix)` des produits achetés atteints par les productions, quantités par type additionnées entre productions ; un type acheté sans prix est listé comme « prix inconnu » et exclu du total. Estimation seulement : pas de multibuy (les achats vivent dans Stock).
12. Extraction P0 par heure : `parJour / 24` pour chaque P0 atteint, additionné entre productions, par ressource.
13. ★ : sur chaque produit P2 à P4 non visé, `produire` si `coût produire ≤ prix`, sinon `acheter` ; aucun ★ sur un P1, sur un produit visé, ni quand un prix manque. « Tout au moins cher » applique ces ★ aux seuls produits qui en ont un.
14. Paramètres (état de page, pas de persistance) : source de prix `jita-sell` (défaut) ou `jita-buy`, taxe POCO en % (défaut 4, entre 0 et 100), jours entre passages (défaut 4, minimum 0,5 ; stocké pour #1403, sans effet sur ce lot). La taxe est un réglage du joueur, pas une donnée ESI.

## Frontend : écran

15. Onglets de `/planetary` : Planificateur (`planner`, premier), Colonies, Revenus. Colonies reste l'onglet par défaut. Sans colonie (colonies chargées, liste vide) et sans `?tab=` explicite, la page ouvre le Planificateur.
16. Store Pinia `planetaryPlanner` : chargement de la ressource, productions `{typeId, targetPerDay}`, choix explicites, paramètres. Les actions sont les seules à écrire l'état ; #1403 à #1405 le lisent.
17. Composants : liste des productions (ajout par sélecteur groupé P4, P3, P2, P1 ; objectif par jour ; retrait ; production sélectionnée), arbre de la production sélectionnée (indenté jusqu'au P0, par ligne : par jour, usines, coût produire, prix, bascule Produire/Acheter, ★), paramètres, synthèse (coût unitaire de la production sélectionnée, bénéfice par jour, achats par semaine, usines par tier, extraction P0 par heure). Direction HUD EVE, noms longs testés, `useEveImages` pour les icônes, `formatIsk`.
18. Ajout d'une production : objectif par défaut = sortie d'une usine pendant un jour (`quantitéSortie × 86400 / cycleSeconds` : 24 pour un P4, 72 pour un P3, 120 pour un P2, 960 pour un P1). Un objectif `<= 0` ou non numérique est refusé (champ en erreur, calcul inchangé).
19. Prix inconnu : cellule « inconnu », pas de ★, bénéfice « inconnu », ligne de synthèse marquée incomplète.
20. Pas d'état vide silencieux : sans production, le texte invite à en ajouter une.

## Exemples chiffrés (tests rouges, recettes réduites)

Recettes : P1 `A` (sortie 20, cycle 1800, entrée P0 `x` 3000) ; P1 `C` (idem, entrée P0 `y`) ; P2 `B` (sortie 5, cycle 3600, entrées `A` 40, `C` 40) ; P3 `D` (sortie 3, cycle 3600, entrées `B` 10, `E` 10 avec `E` un autre P2).

| Cas | Entrée | Attendu |
|---|---|---|
| Usines P2 | `B` à 120/j | 120 / (5×86400/3600) = 1 usine P2 |
| Entrées par jour | `B` à 120/j | `A` 960/j, `C` 960/j |
| P1 acheté (défaut) | `B` 120/j | usines P1 = 0, extraction P0 = 0, achats `A` et `C` |
| P1 fabriqué | `A` fabriqué, 960/j | usines P1 = 960/(20×86400/1800) = 1 ; P0 `x` = 960×3000/20 = 144 000/j = 6 000/h |
| Regroupement | productions `B` 120/j et `D` 18/j (`D` consomme `B` 60/j, `B` fabriqué) | usines P2 = ceil(1 + 0,5) = 2 ; usines P3 = ceil(0,25) = 1 |
| Choix partagé | `B` acheté | `B` acheté dans toutes les productions où il est entrée ; sa propre production visée reste fabriquée |
| Coût fabriqué | `A`, `C` achetés à 100 chacun, `B` à taxe 4 % et prix `B` 1 000 | coût `B` = (40×100 + 40×100)/5 + 1 000×0,04 = 1 640 |
| ★ | coût produire 1 640, prix 1 000 | acheter ★ ; coût produire 800, prix 1 000 : produire ★ |
| Prix manquant | prix de `C` `null` | coût `B` `null`, bénéfice `null`, aucun ★, jamais 0 |
| Bénéfice | `q` 120, prix 1 000, coût 640 | 120 × 360 = 43 200 / jour |

## Cas limites

- Même produit visé deux fois : deux productions distinctes, additionnées.
- Produit visé qui sert aussi d'entrée à une autre production : une seule ligne d'usines pour le tier (cf. regroupement).
- Source de prix changée : tous les coûts, ★ et achats sont recalculés, les choix explicites sont conservés.
- Taxe invalide (vide, négative, > 100) : valeur précédente conservée.
- API en erreur ou SDE vide : bandeau d'erreur existant, onglet Colonies non affecté.

## Accès et fraîcheur

Données publiques (SDE, prix Jita en cache) sans identifiant reçu d'un autre périmètre : `ROLE_USER` seulement, pas de contrôle d'appartenance. Fraîcheur des prix : celle de `JitaMarketService` (synchro planifiée), pas de repli ESI à la requête.

## Tests existants contredits ou modifiés

Aucun attendu. À vérifier en phase rouge (5a) : tests des onglets de `PlanetaryInteraction.vue`, exhaustivité des routes et des ressources API (`grep` sur `PlanetaryRevenueResource`).

## Hors périmètre

Planètes nécessaires, CPU/PG, disposition et comparaison aux emplacements (#1403) ; classement « Que produire ? » (#1404) ; templates et persistance du plan (#1405) ; « Suivre ce plan » (#1406) ; comparaison usines du plan contre colonies ; rendement des extracteurs du joueur ; onglets Approvisionnement, Stock ; volumes SDE dans `usePlanetaryHelpers` (bug 1 du plan, issue séparée).

## Décisions (mode nuit)

- Prix : Jita vendeur ou acheteur, depuis le cache (pas de structure ni de hub) ; les prix de hub viennent avec l'onglet Stock.
- Libellé « taxes POCO » (maquette) à la place de « sales tax » (issue) : la maquette l'applique à chaque unité fabriquée.
- Calcul côté front (le joueur change choix, objectifs et taxe sans aller-retour) ; le backend ne sert que recettes, types et prix.
- Pas de persistance du plan avant #1405 ; le store Pinia survit à la navigation dans l'application, pas au rechargement.
- `daysBetweenRounds` est saisi mais sans effet ici (consommé par #1403).

## Glossaire

Ajoutés : **Production planifiée**, **Produire ou acheter**, **Taxes POCO (planificateur)**.
