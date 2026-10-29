# Spec #278 + #284 — Commandes ponctuelles de correction des projets existants (prix de vente estimé des réactions, étapes des produits à plusieurs unités par run)

**Statut : rédigée en mode nuit le 2026-10-29, à valider par Sylvain.** Aucune question n'a pu être posée : les choix faits en autonomie sont listés dans « Décisions prises en autonomie », ceux qui changeraient un montant ou des étapes sans que les issues le prévoient sont dans « À trancher par Sylvain » et restent hors du lot.

## Contexte

Deux corrections déjà faites ne s'appliquent qu'aux projets créés ou régénérés après elles :

- **#125** (spec `137-125-units-per-run-from-sde.md`, R5) : le **prix de vente estimé** est `meilleur prix × runs × unités par run du producteur`. Avant, le **producteur** n'était cherché qu'en fabrication et le calcul retombait sur 1 unité par run : un projet dont le produit est une **réaction** a gardé un prix de vente estimé (et une taxe estimée) trop bas d'un facteur égal aux unités par run (×10 000 pour Fernite Carbide). Le prix est figé à la création : `CreateProjectProcessor` est le seul à l'écrire.
- **#2** (spec `2-129-project-runs-and-t2-copy.md`, R1) : la racine de l'arbre d'un projet reçoit ses runs ; sa quantité vaut la **quantité produite** (`runs × unités par run`). Avant, les runs étaient lus comme une quantité : 25 runs de Nitrogen Fuel Block (40 par run) donnaient une **étape racine** d'1 run et 25 unités. Les étapes, l'arbre affiché et la **liste d'achats** de ces projets restent faux jusqu'à une **régénération des étapes**. Sylvain a tranché (T2 de la spec #2-129) : commande ponctuelle de régénération.

Ni #2 ni #125 ne sont en production (dernier tag `V0.13`, 2026-02-26 ; les commits #125 `4d685b29` et #2 `2ab440e8` sont sur `main`). Les deux commandes partent dans la même release et se lancent une fois, juste après son déploiement. Aucune migration.

État vérifié dans le code au 2026-10-29 :

| Élément | Aujourd'hui |
|---|---|
| `CreateProjectProcessor::process()` | Calcule et écrit le prix de vente estimé, sa source et la taxe estimée dans un bloc `try { … } catch (\Throwable)` qui journalise `Industry project estimation failed` (`estimation: sell_price`). Prix : Jita (`JitaMarketService::getPrice`) et structure préférée de l'utilisateur (`StructureMarketService::getLowestSellPrice`), le plus haut des deux ; aucun → prix, source et taxe restent `null`. Unités par run : `SplitGroupMaterials::producerOf()` (fabrication, puis réaction ; sans producteur `ProducerMissingFromSdeException`). Taxe : `prix × (courtage + taxe de vente)` des réglages de l'utilisateur, défauts `EveConstants` sans réglages (`sellTaxRate`, privée). Aucune autre classe n'a cette règle |
| `IndustryProjectFactory::regenerateSteps()` | Publie `syncStarted` (Mercure, type `industry-project`), recalcule le plan (arbre, ME/TE des étapes existantes par `withSettingsOfExistingStep`, durées, découpage, **runs fixés** gardés par `jobsKeepingFixedRuns`), l'applique sur place (`applyPlanToExistingSteps` : étape appariée par blueprint, activité et ordre de `splitIndex` ; identifiant, jobs matchés, achats et stock gardés ; étape en trop absorbée ; étape hors plan gardée après le plan), `flush`, puis `syncCompleted` ; en cas d'exception `syncError` puis relance. Côté front, `StepsTab.vue` recharge le projet sur `completed` et affiche le message sur `error` |
| ME/TE à la régénération | Intermédiaire de fabrication : ME/TE de l'étape existante gardés. **Racine** de fabrication : ME/TE du projet (`testChangingTheRunsGivesTheRootStepTheMeAndTeOfTheProject`). **Réaction** : ME 0 / TE 0 (`testRegenerationGivesAReactionStepNoMeNorTeAndKeepsItsStructure`). **Copie** : ME 0 / TE 0. Une régénération peut donc changer le ME/TE d'une étape existante : racine dont le ME/TE a été modifié par PATCH de l'étape (`UpdateStepProcessor` ne touche pas le projet), réaction ou copie à ME/TE non nul |
| Planification sans écriture | N'existe pas : `regenerateSteps()` enchaîne plan, application et `flush`. `jobsKeepingFixedRuns()` appelle `SplitGroupQuantities::applyNeedToSteps()`, qui modifie en mémoire les jobs à runs fixés pendant le plan |
| Commandes (`src/Command/`) | `class … extends Command` + `#[AsCommand(name: 'app:…', description: …)]`, dépendances par constructeur `private readonly`, `SymfonyStyle`. Industrie : `app:industry:regenerate-steps <project-id>`, `app:industry:match-jobs <project-id>`, libellé de projet par `ConsoleProjectLabel::of($project, IndustryCalculationService::getProjectDisplayName($project))`. `--dry-run` existe une fois : `ResolveAllStructuresCommand` (`addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be done, do not save')`, tableau `['Status', 'Count']` en fin, `$io->warning('Dry run - no changes were saved')`). Tests : `tests/Unit/Command/` avec `CommandTester` |
| `IndustryProject` | `status` (`ProjectStatus` : `active`, `completed`, `archived`), `createdAt` (posé au constructeur), `estimatedSellPrice`, `estimatedSellPriceSource`, `estimatedTaxAmount`, `estimatedMaterialCost`, `estimatedJobCost`. `IndustryResourceMapper` affiche `sellPrice ?? estimatedSellPrice` et `taxAmount ?? estimatedTaxAmount`, et en tire le profit |

## Règles métier

### Communes aux deux commandes

- **C1 — Logique hors de la commande.** Chaque commande délègue à un service de `src/Service/Industry/`, testable sans console, qui sélectionne, traite et rend une liste de résultats par projet. La commande lit ses options, appelle le service, écrit une ligne par résultat, le tableau récapitulatif et choisit le code de sortie. Comme `RegenerateProjectStepsCommand` et `MatchProjectJobsCommand` délèguent à la factory et au matcher.
- **C2 — Un projet à la fois.** Le service lit les identifiants des candidats (ordre `createdAt` croissant, puis `id`), charge chaque projet, le traite, écrit (`flush`) s'il est corrigé et hors `--dry-run`, puis vide l'unité de travail (`EntityManagerInterface::clear()`), quel que soit le résultat. Un projet corrigé est enregistré seul : l'échec d'un autre ne l'annule pas.
- **C3 — Échec d'un projet.** Toute exception levée pendant le traitement d'un projet le marque `failed` avec le message de l'exception comme raison ; rien n'est écrit pour lui (le `clear()` jette ses changements en mémoire) ; les projets suivants sont traités. Ce n'est pas un catch silencieux : la raison est dans la sortie et le code de sortie devient non nul. Exception : si l'échec a fermé l'`EntityManager` (`!isOpen()`, échec pendant un `flush`), le service s'arrête ; chaque projet restant est `failed` avec la raison `not processed: the entity manager was closed by the failure of project <id>`.
- **C4 — Statuts d'un résultat.** `changed` (corrigé ; en `--dry-run` : serait corrigé), `unchanged` (sélectionné, mais la règle ne change rien), `skipped` (sélectionné, volontairement laissé tel quel, avec une raison ; #284 seulement), `failed` (C3).
- **C5 — Sortie.** Une ligne par projet sélectionné, `<uuid> <libellé> (<runs> runs): <avant> -> <après>` pour `changed`, `: unchanged` pour `unchanged`, `: skipped: <raison>` et `: failed: <raison>` pour les deux autres. Libellé : `ConsoleProjectLabel` (nom du projet, sinon nom SDE du produit, sinon `type N (name missing from the SDE)`). Puis le tableau `['Status', 'Count']` : `Changed`, `Unchanged`, `Skipped`, `Failed`, `Total`. En `--dry-run`, la même sortie suivie de `Dry run - no changes were saved`. Aucun candidat : `$io->success('No project to …')`, code 0.
- **C6 — Code de sortie.** `Command::FAILURE` dès qu'un projet est `failed`, en `--dry-run` aussi (le vrai passage échouerait) ; sinon `Command::SUCCESS`. `skipped` et `unchanged` ne font pas échouer.
- **C7 — `--dry-run`.** Même sélection, même calcul, mêmes lignes ; aucune écriture en base, aucune publication Mercure.
- **C8 — Unités par run.** Lues par `SplitGroupMaterials::producerOf($productRepository, $project->getProductTypeId())->getQuantity()`, jamais `?? 1`. Producteur absent : `ProducerMissingFromSdeException` (`No blueprint or reaction found for product type ID N`), donc projet `failed` (C3). Le service peut garder le producteur lu par produit le temps d'un passage.

### #278 — `app:industry:recalculate-reaction-sell-prices`

- **P1 — Règle de calcul unique.** La règle du prix de vente estimé sort de `CreateProjectProcessor` dans un service (`EstimatedSellPriceEstimator`, nom indicatif) appelé par le processor et par la commande : même choix du prix (Jita ou structure préférée, le plus haut), même `runs × unités par run du producteur` (C8), même taxe (réglages de l'utilisateur, défauts `EveConstants`). Le processor garde son `try/catch` et son warning ; son comportement ne change pas.
- **P2 — Sélection.** Un projet est sélectionné si :
  1. `createdAt` est strictement antérieur à la valeur de l'option obligatoire `--created-before` (heure du déploiement de la release qui contient #125) ;
  2. son statut est `active` (terminés et archivés : T2) ;
  3. son prix de vente estimé n'est pas `null` (un prix inconnu à la création n'a pas le défaut de #125) ;
  4. son producteur (C8) est une réaction (`activityId` 11) à plus d'une unité par run. Un produit qui a un blueprint de fabrication garde la fabrication comme producteur : son prix utilisait déjà ses unités par run, il n'est pas sélectionné.
- **P3 — Correction.** Prix de vente estimé, source et taxe estimée recalculés par P1, avec les prix du marché, la structure préférée et les réglages de taxe du jour. Les trois valeurs sont écrites ensemble.
- **P4 — Prix absent.** Ni prix Jita ni prix structure : le projet est `failed`, raison `No Jita nor structure sell price for type ID N`, ses trois valeurs restent celles d'avant. Le projet garde une valeur fausse plutôt que de perdre la trace d'un prix : la ligne `failed` le signale et le passage suivant le corrigera.
- **P5 — Inchangé.** Si le calcul redonne exactement le prix, la source et la taxe enregistrés : `unchanged`, rien n'est écrit.
- **P6 — Idempotence.** Le calcul part du prix du marché, pas de la valeur enregistrée : relancer ne multiplie jamais deux fois par les unités par run. Relancé avec les mêmes prix, il ne change rien (P5). Relancé plus tard, il remet les mêmes projets (même `--created-before`) au prix du jour, à la même échelle.
- **P7 — Ligne.** Avant et après : `sell <prix> (<source>), tax <taxe>`, montants en `number_format($montant, 2)`. Exemple : `… Fernite Carbide (8 runs): sell 240.00 (jita), tax 7.20 -> sell 2,400,000.00 (jita), tax 72,000.00`.
- **P8 — Option `--created-before`.** Obligatoire, lue par `new \DateTimeImmutable($valeur)` (ISO 8601 avec fuseau conseillé). Absente : erreur `The --created-before option is required: pass the deployment time of the release that fixed the reaction sell price`, code `FAILURE`, rien n'est lu. Illisible : erreur `Invalid --created-before value: <valeur>`, code `FAILURE`.

### #284 — `app:industry:regenerate-multi-unit-steps`

- **G1 — Étapes racine.** Les étapes racine d'un projet sont ses étapes de profondeur 0 dont le blueprint est celui du producteur (C8) et l'activité celle du producteur (`manufacturing` pour 1, `reaction` pour 11). Plusieurs étapes racine = un **groupe découpé**. Leur quantité est la somme de leurs quantités.
- **G2 — Sélection.** Un projet est sélectionné si son statut est `active` (terminés et archivés : T2), si son producteur a plus d'une unité par run (fabrication ou réaction : fuel blocks, munitions, réactions) et s'il a au moins une étape racine dont la quantité totale est **strictement inférieure à sa quantité produite** (`runs × unités par run`). C'est la signature de #2 : l'ancien calcul donnait une racine de quantité `runs`. Un projet dont les étapes diffèrent d'un plan neuf pour une autre raison (ME/TE d'un ancien calcul, structure, découpage, étape ajoutée à la main) n'est pas sélectionné. Un projet sans étape racine (racine supprimée) non plus.
- **G3 — Aperçu de la régénération.** La factory expose un aperçu sans écriture (`stepRegenerationPreviewOf(IndustryProject)`, nom indicatif) : le plan que `regenerateSteps()` appliquerait, chaque ligne avec l'étape existante qu'elle reprendrait (même appariement que `applyPlanToExistingSteps`) et ses valeurs avant (runs, quantité, ME, TE) et après, plus le nombre d'étapes après (plan + étapes hors plan gardées). Plan et appariement restent écrits une seule fois dans la factory : `regenerateSteps()` et l'aperçu les partagent. L'aperçu ne publie rien sur Mercure, ne fait ni `persist`, ni `remove`, ni `flush` ; les changements qu'il laisse en mémoire (jobs à runs fixés) sont jetés par le `clear()` de C2.
- **G4 — ME/TE gardés.** Si l'aperçu change le ME ou le TE d'au moins une étape existante reprise par le plan, le projet est `skipped`, raison `ME/TE would change on <blueprintTypeId> <activité> job <splitIndex>: <me>/<te> -> <me'>/<te'>` (toutes les étapes concernées, séparées par `, `). Rien n'est écrit. Cas réels : racine dont le ME/TE a été saisi sur l'étape, réaction ou copie à ME/TE non nul d'un ancien calcul.
- **G5 — Inchangé.** Si l'aperçu laisse les étapes racine identiques (nombre de jobs, runs et quantités, dans l'ordre de `splitIndex`) : `unchanged`, rien n'est écrit. Seul cas attendu : racine à runs fixés déjà régénérée (la racine reste au nombre de runs fixé, quantité plafonnée au besoin, #185).
- **G6 — Correction.** Sinon, hors `--dry-run`, `IndustryProjectFactory::regenerateSteps($project)`, sans autre traitement : identifiants des étapes reprises, jobs matchés, achats, stock, structures et runs fixés gardés ; étape de copie d'un **blueprint inventé** déjà en base gardée hors plan (T3 de la spec #2-129) ; Mercure publié pour le propriétaire comme à toute régénération (son onglet Étapes se recharge). Aperçu puis régénération donnent les mêmes étapes qu'une régénération seule.
- **G7 — Ligne.** `root jobs <n>, runs <r>, units <q> -> jobs <n'>, runs <r'>, units <q'>; steps <s> -> <s'>; job matches <m> -> <m'>, purchases <a> -> <a'>`. En `--dry-run`, les valeurs après viennent de l'aperçu (jobs matchés et achats : ceux d'avant) ; sinon, des étapes après la régénération.
- **G8 — Idempotence.** Après correction, la quantité racine vaut la quantité produite : le projet n'est plus sélectionné. Racine à runs fixés : sélectionnée de nouveau, `unchanged` (G5). Relancer ne change donc plus rien.
- **G9 — Estimations figées.** `estimatedMaterialCost`, `estimatedJobCost` et le prix de vente estimé ne sont pas touchés par cette commande (voir T1).

Contrat proposé (noms indicatifs, l'implémentation tranche) :

```php
// src/Service/Industry/EstimatedSellPriceEstimator.php
public function estimateFor(IndustryProject $project): ?EstimatedSellPrice // null : ni Jita ni structure ; lève ProducerMissingFromSdeException
// EstimatedSellPrice : float $amount, string $source ('jita'|'structure'), float $taxAmount

// src/Service/Industry/ReactionProjectSellPriceRecalculation.php
/** @return list<ProjectCorrectionOutcome> */
public function run(\DateTimeImmutable $createdBefore, bool $dryRun): array

// src/Service/Industry/MultiUnitProjectStepsRegeneration.php
/** @return list<ProjectCorrectionOutcome> */
public function run(bool $dryRun): array

// IndustryProjectFactory
public function stepRegenerationPreviewOf(IndustryProject $project): StepRegenerationPreview

// ProjectCorrectionOutcome : projectId, label, runs, status (enum C4), before, after, reason
```

## Exemples chiffrés (tests rouges)

Fernite Carbide : typeId 16673, formule de réaction 46166, 10 000 unités par run. Nitrogen Fuel Block : typeId 4051, blueprint 4314, 40 par run. Taxe de vente du jour : courtage 0,01 + taxe de vente 0,02 = 0,03. Cutoff des exemples #278 : `--created-before=2026-11-01T00:00:00+00:00`, projets créés le 2026-10-15 sauf mention.

### #278

| # | Entrée | Attendu |
|---|---|---|
| E1 | Projet actif Fernite Carbide, 8 runs, enregistré à 240 (`jita`), taxe 7,2 (ancien calcul) ; prix Jita 30, pas de structure préférée | `changed` : 2 400 000 (`jita`), taxe 72 000 (prix Jita 30 × 8 × 10 000, test de l'issue). Ligne `<uuid> Fernite Carbide (8 runs): sell 240.00 (jita), tax 7.20 -> sell 2,400,000.00 (jita), tax 72,000.00` ; code 0 |
| E2 | E1 relancé, mêmes prix | `unchanged`, valeurs identiques, aucun `flush` du projet |
| E3 | E1 en `--dry-run` | même ligne qu'E1, valeurs en base 240 / `jita` / 7,2, `Dry run - no changes were saved`, code 0 |
| E4 | E1 avec une structure préférée à 35 | 2 800 000 (`structure`), taxe 84 000 (même choix que la création) |
| E5 | Projet Fernite Carbide créé le 2026-11-02 (après le cutoff), 2 400 000 enregistré | non sélectionné, aucune ligne |
| E6 | Projet actif Nitrogen Fuel Block (producteur de fabrication à 40 par run) | non sélectionné |
| E7 | Projet actif Fernite Carbide dont le prix de vente estimé est `null` | non sélectionné |
| E8 | Projet Fernite Carbide `completed` à 240 | non sélectionné (T2) |
| E9 | E1 et un second projet de réaction (synthétique, 200 par run) sans prix Jita ni structure, créé avant E1 | second projet `failed: No Jita nor structure sell price for type ID <id>`, valeurs inchangées ; E1 corrigé ; code `FAILURE` |
| E10 | E1 et un projet dont le produit n'a plus de producteur dans le SDE, créé avant E1 | ce projet `failed: No blueprint or reaction found for product type ID <id>` ; E1 corrigé ; code `FAILURE` |
| E11 | Sans `--created-before` ; puis avec `--created-before=not-a-date` | erreurs de P8, code `FAILURE`, aucun projet lu |
| E12 | Aucun candidat | `No project to recalculate`, code 0 |
| E13 | Non-régression `CreateProjectProcessorTest` (Fernite 8 runs → 2 400 000 / 72 000, Rifter, taxes, producteur absent → warning `sell_price`) | inchangé |

### #284

Harnais golden (`BuildsProjectOnGoldenSde`) pour les plans ; intégration (`IndustryProjectFactoryRegenerationTest`) pour l'écriture, le `clear()` et les jobs matchés. « Ancien calcul » = projet créé par la factory puis étape racine remise à la main à l'état d'avant #2 (comme E14 de la spec #2-129).

| # | Entrée | Attendu |
|---|---|---|
| F1 | Projet actif Nitrogen Fuel Block, 25 runs, ME 10, TE 20, sans structure ; racine remise à `[1 run, 25 unités]`, runs non fixés, un job matché et un achat sur la racine | `changed` : racine `[25, 1000, 0, 10, 20, 0, null]` (golden `nitrogen-fuel-block-25runs-me10-npc`), même identifiant, 1 job matché et 1 achat gardés (test de l'issue). Ligne `… Nitrogen Fuel Block (25 runs): root jobs 1, runs 1, units 25 -> jobs 1, runs 25, units 1000; steps 1 -> 1; job matches 1 -> 1, purchases 1 -> 1` ; Mercure `syncStarted` puis `syncCompleted` pour le propriétaire |
| F2 | F1 relancé | non sélectionné (1 000 = 25 × 40), `No project to regenerate` |
| F3 | F1 en `--dry-run` | même ligne qu'F1 ; racine en base toujours `[1, 25]` ; aucune publication Mercure ; code 0 |
| F4 | Projet actif Fernite Carbide, 10 runs, Tatara + Raitaru, durée max 2 j ; racine réaction remise à `[1, 10]` | `changed` : racine `[10, 100000]` ; Ceramic Powder 5 runs, Fernite Alloy 5 runs, Hydrogen Fuel Block 3 runs (golden `fernite-carbide-10runs-tatara-nullsec`, E5 de la spec #2-129) |
| F5 | Projet actif Rifter (1 par run), 10 runs | non sélectionné |
| F6 | F1 dont l'étape racine est à ME 8 (projet à ME 10) | `skipped: ME/TE would change on 4314 manufacturing job 0: 8/20 -> 10/20` ; racine en base `[1, 25]`, ME 8 ; code 0 |
| F7 | F4 dont la réaction Ceramic Powder est enregistrée à ME 10 / TE 20 | `skipped`, raison nommant le blueprint de Ceramic Powder `reaction job 0: 10/20 -> 0/0` |
| F8 | F1 dont la racine a ses runs fixés à 1 | `changed` : racine `[1, 40]` (runs fixés gardés, quantité plafonnée au besoin) ; relancé : sélectionné (40 < 1 000), `unchanged` |
| F9 | F1 au statut `completed` | non sélectionné (T2) |
| F10 | F1 et un projet actif plus ancien dont le produit n'a plus de producteur | ce projet `failed: No blueprint or reaction found for product type ID <id>`, rien d'écrit pour lui ; F1 corrigé ; code `FAILURE` |
| F11 | F1 et un projet plus ancien dont l'aperçu lève (`\UnexpectedValueException` du catalogue d'invention) | ce projet `failed` avec le message ; ses étapes en base inchangées, y compris celles à runs fixés modifiées en mémoire par le plan ; F1 corrigé |
| F12 | Projet sélectionné sans étape racine (racine supprimée) | non sélectionné |
| F13 | F1 corrigé par la commande, comparé au même projet corrigé par `regenerateSteps()` seule | étapes identiques (runs, quantités, ME, TE, structures, ordre) |
| F14 | Non-régression : tous les tests de la factory (`IndustryProjectFactory*Test`, `IndustryProjectFactoryRegenerationTest`, `UpdateStepFixedRunsTest`) | inchangés (plan et appariement partagés, G3) |

## Cas limites

- **Projet créé entre le déploiement et la commande** : #278 l'exclut par `--created-before` ; #284 ne le sélectionne pas (sa racine vaut déjà la quantité produite).
- **Prix ou réglages changés depuis la création** (#278) : le recalcul prend ceux du jour, la source peut passer de `jita` à `structure` (P3, décision 2).
- **Racine découpée** (#284) : G1 somme les jobs ; l'aperçu peut produire plus de jobs (vrais runs découpés par la durée maximale ou le plafond de runs d'un BPC, T4 de la spec #2-129) ; les jobs matchés restent sur le job de même `splitIndex`, un job en trop est absorbé avec ses jobs matchés et achats.
- **Étape ajoutée à la main du même produit** (profondeur 0, même blueprint et activité que la racine) : comptée dans la racine (G1) ; la régénération l'apparie comme un job de plus (comportement de la régénération, inchangé).
- **Autres écarts d'un vieux projet** (#284) : la régénération applique aussi les règles arrivées depuis sa création (#234, #235, #129) ; la ligne montre le nombre d'étapes avant et après. Seul le ME/TE est protégé (G4).
- **Étape de copie d'un blueprint inventé** déjà en base : gardée hors plan, pas de changement de ME/TE (pas reprise par le plan) : ne déclenche pas G4.
- **Produit sans producteur, type absent du SDE, catalogue d'invention incohérent** : `failed` avec la raison (C3), rien n'est écrit pour le projet.
- **Plus grand produit** : 100 000 runs × 10 000 = 10⁹ unités, sous la limite de la colonne `quantity` (INT), comme dans la spec #2-129.
- **Utilisateur connecté pendant #284** : `syncStarted` / `syncCompleted` rechargent son onglet Étapes ; un échec dans `regenerateSteps()` lui publierait `syncError` (affiché dans l'onglet). Les échecs attendus (producteur, catalogue) tombent dans l'aperçu, avant toute publication.

## Décisions prises en autonomie

1. **Un service par commande, la règle du prix extraite** (C1, P1) : la commande ne porte aucune règle ; `CreateProjectProcessor` et #278 partagent le seul calcul du prix de vente estimé, sans copie.
2. **#278 recalcule avec le prix du jour** (P3), parce que l'issue demande « la même règle que la création ». Écarté : multiplier la valeur enregistrée par les unités par run, qui garde le prix de la création mais n'est pas idempotent sans marqueur (une relance multiplierait deux fois) et demanderait une colonne, donc une migration.
3. **`--created-before` obligatoire pour #278** (P2, P8) : aucune donnée ne distingue un prix d'avant #125 d'un prix correct ; l'heure du déploiement fixe l'ensemble, une relance ne touche jamais un projet créé après. #284 n'en a pas besoin : la quantité racine dit à elle seule si le projet a le défaut (G2).
4. **Prix absent : `failed`, valeur gardée** (P4) plutôt qu'un prix inconnu : l'écart reste visible dans la sortie, pas dans l'écran de l'utilisateur sous forme de « - ».
5. **Prix de vente estimé `null` non sélectionné** (P2.3) : ce n'est pas le défaut de #125.
6. **Sélection de #284 par la signature de #2** (G2), pas par « la régénération changerait quelque chose » : un projet qui ne diffère que par un ancien ME/TE, une structure ou un découpage n'est pas touché.
7. **Garde ME/TE : projet ignoré, pas de ME/TE forcé** (G4). Garder le ME/TE enregistré tout en planifiant avec celui du projet donnerait des quantités incohérentes avec le ME affiché.
8. **Aperçu dans la factory** (G3) : le `--dry-run` doit dire ce qui changerait sans écrire ni publier sur Mercure ; une régénération dans une transaction annulée publierait `syncCompleted` / `syncError` chez l'utilisateur. Plan et appariement restent à un seul endroit.
9. **`clear()` après chaque projet** (C2) : le plan modifie en mémoire les jobs à runs fixés, et un projet en échec ne doit pas être écrit par le `flush` du suivant.
10. **Racine inchangée = projet inchangé** (G5), même si d'autres étapes changeraient : la commande corrige #2, pas le reste.
11. **Statut `active` seulement** dans le lot (P2.2, G2) : les deux commandes ne changent que les projets en cours, ce que les issues visent ; le reste est T2.
12. **Pas de commande pour les deux corrections à la fois** : elles sont indépendantes (#278 ne lit pas les étapes, #284 n'écrit pas le prix) et s'arbitrent séparément.

## À trancher par Sylvain (hors lot)

- **T1 — Estimations figées des projets régénérés.** `estimatedMaterialCost` et `estimatedJobCost` restent ceux de l'ancien plan (sous-estimés du facteur des unités par run pour la racine), alors que #278 multiplie le prix de vente estimé des réactions : le profit estimé d'un projet sans coûts réels saisis devient très surestimé. Le choix T2 de la spec #2-129 a retenu la régénération seule. Recommandation : dans #284, après une régénération réussie, recalculer ces deux estimations par `ProductionCostService::estimateMaterialCost()` et `estimateJobInstallCosts()` (règle de la création, échec journalisé comme à la création), et les montrer dans la ligne. Change des montants affichés de projets en cours.
- **T2 — Projets terminés et archivés.** Recommandation : #278 oui (simple estimation, utilisée pour le profit d'un projet sans prix de vente réel) ; #284 non (jobs livrés, l'historique n'a plus d'usage de la liste d'achats). Change des montants de projets clos.
- **T3 — Projets ignorés par la garde ME/TE** (G4). Recommandation : les laisser à leur propriétaire, prévenu avec la liste du `--dry-run` ; une régénération depuis l'interface (changement de runs) remet aujourd'hui la racine au ME/TE du projet et la réaction à 0 / 0. Alternative : option qui force la régénération de ces projets.

## Action en production (journal de déploiement)

À lancer une fois, juste après le déploiement de la release qui contient #2, #125, #278 et #284. Aucune migration. Les deux commandes sont indépendantes.

```bash
# 1. Noter l'heure du déploiement (ISO 8601 avec fuseau), ex. 2026-11-03T21:30:00+01:00

# 2. Prix de vente estimé des projets de réaction (#278)
docker compose exec app php bin/console app:industry:recalculate-reaction-sell-prices --created-before="<heure du déploiement>" --dry-run
docker compose exec app php bin/console app:industry:recalculate-reaction-sell-prices --created-before="<heure du déploiement>"

# 3. Étapes des projets à plusieurs unités par run (#284) : change les étapes de projets en cours,
#    prévenir les propriétaires des projets listés par le dry-run avant le vrai passage
docker compose exec app php bin/console app:industry:regenerate-multi-unit-steps --dry-run
docker compose exec app php bin/console app:industry:regenerate-multi-unit-steps
```

Code de sortie non nul : lire les lignes `failed`, corriger la cause, relancer la même commande. #284 ne reprend que les projets encore faux ; #278 reprend les mêmes projets et remet les déjà corrigés au prix du jour (même échelle).

Mesure de l'étendue, en lecture seule, avant le déploiement (tables et colonnes vérifiées : `#[ORM\Table]` des entités, stratégie `underscore_number_aware`) :

```sql
-- producteur d'un produit : fabrication d'abord, puis réaction, plus grande quantité
WITH producer AS (
  SELECT DISTINCT ON (product_type_id) product_type_id, type_id, activity_id, quantity
  FROM sde_industry_activity_products
  WHERE activity_id IN (1, 11)
  ORDER BY product_type_id, activity_id, quantity DESC
)
-- #278
SELECT p.id, p.status, p.created_at, p.product_type_id, p.runs, pr.quantity AS units_per_run,
       p.estimated_sell_price, p.estimated_sell_price_source, p.estimated_tax_amount
FROM industry_projects p
JOIN producer pr ON pr.product_type_id = p.product_type_id
WHERE pr.activity_id = 11 AND pr.quantity > 1 AND p.estimated_sell_price IS NOT NULL
ORDER BY p.status, p.created_at;

-- #284 (même CTE)
SELECT p.id, p.status, p.product_type_id, p.runs, pr.activity_id, pr.quantity AS units_per_run,
       COUNT(s.id) AS root_jobs, SUM(s.quantity) AS root_quantity, p.runs * pr.quantity AS output_quantity
FROM industry_projects p
JOIN producer pr ON pr.product_type_id = p.product_type_id
JOIN industry_project_steps s ON s.project_id = p.id AND s.depth = 0
  AND s.blueprint_type_id = pr.type_id
  AND s.activity_type = CASE pr.activity_id WHEN 1 THEN 'manufacturing' ELSE 'reaction' END
WHERE pr.quantity > 1
GROUP BY p.id, p.status, p.product_type_id, p.runs, pr.activity_id, pr.quantity
HAVING SUM(s.quantity) < p.runs * pr.quantity
ORDER BY p.status, p.created_at;
```

Elle remplace la requête Q3 de la note de câblage du lot #2 (qui listait tous les projets à plusieurs unités par run, défaut ou non).

## Hors périmètre

- T1 à T3.
- Une option pour cibler un projet (`app:industry:regenerate-steps <project-id>` existe déjà pour #284).
- Les estimations figées à la création autres que le prix de vente (T1).
- Les autres copies de la recherche du producteur (`PlansStockpileImports::producerOf`, `GroupIndustryProjectService::producerOf`).
- La suppression de `RegenerateProjectStepsCommand` ou sa fusion avec #284.

## Tests existants touchés

- **Harnais à adapter, assertions inchangées** : `CreateProjectProcessorTest::processor()` construit le service extrait (P1) au lieu de passer `JitaMarketService`, `StructureMarketService`, `IndustryActivityProductRepository` et `IndustryUserSettingsRepository` au processor.
- **Restent verts sans changement** (F14, G3) : `IndustryProjectFactoryGoldenStepsCharacterizationTest`, `IndustryProjectFactoryTest`, `IndustryProjectFactoryCopyStepsTest`, `IndustryProjectFactoryBpcMaxRunsTest`, `IndustryProjectFactoryRegenerationOnGoldenSdeTest`, `tests/Integration/Service/Industry/IndustryProjectFactoryRegenerationTest`, `tests/Integration/State/Processor/Industry/UpdateStepFixedRunsTest`, `RegenerateProjectStepsCommandTest`.
- **Nouveaux** : tests des deux services (E1 à E12 ; F1 à F13, en golden pour les plans et en intégration pour l'écriture, le `clear()`, les jobs matchés et achats), de l'aperçu de la factory, et des deux commandes avec `CommandTester` et le service doublé (format des lignes C5, P7, G7 ; tableau ; options ; code de sortie C6). Groupes `issue-278` et `issue-284`.

## Termes du glossaire

- À ajouter : **Étape racine** (Root step) : étape de profondeur 0 dont le blueprint et l'activité sont ceux du **producteur** du produit du projet ; plusieurs jobs si elle est découpée ; sa quantité vaut la **quantité produite** du projet (spec #2-129).
- À mettre à jour à l'implémentation : **Prix de vente estimé** (règle portée par le service extrait ; recalculé une fois pour les projets de réaction existants, #278) et **Régénération des étapes** (commande `app:industry:regenerate-multi-unit-steps`, garde ME/TE, #284).
- `docs/modules/industry.md` : ajouter les deux services, l'aperçu de la factory et les deux commandes au tableau des classes.
