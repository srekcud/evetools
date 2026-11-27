# Spec #1452 : chaîne de production complète sur une seule planète

Mode nuit : validée en autonomie, décisions en bas. Pile sur #1403 (`docs/specs/1403-pi-planets-needed.md`, `frontend/src/utils/planetaryPlanets.ts`). Deux lots empilés : lot 1 Planificateur (front), lot 2 Approvisionnement (backend). Aucune constante de la SDE n'est recopiée : CPU, PG et capacités viennent de `planetTypes` du catalogue (#1403).

## Contexte

Le Planificateur dimensionne une planète par tier (P4, P3, P2) et traite chaque tier comme alimenté par launchpad. Beaucoup de joueurs font P2 vers P4 sur une seule planète (P1 importé, intermédiaires fabriqués et consommés sur place) : pour un plan P4 modeste, le Planificateur annonce 3 planètes là où une seule suffit. Quatre templates de jeu (centre de commande 5, sauf le dernier au niveau 4) tiennent P2 à P4 sur une planète : 21 usines avancées + 1 usine haute technologie + 3 launchpads + 1 storage (Barren), 20 + 1 + 3 + 2, 21 + 1 + 3 + 1, et P2 à P3 en 20 usines avancées + 2 launchpads + 1 storage (Temperate, niveau 4).

## Règles : lot 1, Planificateur (`frontend/src/utils/planetaryPlanets.ts`)

1. Nouveau type `PlanetMode = 'auto' | 'perTier' | 'wholeChain'`, 7e paramètre optionnel de `buildPlanetPlan`, valeur par défaut de la fonction `'perTier'` (comportement #1403 inchangé pour tout appelant qui ne le passe pas). Le store `planetaryPlanner` garde `planetMode` en état de page (pas de persistance, pas dans `PlannerSettings` ni dans les templates : aucun changement d'API), défaut `'auto'`, action `setPlanetMode`. `evaluateTemplate` reçoit le même paramètre optionnel et `PiPlannerTemplates.vue` lui passe `store.planetMode`.
2. Chaîne = les nœuds actifs fabriqués aux tiers P2, P3, P4 (`factories !== null`). Ses usines par tier sont celles de la règle 7 de #1402 (`factoriesOfTier`). Un tier absent n'est pas hébergé. P1 fabriqué reste sur les planètes d'extraction (règle 7 de #1403) ; il entre dans la chaîne comme tout produit qui n'y est pas fabriqué.
3. Entrées de la chaîne par jour = Σ `perDay × volume SDE` des enfants des nœuds fabriqués P2 à P4 qui ne sont pas eux-mêmes fabriqués P2 à P4 (P1, produits achetés). Sorties par jour = Σ `targetPerDay × volume` des productions dont le produit est fabriqué P2 à P4. Un volume `null` rend la chaîne `unknownVolume` (aucune planète comptée, total incomplet).
4. Disposition d'une planète de chaîne : pour `N` planètes identiques (N de 1 à `max(usines d'un tier, ⌈(entrées + sorties) × jours / capacité d'un launchpad⌉)`) et `k` launchpads (1 à 6), `N` convient pour `k` quand : `Σ_tiers ⌈usines du tier / N⌉ × CPU installation + k × CPU launchpad ≤ CPU command center`, idem PG, et `(entrées + sorties) / N × jours ≤ k × capacité`. Installation : P2 et P3 `advanced`, P4 `highTech` (#1403). On garde le plus petit `N`, puis le plus petit `k`. Types de planète possibles : ceux dont toutes les installations des tiers présents sont non `null` ; résultat différent selon le type : le plus grand `N` et la disposition du type qui le donne (prudent, comme #1403 ; premier du catalogue à égalité). Aucun `N` ne convient : `doesNotFit`, aucune planète comptée.
5. Ligne `chain` (rôle `chain`) : `{role, tiers, planetTypes, factoriesByTier, layout: {launchpads, factoriesPerPlanet: {P2, P3, P4}, dailyVolume, capacity, days} | null, status: 'ok' | 'unknownVolume' | 'doesNotFit', planets}`. Elle remplace les lignes `factory` ; les lignes `extraction` restent.
6. Mode `perTier` : lignes `factory` de #1403. Mode `wholeChain` : ligne `chain` seule (même avec un seul tier présent). Mode `auto` : la ligne `chain` quand au moins deux tiers P2 à P4 sont présents, que son statut est `ok` et que `planets` ≤ somme des planètes des lignes `factory` (égalité : chaîne, pas de transfert entre planètes) ou que cette somme est inconnue ; sinon `perTier`. Un seul tier présent en `auto` : `perTier`. `PlanetPlan.planetMode` porte le mode effectif (`'perTier' | 'wholeChain'`).
7. Totaux, personnages, planètes ajoutées par production (`addedByProduction`) et barre des emplacements lisent les lignes comme avant ; `allocations` ne contient aucune entrée pour une ligne `chain`.
8. Écran (`PiPlannerPlanets.vue`) : sélecteur de mode (auto, par tier, chaîne sur une planète) au-dessus du tableau ; ligne `chain` : tiers présents, besoin « n usines avancées, m haute technologie », disposition « k launchpads + usines par planète », volume sur capacité, planètes ; en `auto`, mention du mode effectif. Textes FR et EN (`pi.planner.planets.*`).

## Règles : lot 2, Approvisionnement (`PlanetaryInputNeedsCalculator`)

9. Sur une colonie, le débit d'un type alimenté par launchpads (`feedRatesByLaunchpad`) est plafonné à son `dailyNetImport` : somme des débits du type sur les launchpads de la colonie ramenée à `dailyNetImport` par un même coefficient (`dailyNetImport / somme`), jamais augmentée. `dailyNetImport` null (extraction du type inconnue) : débits inchangés. Débit ramené à 0 : le type n'est plus listé dans `feeds`. Un intermédiaire fabriqué sur place et passant par un launchpad n'est donc pas une entrée à apporter.
10. Le besoin hebdomadaire du Stock (`weekly`, `weeklyNetNeed`) soustrait déjà la sortie des usines (#1380) : aucun changement. Colonies : `PlanetaryFactoryColonyTracker` (#744) lit déjà la chaîne d'une planète usine, et `colonies[].needs` porte `dailyProducedLocally` : aucun changement, vérifié par un test de garde.

## Exemples chiffrés (tests rouges ; catalogue de `planetaryPlannerFixtures.ts`, jours = 4)

Planètes : Barren 25 415 / 19 000, Temperate 24 000 / 18 000, Lava (pas de `highTech`) ; launchpad 3 600 / 700, capacité 10 000 ; `advanced` 500 / 700 ; `highTech` 1 100 / 400. Volumes : P1 0,38, P2 1,5, P3 3, P4 100. P1 acheté (défaut).

| Cas | Attendu |
|---|---|
| Robotics 72/j, `wholeChain` | usines P2 = 4, P3 = 1, aucune P4 ; entrées 3 840 + 1 920 + 1 920 P1 × 0,38 = 2 918,4, sorties 72 × 3 = 216, total 3 134,4 m³/j : N = 1, k = 2 (k = 1 : 40 000 / 10 000 ne passe pas), 1 planète, types Barren, Lava, Temperate |
| Robotics 72/j, `auto` | même ligne `chain`, 1 planète ; `perTier` donne plus de planètes (P3 et P2 séparées) |
| Broadcast Node 24/j, `wholeChain` | P2 = 8, P3 = 2, P4 = 1 ; total 8 236,8 m³/j : N = 1, k = 4 ; types Barren, Temperate seulement (Lava exclu faute de `highTech`) |
| Broadcast Node 144/j, `wholeChain` | P2 = 96, P3 = 12, P4 = 6 ; Barren donne N = 6, k = 4, Temperate N = 7, k = 3 : on retient le plus grand N, soit 7 planètes, disposition de Temperate (3 launchpads, par planète P2 14, P3 2, P4 1) |
| Broadcast Node 144/j, `auto` | chaîne, 7 planètes (`perTier` : P2 7 + P3 1 + P4 2 = 10) |
| Un seul tier (Coolant 120/j), `auto` | lignes `factory` inchangées |
| Volume SDE nul sur un P1 de la chaîne | statut `unknownVolume`, `planets` null, total incomplet |
| Aucune planète ne loge une usine de chaque tier | `doesNotFit`, `planets` null |
| Défaut sans 7e paramètre | résultat identique à #1403 (tests existants verts) |
| Lot 2 : P2 fabriqué sur place 120/j, consommé par une usine P3 alimentée par un launchpad de la même colonie | `dailyNetImport` du P2 = 0, pas de ligne P2 dans `feeds` ; les P1 alimentant l'usine P2 restent |
| Lot 2 : besoin 120/j, production locale 40/j, deux launchpads à 90 et 30 | `dailyNetImport` 80 : débits 60 et 20 |
| Lot 2 : extraction du type inconnue | débits inchangés |

## Cas limites

Production sans P2 à P4 fabriqué (tout acheté) : aucune ligne `chain`. Plusieurs productions : usines additionnées par tier (règle 7 de #1402), sorties additionnées. Le mode change : tout est recalculé, productions et choix conservés. Mode inconnu reçu du store : erreur explicite, pas de repli.

## Accès et fraîcheur

Aucun nouvel endpoint ni identifiant. Lot 2 lit les colonies et routes déjà lues par `calculate()` (même périmètre : colonies de l'utilisateur, dernière synchro 30 min).

## Tests existants contredits ou modifiés

Aucun attendu : le défaut du 7e paramètre et du 5e argument de `evaluateTemplate` conserve #1403. `planetaryPlannerKeys.spec.ts` (clés i18n FR/EN) doit voir les nouvelles clés dans les deux langues. À vérifier en phase rouge (5a) : `PiPlannerPlanets` (lignes de tableau) et `PlanetaryInputNeedsCalculatorTest` (débits de launchpad).

## Hors périmètre

Storage facility (CPU et PG) et launchpads de sortie distincts : non modélisés, le catalogue n'a pas leur charge ; regroupement partiel de deux tiers sur une planète et d'un troisième ailleurs ; planète qui consomme le surplus P2 d'une autre (#1458) ; Stock et couverture (#1453) ; persistance du mode dans les templates ; import de layout (#933).

## Décisions (mode nuit)

- Mode en état de page, pas dans `PlannerSettings` : un champ persisté changerait la validation du template côté serveur (`CreatePlanTemplateProcessor`), donc l'API ; à reprendre si Sylvain veut le mode dans les templates.
- Défaut `auto` dans l'écran, `perTier` pour un appelant sans mode : les nombres des tests #1403 ne bougent pas.
- Les planètes d'une chaîne sont identiques et portent chacune `⌈usines / N⌉` par tier (borne haute, usines légèrement plus nombreuses que le plan) ; les entrées P1 sont réparties à parts égales.
- Storage non modélisé : le calcul peut loger un peu trop ; les marges des quatre templates du testeur (CPU 22 400 sur 25 415, PG 17 200 sur 19 000 avec 3 launchpads) laissent la place d'un storage.
- Colonies et Stock : déjà corrects (règle 10), pas de lot 3.
