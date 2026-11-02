# Spec #190 M5 — Commande ponctuelle de remise à 0 du ME des étapes de réaction

**Statut : rédigée le 2026-11-02.** Suite de la [spec #191-190-287](191-190-287-manual-step-create-and-update.md) (implémentée et fusionnée), qui laissait M5 hors lot. Sylvain a tranché le 2026-11-02 : **option A**, implémenter la commande dans un lot séparé, lancer un `--dry-run` puis le vrai passage, **avant** `app:industry:regenerate-multi-unit-steps` (#284). Journal : `.claude/tasks/loop-2026-10-31.md` (Blocage #190 M5).

## Contexte

Le PATCH d'une étape refuse désormais un ME non nul sur une réaction (M1, **400** `Cannot set a material efficiency on a reaction step`), le POST d'un job sur une réaction pose ME 0 (M2, M3), la génération et la régénération des étapes posent ME 0 / TE 0 sur une réaction. Mais les étapes de réaction déjà en base gardent leur ME enregistré, et tous les lecteurs du ME le lisent tel quel (M6) : la cascade (`IndustryStepCalculator::needOfConsumer()`), la liste d'achats et l'arbre de production (`ProductionStepNeedsResolver`, l. 106). Un ME 10 sur une réaction y retire 10 % des matériaux, alors qu'une réaction n'a pas de ME en jeu.

Origine probable des valeurs : la colonne `me_level` des étapes vaut 10 par défaut (`IndustryProjectStep::$meLevel = 10`), et la migration `Version20260209200000` a recopié le ME du projet dans toutes les étapes, réactions comprises. L'étendue réelle se mesure avec les requêtes de la section Actions prod.

État vérifié dans le code au 2026-11-02 (branche `fix/group-production-tree`, 3c403159) :

| Élément | Aujourd'hui |
|---|---|
| Commandes de correction (`src/Command/`) | `RecalculateReactionSellPricesCommand` (#278) et `RegenerateMultiUnitStepsCommand` (#284) : `#[AsCommand]`, `extends Command`, service injecté, option `--dry-run` (`InputOption::VALUE_NONE`, `Only show what would be done, do not save`), sortie par `ProjectCorrectionConsoleReport::print($io, $outcomes, $dryRun, 'No project to …')`. Aucune commande du dépôt n'a d'option `--force` |
| `ProjectByProjectCorrection` (`src/Service/Industry/`) | Classe de base des deux services : `correctProjects($isSelected, $correct)` parcourt `IndustryProjectRepository::findIdsInCreationOrder()` (ordre `createdAt` puis `id`), charge un projet à la fois, `clear()` après chacun dans un `finally`, une exception rend le projet `failed` avec son message, un `EntityManager` fermé rend les suivants `failed` (`not processed: the entity manager was closed by the failure of project <id>`). Elle ne fait aucun `flush` : chaque service écrit le sien |
| `ProjectCorrectionConsoleReport` | Une ligne `<uuid> <libellé> (<runs> runs): <résultat>`, puis le tableau `['Status', 'Count']` (`Changed`, `Unchanged`, `Skipped`, `Failed`, `Total`), puis `Dry run - no changes were saved` en `--dry-run`. Code `FAILURE` dès qu'un projet est `failed`, en `--dry-run` aussi |
| `IndustryStepCalculator::recalculateStepQuantities()` | Cascade du besoin des consommateurs vers les matériaux (`SplitGroupQuantities::applyNeedToSteps()` : runs fixés gardés #185, jobs en trop supprimés #131), puis **`flush` seulement si une étape a changé ou un job a été supprimé**. Appelants : `UpdateStepProcessor`, `CreateStepProcessor`, `DeleteStepProcessor`, `IndustryJobMatcher` |
| `UpdateStepProcessor::update()` | `meLevel` différent de l'actuel : `setMeLevel()`, cascade, puis un second `flush()` inconditionnel (l. 140) qui écrit le ME même si la cascade n'a rien changé |
| `ProjectStatus` | `active`, `completed`, `archived` (colonne `industry_projects.status`, `VARCHAR(20)`) |
| `IndustryProjectStep` | `activityType` (`manufacturing`, `reaction`, `copy`), `meLevel` (INT NOT NULL), `purchased`, `runsFixed`, `inStockQuantity`, `splitGroupId` ; table `industry_project_steps`, nommage `underscore_number_aware` |
| `ProductionStepGraph::productionStepsByProduct()` | Toute étape d'activité autre que `copy` est une étape de production, achetée, en stock ou fixée comprises : son ME compte dans le besoin de ses enfants |

## Règles métier

Les conventions C1 à C7 de la [spec #278-284](278-284-existing-projects-backfill-commands.md) s'appliquent telles quelles (service dans `src/Service/Industry/` sur `ProjectByProjectCorrection`, un projet à la fois avec `clear()`, échec `failed` sans écriture, sortie par `ProjectCorrectionConsoleReport`, code de sortie, `--dry-run` sans écriture ni Mercure). Les règles ci-dessous les précisent pour cette commande.

- **R1 — Nom et options.** `app:industry:reset-reaction-me-levels`, description `Reset to 0 the material efficiency of the reaction steps of the active projects and recalculate their steps`. Une seule option : `--dry-run` (`InputOption::VALUE_NONE`, `Only show what would be done, do not save`). Sans option, la commande écrit. Service : `ReactionStepMeLevelReset` (nom indicatif), `run(bool $dryRun): list<ProjectCorrectionOutcome>`. Aucun candidat : `No project to reset`, code `SUCCESS`.
- **R2 — Sélection.** Un projet est sélectionné si son statut est `active` et s'il a au moins une étape d'activité `reaction` dont le ME enregistré est différent de 0. Ordre : `createdAt` puis `id` (`findIdsInCreationOrder()`). Projets `completed` et `archived` : non sélectionnés (texte de l'option A validé). Projet sans étape de réaction, ou dont toutes les réactions sont à ME 0 : non sélectionné.
- **R3 — Étapes remises à 0.** Toutes les étapes `reaction` du projet de ME différent de 0, **chaque job compté à part** : un groupe découpé dont deux jobs sont à ME 10 compte 2. Les jobs du même groupe déjà à ME 0 ne sont pas touchés. Une réaction **achetée**, **en stock** ou à **runs fixés** est remise à 0 comme les autres : son ME compte dans le besoin de ses enfants (`ProductionStepGraph`), et un PATCH `meLevel: 0` sur elle est accepté aujourd'hui avec la même cascade. Marques achetée, stock, runs fixés, structure, TE, jobs matchés et achats : gardés. Étapes de fabrication et de copie : jamais touchées. ME du projet lui-même (`industry_projects.me_level`) : non touché (hors périmètre de #191-190-287).
- **R4 — Recalcul.** Après la remise à 0 de toutes les étapes de R3, une seule cascade sur le projet par la règle de `IndustryStepCalculator` (celle du PATCH) : besoin des enfants recalculé avec ME 0, runs fixés gardés (#185), jobs en trop supprimés (#131), `splitIndex` et `totalGroupRuns` remis en ordre. Aucune autre règle : pas de régénération, pas de recalcul des estimations figées (`estimatedMaterialCost`, `estimatedJobCost`, prix de vente estimé).
- **R5 — Recalcul sans écriture.** `IndustryStepCalculator` expose la cascade sans `flush` (nom indicatif `applyStepQuantities(IndustryProject): array`, mêmes étapes rendues que `recalculateStepQuantities()`). `recalculateStepQuantities()` devient cette méthode suivie de son `flush` conditionnel actuel : ses appelants et leurs tests ne changent pas. Le service appelle la variante sans `flush` dans les deux modes.
- **R6 — Écriture, un projet à la fois.** Hors `--dry-run`, le service fait **un seul `flush`** après R3 et R4, **toujours**, même si la cascade n'a rien changé (sinon le ME remis à 0 ne serait pas écrit, voir R-E5). Un `flush` Doctrine est une transaction : ME et quantités d'un projet sont écrits ensemble ou pas du tout. Pas de transaction englobant plusieurs projets : un projet corrigé reste corrigé si un suivant échoue (C2).
- **R7 — `--dry-run`.** Même sélection, mêmes R3 et R4 en mémoire, même ligne ; aucun `flush`. Le `clear()` de `ProjectByProjectCorrection` jette les changements en mémoire (ME, quantités, jobs retirés par `removeStep()`). Rien n'est publié sur Mercure (R11).
- **R8 — Statuts.** `changed` pour tout projet traité sans exception (la sélection garantit au moins un ME à écrire), `failed` sinon. `unchanged` et `skipped` n'existent pas pour cette commande.
- **R9 — Ligne.** `<k> reaction steps ME <m> -> 0, <n> steps recalculated, steps <s> -> <s'>` :
  - `<k>` : nombre d'étapes de R3 ;
  - `<m>` : ME distincts avant, croissants, séparés par `/` (`10`, `2/10`) ;
  - `<n>` : nombre d'étapes rendues par la cascade (étapes dont runs, quantité, `totalGroupRuns` ou `splitIndex` changent) ; la réaction remise à 0 n'y figure que si ses propres runs ou sa quantité changent ;
  - `<s> -> <s'>` : nombre d'étapes du projet avant et après (un job en trop supprimé par R4 le fait baisser).

  En `--dry-run`, les valeurs après viennent du calcul en mémoire. `failed: <message de l'exception>` en cas d'échec (C5). Ce format remplace celui de K1 dans la spec #191-190-287 (ajout des ME avant et du nombre d'étapes).
- **R10 — Échec d'un projet.** Toute exception pendant R3 ou R4 (blueprint d'un enfant sans produit dans le SDE : `Product of blueprint N missing from the SDE` ; cycle entre produits : `Project <id> has a cycle between the products of its steps: …`) rend le projet `failed` avec le message, rien n'est écrit pour lui, les suivants sont traités, code de sortie `FAILURE`, en `--dry-run` aussi (C3, C6). Un `flush` qui échoue ferme l'`EntityManager` : les projets suivants sont `failed` `not processed: …` (C3). Pas de `catch` silencieux, pas d'arrêt silencieux.
- **R11 — Publication Mercure** (option B de Q1, décision de Sylvain du 2026-11-02) : hors `--dry-run`, après le flush d'un projet `changed`, la commande publie `syncCompleted` du type `industry-project` à son propriétaire, comme `regenerateSteps()` de `IndustryProjectFactory` (`MercurePublisherService::syncCompleted($userId, 'industry-project', ...)`), pour recharger son onglet Étapes. Rien n'est publié en `--dry-run`, ni pour un projet inchangé ni pour un projet en échec.
- **R12 — Journalisation.** Sortie console seulement, aucune écriture par `LoggerInterface`. La ligne porte l'UUID du projet, son libellé (`ConsoleProjectLabel` : nom du projet, sinon nom SDE du produit) et ses runs ; jamais l'utilisateur, son e-mail, un personnage ou un identifiant EVE.
- **R13 — Idempotence.** Après un passage réussi, plus aucune réaction du projet n'a un ME non nul : il n'est plus sélectionné. Relancer donne `No project to reset`. Un projet `failed` n'a rien écrit : il est sélectionné de nouveau au passage suivant.
- **R14 — Ordre avec #284.** `regenerate-multi-unit-steps` saute un projet dont la régénération changerait le ME d'une étape reprise (G4 de #278-284, F7) : une réaction à ME 10 suffit. Lancer M5 d'abord retire cette cause de `skipped`. M5 et #278 sont indépendants (#278 ne lit pas les étapes).

Contrat proposé (noms indicatifs, l'implémentation tranche) :

```php
// src/Command/ResetReactionMeLevelsCommand.php : app:industry:reset-reaction-me-levels [--dry-run]

// src/Service/Industry/ReactionStepMeLevelReset.php (extends ProjectByProjectCorrection)
/** @return list<ProjectCorrectionOutcome> */
public function run(bool $dryRun): array

// IndustryStepCalculator
/** @return IndustryProjectStep[] */
public function applyStepQuantities(IndustryProject $project): array // cascade sans flush
public function recalculateStepQuantities(IndustryProject $project): array // inchangé : applyStepQuantities + flush si changement
```

## Exemples chiffrés (tests rouges)

SDE golden (`tests/Fixtures/Golden/sde-blueprint-recipes.json`), sans structure, harnais `BuildsStepProcessorsOnGoldenSde` (`stepCalculatorOnGoldenSde()`) : Fernite Carbide (réaction, 10 000 par run ; 100 Fernite Alloy, 100 Ceramic Powder, 5 Hydrogen Fuel Block par run), Fernite Alloy (réaction, 200 par run ; 100 Scandium, 100 Vanadium, 5 Hydrogen Fuel Block par run), Hydrogen Fuel Block (fabrication, 40 par run, ME 10). Quantité de matériau : `max(runs, ceil(base × runs × (100 − ME) / 100))`. Colonnes `[runs, quantité]`.

Projets de la spec #191-190-287 :
- **FC20** : Fernite Carbide racine 20 runs (200 000, ME 0) ; Fernite Alloy 10 runs (2 000) ; Hydrogen Fuel Block 4 runs (150 = 100 + 50). 3 étapes.
- **FC20-ME10** : FC20 calculé avec ME 10 enregistré sur Fernite Carbide : Alloy `[9, 1 800]` (100 × 20 × 0,9), Hydrogen Fuel Block `[4, 135]` (90 + 45).
- **FC10** : Fernite Carbide racine 10 runs (100 000, ME 0) ; Fernite Alloy en groupe découpé 3 + 2 runs (600, 400, total 5) ; Hydrogen Fuel Block `[2, 75]` (50 + 15 + 10). 4 étapes.

| # | Entrée | Attendu |
|---|---|---|
| R-E1 | Un projet actif FC20-ME10 | Fernite Carbide ME 0 ; Alloy `[10, 2 000]` ; Hydrogen Fuel Block `[4, 150]`, ME 10 gardé (fabrication) ; ligne `<uuid> Fernite Carbide (20 runs): 1 reaction steps ME 10 -> 0, 2 steps recalculated, steps 3 -> 3` ; tableau `Changed 1`, `Failed 0`, `Total 1` ; un `flush` ; code `SUCCESS` |
| R-E2 | R-E1 avec `--dry-run` | même ligne, même tableau, `Dry run - no changes were saved` ; aucun `flush` : ME 10, Alloy `[9, 1 800]`, Hydrogen Fuel Block `[4, 135]` en base ; code `SUCCESS` |
| R-E3 | R-E1 relancé après le vrai passage | `No project to reset`, code `SUCCESS` (R13) |
| R-E4 | FC10 dont les deux jobs de Fernite Alloy sont enregistrés à ME 10 : Hydrogen Fuel Block `[2, 73]` (50 + 14 + 9) | les deux jobs à ME 0, groupe inchangé `[3, 600]`, `[2, 400]`, total 5 (leur besoin vient de Fernite Carbide à ME 0) ; Hydrogen Fuel Block `[2, 75]` ; ligne `… (10 runs): 2 reaction steps ME 10 -> 0, 1 steps recalculated, steps 4 -> 4` |
| R-E5 | FC10 dont seul le job 0 de Fernite Alloy (3 runs) est à ME 2 (job ajouté avant M3) | job 0 à ME 0, job 1 non touché ; Hydrogen Fuel Block reste `[2, 75]` (`ceil(15 × 0,98) = 15`) ; ligne `… 1 reaction steps ME 2 -> 0, 0 steps recalculated, steps 4 -> 4` ; **un `flush`** et ME 0 en base (R6) |
| R-E6 | FC20-ME10 dont Fernite Alloy a ses runs fixés à 9 (`[9, 1 800]`) | Fernite Carbide ME 0 ; Alloy reste `[9, 1 800]`, fixé (#185) ; Hydrogen Fuel Block `[4, 145]` (100 + 45) ; ligne `… 1 reaction steps ME 10 -> 0, 1 steps recalculated, steps 3 -> 3` |
| R-E7 | FC20 dont Fernite Alloy est **acheté** et enregistré à ME 10 : Hydrogen Fuel Block `[4, 145]` (100 + 45) | Alloy ME 0, toujours acheté, `[10, 2 000]` ; Hydrogen Fuel Block `[4, 150]` ; ligne `… 1 reaction steps ME 10 -> 0, 1 steps recalculated, steps 3 -> 3` |
| R-E8 | FC20-ME10 au statut `completed`, le même au statut `archived`, FC20 actif (toutes réactions à ME 0), un projet actif Rifter (aucune réaction) | aucun sélectionné : `No project to reset`, code `SUCCESS` |
| R-E9 | Deux projets actifs FC20-ME10, le plus ancien a un enfant dont le blueprint (999999999) n'a pas de produit dans le SDE | le premier `failed: Product of blueprint 999999999 missing from the SDE`, ME 10 et quantités en base inchangés ; le second `changed` ; tableau `Changed 1`, `Failed 1`, `Total 2` ; code `FAILURE` |
| R-E10 | R-E9 avec `--dry-run` | mêmes lignes, `Dry run - no changes were saved`, aucun `flush`, code `FAILURE` |
| R-E11 | R-E1 dont le `flush` lève (`EntityManager` fermé), suivi d'un second projet FC20-ME10 | le premier `failed` avec le message ; le second `failed: not processed: the entity manager was closed by the failure of project <uuid du premier>` ; code `FAILURE` (règle déjà testée de `ProjectByProjectCorrection`, à couvrir une fois pour ce service) |
| R-E12 | Non-régression `IndustryStepCalculatorTest`, `UpdateStepProcessorTest`, `CreateStepProcessorTest`, `DeleteStepProcessorTest`, `IndustryJobMatcherTest` | inchangés (R5 : `recalculateStepQuantities()` garde son `flush` conditionnel) |

Commande (`CommandTester`, service doublé) : R-E1 (ligne, tableau, `SUCCESS`), R-E2 (avertissement de dry-run, `run(true)` appelé), R-E8 (`No project to reset`), R-E9 (`FAILURE`), comme `RegenerateMultiUnitStepsCommandTest`.

## Cas limites

- **Réaction sans étape enfant** (tous ses matériaux sont des feuilles achetées) : la cascade ne change rien, `0 steps recalculated`, le ME est écrit (R6, même cas que R-E5). La liste d'achats change quand même (feuilles lues avec le ME de l'étape).
- **Groupe découpé dont seuls certains jobs ont un ME non nul** : seuls ceux-là sont remis à 0 et comptés (R3, R-E5). Le besoin du groupe vient de son consommateur, pas de son propre ME : ses runs ne bougent que si le consommateur est lui-même une réaction remise à 0.
- **Réaction racine d'un projet de réaction** (Fernite Carbide en R-E1) : remise à 0 ; elle n'a pas de consommateur, sa quantité ne change pas, seuls ses enfants bougent.
- **Jobs en trop d'avant #131** : la cascade peut supprimer un job d'un groupe resté en trop (même effet qu'un PATCH), ses jobs matchés et achats passent au job gardé (#131) ; visible par `steps <s> -> <s'>`.
- **Données incohérentes déjà en base** (quantités qui ne correspondent plus au besoin pour une autre raison) : la cascade les remet au besoin calculé en même temps ; comptées dans `<n>`. Le `--dry-run` les montre avant.
- **PATCH d'un utilisateur pendant le passage** : aucun verrou, le dernier `flush` gagne sur les lignes communes, comme #284. Le passage dure peu (un projet à la fois) ; le lancer hors des heures d'usage.
- **Projet `completed` ou `archived` qui repasse `active` plus tard** : ses réactions gardent leur ME ; le relancer le corrige (R13), ou un PATCH `meLevel: 0` (accepté par M1).
- **ME de réaction négatif ou supérieur à 10** (non accepté par l'API, `Range(0, 10)`) : sélectionné par R2 (« différent de 0 »), remis à 0.
- **TE d'une réaction non nul** : gardé (hors périmètre de #191-190-287).

## Montants visibles qui changent

Sur les projets actifs sélectionnés seulement, au moment du vrai passage :

- **Étapes** (onglet Étapes) : runs et quantités des enfants d'une réaction remise à 0 augmentent ; libellé `ME n` déjà masqué sur les réactions (M4). R-E1 : Fernite Alloy 9 → 10 runs (1 800 → 2 000), Hydrogen Fuel Block 135 → 150.
- **Liste d'achats et arbre de production** (`ProductionStepNeedsResolver`, ME de l'étape) : plus de matériaux pour chaque réaction remise à 0. R-E1 : Ceramic Powder 1 800 → 2 000 (feuille de Fernite Carbide), Scandium 900 → 1 000 et Vanadium 900 → 1 000 (Fernite Alloy 9 → 10 runs) ; matériaux de Hydrogen Fuel Block inchangés (4 runs avant et après).
- **Onglet Estimation des coûts** : coût d'installation des étapes dont les runs changent (spec #7, R1 : runs de l'étape), coût matière lu à la demande.
- **Inchangés** : `estimatedMaterialCost`, `estimatedJobCost`, prix de vente estimé et taxe estimée (figés à la création, R4) ; projets terminés et archivés.

## Décisions prises en autonomie

1. **`--dry-run` en option, écriture par défaut** (R1) : c'est la convention des deux commandes de correction voisines (#278, #284) et de `ResolveAllStructuresCommand` ; aucune commande du dépôt n'a `--force`. Le journal de déploiement impose le `--dry-run` d'abord. Écarté : dry-run par défaut avec `--force`, plus sûr seul mais incohérent avec les deux commandes lancées dans la même release.
2. **Cascade sans `flush` extraite du calculateur** (R5) plutôt qu'un `--dry-run` dans une transaction annulée : la transaction annulée écrirait puis verrouillerait les lignes pendant le calcul ; la règle de cascade reste à un seul endroit, ses appelants ne changent pas.
3. **`flush` inconditionnel du service** (R6), comme le second `flush` de `UpdateStepProcessor` : le `flush` du calculateur dépend des étapes changées, pas du ME.
4. **Réactions achetées, en stock ou fixées incluses** (R3) : M6 garde une seule source (le ME enregistré) ; les exclure laisserait un ME de réaction non nul que le PATCH refuse désormais de saisir mais que la cascade lit.
5. **Pas de `unchanged`** (R8) : la sélection porte sur la donnée à corriger (ME), l'écriture du ME est toujours un changement.
6. **Ligne enrichie** (R9) : ME avant et nombre d'étapes avant/après, pour lire au `--dry-run` l'ampleur du changement et les jobs supprimés par la cascade.

## Questions ouvertes

- **Q1 — Publication Mercure : tranchée** le 2026-11-02, option B (R11).

## Tests existants appelés à casser

Aucun. `recalculateStepQuantities()` garde son contrat (R5). La spec #191-190-287 (K1 à K4) n'a pas de test écrit : ses cas sont repris et complétés ici (R-E1, R-E2, R-E8, R-E9).

Nouveaux : `ReactionStepMeLevelResetTest` (R-E1 à R-E11, groupe `issue-190`), `ResetReactionMeLevelsCommandTest` (`CommandTester`), un test de `applyStepQuantities()` sans `flush` dans `IndustryStepCalculatorTest`.

## Documentation

- `docs/modules/industry.md` : ajouter `ReactionStepMeLevelReset` et `ResetReactionMeLevelsCommand` au tableau des classes, `applyStepQuantities()` à `IndustryStepCalculator`.
- `docs/glossary.md`, **Correction ponctuelle de projets** : ajouter #190 et `ReactionStepMeLevelReset` (à l'implémentation).

## Actions prod

**Avant le déploiement**, en lecture seule (tables et colonnes vérifiées : `#[ORM\Table]` des entités, migrations, nommage `underscore_number_aware`) :

```sql
-- P1. Étendue par statut : projets et étapes de réaction à ME non nul
SELECT p.status,
       COUNT(DISTINCT p.id) AS projects,
       COUNT(s.id) AS reaction_steps_with_me,
       array_agg(DISTINCT s.me_level ORDER BY s.me_level) AS me_levels
FROM industry_projects p
JOIN industry_project_steps s ON s.project_id = p.id
WHERE s.activity_type = 'reaction' AND s.me_level <> 0
GROUP BY p.status
ORDER BY p.status;

-- P2. Projets que la commande sélectionnera (R2), dans son ordre, avec les cas particuliers de R3
SELECT p.id, p.user_id, p.created_at, p.product_type_id, p.runs,
       COUNT(s.id) AS reaction_steps_with_me,
       array_agg(DISTINCT s.me_level ORDER BY s.me_level) AS me_levels,
       COUNT(*) FILTER (WHERE s.split_group_id IS NOT NULL) AS in_split_group,
       COUNT(*) FILTER (WHERE s.purchased) AS purchased,
       COUNT(*) FILTER (WHERE s.runs_fixed) AS runs_fixed,
       COUNT(*) FILTER (WHERE s.in_stock_quantity > 0) AS in_stock
FROM industry_projects p
JOIN industry_project_steps s ON s.project_id = p.id
WHERE p.status = 'active' AND s.activity_type = 'reaction' AND s.me_level <> 0
GROUP BY p.id, p.user_id, p.created_at, p.product_type_id, p.runs
ORDER BY p.created_at, p.id;
```

`user_id` sert seulement à prévenir les propriétaires si Sylvain le décide ; il ne figure pas dans la sortie de la commande (R12). Les projets de P2 qui figurent aussi dans la requête #284 de la spec #278-284 sont ceux que #284 aurait sautés sans M5.

**Après le déploiement** de la release qui contient M1 (PATCH 400) et ce lot, dans cet ordre, avant `regenerate-multi-unit-steps` :

```bash
docker compose exec app php bin/console app:industry:reset-reaction-me-levels --dry-run
docker compose exec app php bin/console app:industry:reset-reaction-me-levels
# puis, si la release les contient : recalculate-reaction-sell-prices (#278) et regenerate-multi-unit-steps (#284),
# chacun --dry-run d'abord (spec #278-284)
```

Le nombre de lignes du `--dry-run` doit égaler le nombre de lignes de P2. Code de sortie non nul : lire les lignes `failed`, corriger la cause, relancer la même commande (seuls les projets non corrigés sont repris, R13). Aucune migration. À ajouter à la checklist de déploiement (mémoire `prod-deploy-actions`).
