# Spec #706 : apport d'intermédiaire, le stock réduit la BOM d'un projet solo

Mode nuit (2026-11-11) : spec rédigée sans gate, décisions D1 à D3 de `.claude/tasks/intermediate-stock-plan.md` appliquées, aucune rouverte. Note de câblage fusionnée ici (section Câblage).

## Contexte

`IndustryProjectService::planOf` ne passe aucun `startingStock`. Un intermédiaire déjà en hangar est donc refabriqué avec toute sa descendance. Le noyau sait réduire (R9 de `docs/specs/industry-engine.md` : stock consommé avant les runs, couvert en totalité = aucun job ni matière dessous). Un apport `material` sur une ligne de job est déjà accepté par `ProjectContributionService::submit` et augmente `fulfilledQuantity`, sans effet sur le plan.

## Règles

1. **Apport d'intermédiaire** : contribution `material` approuvée sur une ligne de job (`jobGroup` `component` ou `final`). Le stock d'un type = somme de ces apports sur toutes les lignes de job de ce type (lecture par un nouveau finder du `IndustryProjectContributionRepository`, jamais `fulfilledQuantity`, qui mêle aussi les apports `job_install`, `bpc`, `line_rental`).
2. **Plan** : `planOf` passe ce stock en `startingStock` (par typeId) : tous les appelants (BOM, achat/fabrication, structures, besoins de jobs, recherche, santé des structures, sourcing) voient le plan réduit. Le noyau (`ProductionPlanner`) ne change pas.
3. **Produit final** : le noyau ne réduit pas les cibles. `targetsOf` réduit les runs de la cible : `runsEffectifs = ceil((runs × unitésParRun − stock) / unitésParRun)`, minimum 0. À 0, la cible sort de la demande de planification (si toutes sortent, `planOf` ne doit pas planter : vérifier `planWithStructuresOf` sans cible, sinon retourner un plan vide).
4. **Ligne de job** : `requiredQuantity` = `runs × unitésParRun` + `stockConsommé`, où `stockConsommé = min(stock, demande)` (demande d'un intermédiaire : `Intermediate::$demand` ; d'un produit final : `runs × unitésParRun`). `runs` et `surplusQuantity` viennent du plan réduit. Le reste à faire d'une ligne (`requiredQuantity − fulfilledQuantity`) vaut alors la production prévue ; un stock supérieur à la demande laisse un reste à 0 sans erreur (`max(0, …)` existant).
5. **Couverture totale** : un intermédiaire ou un produit final couvert en totalité garde sa ligne de job avec `runs = 0`, `requiredQuantity = stockConsommé`, aucune structure planifiée (`plannedStructureId` et `plannedStructureStatus` à `null`), `surplusQuantity = 0` pour un composant. Aucune ligne de job ni de matière en dessous (le noyau n'en émet pas). Une ligne à 0 run ne génère ni copie de blueprint ni invention (`replaceT2BlueprintCopiesByInventions`, `copyLinesOf`). Sans ce maintien, la ligne qui porte l'apport serait « supprimée » et le recalcul refusé.
6. **ME/TE de la ligne à 0 run** : ceux que le planificateur appliquerait (final : ME/TE du produit du projet ; composant : réaction 0/0, sinon blueprint possédé, sinon `MANUFACTURED_INTERMEDIATE_ME/TE`), pour que la clé de ligne (`lineKeyOf`) retombe sur la ligne existante.
7. **Plusieurs lignes d'un même type** (jobs scindés par plafond de BPC) : le stock consommé est ajouté à la ligne de plus grands runs (rang 0 de `reconcileBomLines`) ; limite connue, pas de répartition par ligne.
8. **Déclencheurs du recalcul** : `buildBom($project, $project->getOwner(), refuseToDropWorkedLines: true)` après (a) `submit` d'un apport d'intermédiaire en solo (approbation immédiate), (b) `approve` d'un apport d'intermédiaire en attente (reliquat d'avant le correctif), (c) `reject` d'un apport d'intermédiaire approuvé (doublon d'un dérivé, seule voie qui retire un apport approuvé aujourd'hui : il n'existe pas d'annulation). Un apport d'une autre nature (matière, `job_install`…) ne recalcule rien.
9. **Refus explicites (D3)** : le recalcul en mode strict lève `\RuntimeException` (traduite en `\DomainException` par le service d'apport, donc 400 par le processeur) si : (a) une ligne de job liée à un job ESI verrait ses runs tomber sous la somme des runs liés (`IndustryProjectBomLine::getJobMatches()`) ; (b) une ligne de feuille a déjà un `fulfilledQuantity` supérieur à son nouveau `requiredQuantity` ; (c) une ligne portant achat, lien ou apport disparaît du plan (contrôle existant `assertNoWorkedLineIsDropped`). Ces contrôles s'ajoutent au mode strict existant, appliqué aussi à `UpdateProjectProcessor`.
10. **Atomicité** : l'apport et le recalcul se valident ensemble ou pas du tout. Un refus (règle 9) ne laisse ni apport approuvé ni `fulfilledQuantity` incrémenté, ni BOM modifiée (transaction, `wrapInTransaction`).
11. **Projet de groupe** : `submit` d'un apport `material` sur une ligne de job lève `\DomainException('…intermediate contribution… solo project…')` si `!$project->isSoloProjectOf($contributor)`. Un projet solo devenu groupe garde ses apports approuvés dans le stock (le finder ne filtre pas sur la nature solo), donc la BOM ne regrossit pas ; aucun nouvel apport possible.
12. **Valorisation** : inchangée (`resolveMaterialValue`, prix Jita du jour d'apport, `DEPOSIT_DAY_PERCENTILE`). Le coût du projet compte l'apport à cette valeur.
13. **Brouillon et statuts** : projet terminé = pas de recalcul (`buildBom` lève déjà). Autres statuts : recalcul identique.

## Exemples chiffrés (golden SDE, `tests/Fixtures/Golden/sde-blueprint-recipes.json`)

Projet solo : 1 run de Fernite Carbide (réaction, 10 000 unités par run, ME/TE 0) ; son composant Hydrogen Fuel Block (manufacturing, ME 10 TE 20) est la demande `D` du plan de base (lue sur le plan sans stock, à fixer par le test-writer depuis le harnais `GroupIndustryBomGoldenCharacterizationTest`, constantes `FERNITE_CARBIDE`, `HYDROGEN_FUEL_BLOCK`).

| Cas | Entrée | Attendu |
|---|---|---|
| Base | aucun apport | plan inchangé (garde : les 5 lectures existantes du golden restent vertes) |
| Couverture partielle | apport de `S` Hydrogen Fuel Block, `0 < S < D` | runs HFB = `ceil((D − S) / 40)` (< runs de base) ; feuilles sous HFB réduites ; ligne HFB : `requiredQuantity = runs × 40 + S`, `fulfilledQuantity = S`, reste = production |
| Couverture totale | apport `S ≥ D` | ligne HFB à `runs = 0`, `requiredQuantity = D`, plus aucune feuille propre à HFB (les feuilles de Fernite Carbide hors HFB restent) ; `planOf` ne lève rien |
| Produit final | apport de 1 Fernite Carbide sur le produit final, 1 run demandé | plan sans job Fernite Carbide, ligne finale à `runs = 0`, `requiredQuantity = 10 000`, plus aucune feuille |
| Refus job lié | un job ESI lié à HFB pour tous ses runs, apport qui ramène les runs en dessous | `\DomainException`, apport absent, `fulfilledQuantity` inchangé |
| Refus feuille apportée | feuille déjà apportée au-delà du nouveau besoin | `\DomainException`, rien de modifié |
| Groupe | membre accepté autre que le propriétaire, apport sur ligne de job | `\DomainException` explicite |
| Solo devenu groupe | apport approuvé avant l'invitation, puis `buildBom` | BOM réduite identique à avant l'invitation ; nouvel apport refusé |
| Valorisation | apport de `S` | `estimatedValue = S × prix Jita (5 %)` |

## Cas limites

- Stock d'un type qui n'est pas dans le plan (blacklisté ou plus consommé) : sans effet sur le plan, l'apport reste compté dans les contributions.
- Type en réaction : l'unité par run vient de la formule (10 000 pour Fernite Carbide), jamais 1.
- `submit` : le test `quantity > getRemainingQuantity()` s'applique à la ligne avant recalcul ; inchangé.
- Un seul apport `material` par membre et par ligne (`validateAntiDuplicate`) : pas d'ajustement de quantité dans ce lot (proposition de stock = #708).
- Plafond `MAX_BOM_LINES` : sans effet, le plan ne fait que rétrécir (les lignes à 0 run comptent, bornées par les lignes de base).

## Accès et fraîcheur

Aucun nouvel endpoint ni ressource. `SubmitContributionProcessor` garde le contrôle membre accepté ; `ReviewContributionProcessor` garde `assertAdminOrOwner`. Le recalcul s'exécute pour le propriétaire du projet (`$project->getOwner()`) : structures, blueprints et système favori du propriétaire, comme `UpdateProjectProcessor`. Le stock ne lit que les contributions du projet (filtre `c.project`).

## Câblage

- `IndustryProjectContributionRepository` : finder « stock d'intermédiaires par typeId » (somme des `Material` `Approved` dont la ligne a `isJob = true`, groupé par `typeId` de la ligne).
- `IndustryProjectService` : `planOf` (stock + `targetsOf` réduit), `buildBom`/`jobLinesOf` (lignes à 0 run, `requiredQuantity` avec stock), `reconcileBomLines`/`updateLineInPlace` (mode strict étendu, règle 9).
- `ProjectContributionService` : reçoit `IndustryProjectService` (pas de cycle : celui-ci ne dépend que du repository) ; appel du recalcul dans `submit`, `approve`, `reject` ; garde « solo » dans `submit`.
- Consommateurs de lignes de job qui supposent `runs ≥ 1` (grep `isJob()` : `ProjectPlanningInputLoader` crée un `Runs`, `ProjectChainBuilder`, `ProjectReactionStepsBuilder`, `JobMaterialNeedsMapper`, `ProjectCockpitInputFactory`, `RemainingBomCost`, `ChainMaterialSupply`, `MaterialsToSourceFactory`, `UnsoldProductsOfProject`) : ignorer ou tolérer les lignes à 0 run, un test par consommateur qui lèverait sinon. Ajouter `IndustryProjectBomLine::isCoveredByStock()` (job et `runs === 0`) comme prédicat unique.
- Aucun nouveau topic Mercure : l'évènement `contribution_submitted` existant couvre le rafraîchissement de la BOM (action synchrone).

## Tests existants contredits ou modifiés

Aucun attendu. `GroupIndustryBomGoldenCharacterizationTest` et `ProjectContributionServiceTest` doivent rester verts sans modification d'assertion ; `ProjectContributionServiceTest` gagne un collaborateur (constructeur) : à adapter en phase rouge, sans toucher une assertion.

## Hors périmètre

Proposition du stock détecté dans les assets (#708), Chaîne et container (#707), surplus d'intermédiaires d'un projet terminé (#709), front (bouton ou badge), annulation d'un apport approuvé (n'existe pas, à trancher par Sylvain), répartition du stock entre lignes scindées d'un même type, suppression de champ ou réglage.

## Glossaire

- **Stock de départ** : retirer « La BOM d'un projet n'en passe aucun » ; la BOM d'un projet en passe la somme de ses apports d'intermédiaire.
- **Apport d'intermédiaire** (Intermediate contribution) : contribution `material` approuvée sur une ligne de job d'un projet ; sa somme par type est le stock de départ du plan du projet. Solo seulement à la création ; conservée si le projet devient de groupe.
