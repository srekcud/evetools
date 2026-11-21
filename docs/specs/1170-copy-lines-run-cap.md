# Spec #1170 — Les jobs d'un projet bâti sur des copies ne dépassent pas les runs d'une copie

## Contexte

Un projet créé avec 10 copies de 5 runs (`ItemName ME TE x5 *10`) porte 10 lignes produit de même type, 5 runs chacune. Le propriétaire possède ces BPC : `OriginalBlueprintFinder::bestUsesOwnedBy` les renvoie comme blueprint possédé utilisé, `original = false`. `ProductionPlanning::targetsWithOwnedEfficiency` fusionne alors les lignes du produit en une cible de 50 runs (clé `owned:type:ME:TE`), puis la seconde passe plafonne cette cible à `Recipe::$maxProductionLimit` du SDE. Avec une limite de 10 : 5 jobs de 10 runs, qu'aucune copie de 5 runs ne peut lancer en jeu. Sans blueprint possédé, les lignes ne sont pas fusionnées et chaque ligne donne déjà son job : le défaut ne touche que la fusion d'un BPC possédé.

Cas réel : CONCORD Rogue Analysis Beacon, 10 BPC de 5 runs, facteur de matériaux 0,940104. Les 5 jobs de 10 runs arrondissent chacun leurs matériaux : 5 Isogen, 5 Zydrine et 5 Broadcast Node de moins que les 10 jobs de 5 runs réels.

## Règles

- **R1 — Plafond d'un groupe fusionné.** Pour un produit dont des lignes sont fusionnées sous un BPC possédé (`OwnedBlueprintUse::$original` faux, activité de fabrication), le plafond de runs des jobs du produit est `min(maxProductionLimit, plus petit nombre de runs d'une ligne du groupe)`. Une copie ne porte jamais plus de runs que la ligne qui la représente : un job de ce plafond tient dans n'importe quelle copie du groupe.
- **R2 — Découpe.** Celle du noyau (D2) : `ceil(runs / plafond)` jobs, runs répartis également, les premiers jobs prenant le reste, matériaux et arrondi ME recalculés job par job (R13).
- **R3 — Une seule ligne.** Une ligne seule garde le plafond du SDE : son nombre de runs est au plus celui du plafond, `min` ne change rien.
- **R4 — BPO possédé.** Un BPO possédé garde son comportement (#411) : lignes fusionnées, aucun plafond.
- **R5 — Sans blueprint possédé.** Comportement inchangé : une cible par ligne, plafond du SDE par ligne.
- **R6 — Réaction.** Une réaction n'est jamais fusionnée ni plafonnée par ces règles (`targetsWithOwnedEfficiency` la laisse intacte).
- **R7 — Hors lignes du projet.** Seuls les produits des lignes (cibles) reçoivent ce plafond. Si le type est aussi un intermédiaire du plan, ses jobs d'intermédiaire suivent le même plafond (jobs plus petits, jamais plus gros que le SDE).

## Exemples chiffrés

Catalogue de test : produit cible, limite SDE 50, un matériau de base 330 par run, ME 10 TE 20, un BPC possédé de ME 10 TE 20.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | 10 lignes de 5 runs, BPC possédé | 1 job de 50 runs | 10 jobs de 5 runs, matériau 1 485 par job (330 × 5 × 0,9) |
| E2 | Lignes de 5 et de 10 runs, BPC possédé | 1 job de 15 runs | 3 jobs de 5 runs |
| E3 | Une ligne de 120 runs, BPC possédé, limite 50 | 3 jobs de 40 | inchangé |
| E4 | 10 lignes de 5 runs, BPO possédé | 1 job de 50 runs | inchangé |
| E5 | 10 lignes de 5 runs, aucun blueprint possédé | 10 jobs de 5 runs | inchangé |
| E6 | Deux lignes de 6 runs, BPC possédé, limite 5 (test existant `testTargetsOfTheSameProductMerge…`) | 3 jobs de 4 | inchangé : `min(5, 6) = 5` |

## Cas limites

- Ligne dont les runs sont réduits par le stock de départ du produit : le plafond suit les runs de la cible reçue par `ProductionPlanning` (après stock). Un stock du produit fini est rare et ne produit que des jobs plus petits, jamais plus gros qu'une copie. Hors périmètre : la réduction du stock se fait déjà ligne par ligne dans `IndustryProjectService::runsLeftAfterStock`.
- Ligne entièrement couverte par le stock : absente des cibles, ne compte pas dans le plus petit nombre de runs.
- Structure du plan : `referenceRunsOf` voit toujours la cible fusionnée, le choix de structure ne bouge pas.

## Accès et fraîcheur

Aucun accès touché : calcul pur du noyau, pas de nouvelle ressource, pas de migration.

## Tests existants contredits

Aucun. Les assertions de `ProductionPlanningOwnedBlueprintTest` restent vraies (E6).

## Hors périmètre

- Un champ « ligne issue d'une copie » sur `IndustryProjectProduct` (migration, API, front) : la fusion ne dépend que du blueprint possédé, inutile pour ce défaut.
- Une copie dont les runs diffèrent de ceux de sa ligne (la bibliothèque de blueprints porte les vrais runs, ce n'est pas lu ici).
- Le stock par ligne, les BPC sans bibliothèque synchronisée.

## Décisions (mode nuit, recommandation retenue)

- Plafond = plus petit nombre de runs des lignes fusionnées (vs somme exacte par ligne), car un job de ce plafond tient dans toute copie du groupe, sans changer la structure des cibles ni `referenceRunsOf`.
- Pas de nouveau marqueur « copie » sur la ligne produit (pas de migration).

## Glossaire

Aucun terme ajouté. « Planification de production » : le plafond d'un groupe de lignes fusionnées sous un BPC possédé est le plus petit nombre de runs de ses lignes (#1170).
