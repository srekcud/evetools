# Spec #131 — Groupe découpé : les jobs en trop sont supprimés quand le besoin baisse

**Statut : rédigée le 2026-10-31 (lot A du groupe project-steps, circuit m), décision D1 prise par Sylvain.** Les choix de mise en œuvre faits en autonomie sont listés dans « Décisions prises en autonomie » ; les points qui modifient des données de production sont dans « Impact prod ».

## Contexte

Issue #131 (audit du moteur industrie du 2026-10-22) : quand le besoin d'un **groupe découpé** baisse, la nouvelle répartition force au moins 1 run par job. Avec plus de jobs que de runs nécessaires, le groupe garde trop de runs et un job reçoit une quantité 0. Cas de l'issue : 3 jobs × 10 runs, besoin ramené à 2 runs → runs 1/1/1, quantités 1/1/0, `totalGroupRuns` 2.

**Décision D1 (Sylvain)** : les jobs en trop sont **supprimés** (aucun job à 0 run, aucune quantité 0) ; un groupe réduit à un seul job est **dissous** (l'étape redevient intacte, sans `splitGroupId`) ; la somme des runs des jobs égale `totalGroupRuns`.

État vérifié sur `main` 9bd98a96 :

| Site | Aujourd'hui | Appelants |
|---|---|---|
| `SplitGroupQuantities::applyNeedToSteps()` (l. 54-97) | un seul job : règle d'une étape seule ; sinon jobs fixés gardés, jobs non fixés au prorata de `runs du besoin − runs fixés` ; `totalGroupRuns` = runs du besoin sans job fixé (l. 81), somme des runs avec un job fixé | `IndustryStepCalculator::recalculateStepQuantities()`, `SplitGroupMaterialNeeds::applyChangeOfJobs()`, `IndustryProjectFactory::jobsKeepingFixedRuns()` |
| `SplitGroupQuantities::runsProrataOfCurrentRuns()` (l. 103-126) | arrondi par job, le dernier job prend `total − assigned` (l. 116), puis `max(1, …)` sur chaque job (l. 120) : avec moins de runs que de jobs, ou un arrondi défavorable, la somme dépasse le total | `applyNeedToSteps()` |
| `SplitGroupQuantities::share()` (l. 31-43) | chaque job, dans l'ordre de `splitIndex`, reçoit `min(runs × unités par run, besoin restant)` : un job en trop reçoit 0 | `applyNeedToSteps()`, `SplitStepProcessor`, factory |
| `SplitGroupQuantities::runsBySplitIndex()` (l. 14-25) | répartition égale ; rend des 0 quand les runs sont moins nombreux que les jobs | `UpdateStepProcessor::releaseFixedRunsOfTheGroup()` (l. 182-184, seul appelant qui peut passer moins de runs que de jobs), `SplitStepProcessor` (refuse « runs < jobs », l. 73-74), factory (`jobsOf`, nombre de jobs ≤ runs) |
| `UpdateStepProcessor::fixRunsEnteredOnTheJob()` (l. 149-168) | plafond G3 de #185 : réserve 1 run par autre job non fixé (l. 162-166), au moins 1 run pour le job saisi | PATCH d'une étape |
| `UpdateStepProcessor::update()` (l. 132-146) | réponse : l'étape éditée puis les étapes modifiées par la cascade (liste de `ProjectStepResource`), ou l'étape seule sans cascade | `stores/industry/steps.ts::updateStep()` |
| `IndustryStepCalculator::walkNeedsFromConsumersToMaterials()` (l. 57-87) | le besoin d'un produit est écrit (callback), puis les besoins de ses matériaux sont calculés sur `$stepsByProduct[$productTypeId]`, liste lue avant la callback | `recalculateStepQuantities()`, `needRunsOf()` |
| `IndustryProjectFactory::applyPlanToExistingSteps()` (l. 312-342) | appariement plan / étapes existantes par blueprint et activité, dans l'ordre de `splitIndex` ; une étape existante en trop est absorbée (`IndustryProjectStep::absorb()` : jobs matchés, achats, stock, marque « acheté ») par la dernière étape gardée de même clé, puis supprimée | `regenerateSteps()` |
| `IndustryProjectStep::isInStock()` (l. 248-251) | `inStockQuantity >= quantity` : un job à quantité 0 est « en stock » | voir #182 (lot D, hors périmètre) |
| `stores/industry/steps.ts::updateStep()` (l. 65-88) | réponse liste : remplace chaque étape renvoyée par son identifiant ; une étape supprimée côté serveur reste dans le store | `StepsTab.vue` (runs, surproduction, calcul automatique, ME, TE) |

Le test `SplitGroupQuantitiesTest::testAJobThatIsNotFixedKeepsOneRunWhenTheFixedJobCoversTheNeed` (l. 74-89, #185) fige aujourd'hui 1 run et une quantité 0 pour le job non fixé.

## Règles métier

Notations : **runs du besoin** = `SplitGroupQuantities::runsOfNeed(besoin, unités par run)` ; **runs fixés** = somme des runs des jobs fixés du groupe ; **reste** = runs du besoin − runs fixés.

- **R1 — Aucun job sans run.** Après tout recalcul d'un groupe découpé, chaque job du groupe a au moins 1 run et une quantité strictement positive. La somme des runs des jobs vaut `totalGroupRuns`.
- **R2 — Nombre de jobs gardés.** Les jobs fixés sont tous gardés (G6 de #185, surproduction comprise). Parmi les jobs non fixés, on en garde `min(nombre de jobs non fixés, max(0, reste))` ; les autres sont des **jobs en trop**, supprimés.
- **R3 — Quels jobs sont gardés.** Parmi les jobs non fixés, on garde en priorité les **jobs engagés** (job qui porte au moins un job ESI matché, un achat, une quantité en stock > 0 ou la marque « acheté »), puis les autres ; dans chaque catégorie, le plus petit `splitIndex` d'abord. Les jobs en trop sont donc d'abord les jobs non engagés de plus grand `splitIndex`.
- **R4 — Rien ne se perd.** Un job en trop est absorbé avant sa suppression par le job gardé de plus grand `splitIndex` du groupe (règle existante `IndustryProjectStep::absorb()` : jobs matchés, achats, stock additionné, « acheté » si l'un des deux l'est). Seul un job engagé en trop (plus de jobs engagés que de jobs à garder) transporte quelque chose.
- **R5 — Réindexage.** Les jobs gardés sont renumérotés de 0 à N − 1 dans l'ordre de leur `splitIndex` d'avant le recalcul. Un job dont seul le `splitIndex` change compte comme modifié.
- **R6 — Dissolution.** Un groupe dont il ne reste qu'un job à l'issue d'un recalcul qui a supprimé des jobs est dissous : `splitGroupId` null, `splitIndex` 0, `totalGroupRuns` null. Ce job suit ensuite la règle d'une étape seule : non fixé, runs = runs du besoin et quantité = besoin ; fixé, il garde ses runs, `runsFixed` reste vrai, quantité = runs × unités par run (G1 de #185). Ses identifiant, jobs matchés, achats, stock, ME, TE, structure et mode de matching sont gardés.
- **R7 — Un groupe n'est jamais vidé.** Si R2 ne garde aucun job (aucun job fixé et besoin 0), le premier job selon R3 est gardé et dissous (R6), avec la règle d'une étape seule (runs 0, quantité 0, comportement actuel d'une étape non découpée).
- **R8 — Répartition exacte des runs.** Les jobs non fixés gardés se partagent le reste au prorata de leurs runs actuels, comme aujourd'hui (arrondi par job, le dernier job de la répartition prend ce qui reste). Si ce dernier job tombe sous 1 run, les runs manquants sont pris un à un au job qui en a le plus (le plus petit `splitIndex` à égalité). La somme vaut exactement le reste ; une répartition qui donne déjà au moins 1 run à chaque job est inchangée.
- **R9 — `totalGroupRuns`.** Règle inchangée (#252) : runs du besoin sans job fixé, somme des runs des jobs avec au moins un job fixé. Avec R2 et R8, les deux valeurs égalent la somme des runs des jobs. Tous les jobs gardés d'un groupe qui a perdu un job comptent comme modifiés.
- **R10 — Quantités.** Règle inchangée (#112) : chaque job, dans l'ordre de `splitIndex` après R5, reçoit `min(runs × unités par run, besoin restant)` ; un job fixé reçoit runs × unités par run. Avec R2, le dernier job a une quantité > 0.
- **R11 — Étapes enfants.** Dans le même recalcul, les besoins des matériaux sont calculés sur les jobs gardés, job par job (#73) : un job supprimé ne demande plus rien. Les étapes et groupes enfants suivent la cascade, R1 à R10 compris.
- **R12 — Partout où un besoin est écrit sur un groupe.** R1 à R11 valent pour la cascade (`recalculateStepQuantities()`, appelée par le PATCH d'une étape et par `IndustryJobMatcher`), pour les groupes enfants d'un découpage ou d'une fusion manuels (`SplitGroupMaterialNeeds::applyChangeOfJobs()`), et pour la régénération d'un groupe qui a un job fixé (`jobsKeepingFixedRuns()`, G7 de #185). Dans la régénération, chaque job gardé reste la même étape (identifiant, `runsFixed`, jobs matchés) ; un job en trop est absorbé puis supprimé (R4).
- **R13 — Retour au calcul automatique (G5 de #185).** `runsFixed: false` libère tous les jobs du groupe ; les runs du besoin sont répartis également entre `min(nombre de jobs, runs du besoin)` jobs choisis selon R3 ; les autres sont supprimés (R4, R5), un groupe d'un job est dissous (R6). Le job sur lequel la saisie a été faite peut être supprimé.
- **R14 — Saisie de runs sur un job (G3 de #185) : inchangée.** Le plafond réserve toujours 1 run par autre job non fixé : une saisie ne supprime jamais de job ; seule une baisse du besoin ou le retour au calcul automatique le fait.
- **R15 — Réponse du PATCH d'une étape.** Format inchangé : un `ProjectStepResource` sans cascade, une liste de `ProjectStepResource` avec cascade. La liste contient l'étape éditée en premier si elle existe encore, puis les étapes gardées modifiées (R5, R6, R9 compris). Une étape supprimée n'est jamais dans la réponse. Une liste vide est possible quand l'étape éditée est un job à 0 run hérité supprimé sans qu'aucun job gardé ne change : le store recharge alors le projet (R16).
- **R16 — Store frontend.** Dans `stores/industry/steps.ts::updateStep()`, quand la réponse est une liste et que l'étape éditée n'y figure pas, ou qu'une étape renvoyée appartient à un groupe découpé dans la réponse ou dans le store avant la réponse, le store recharge le projet (`projectsStore.fetchProject()`, déjà utilisé après un découpage, une fusion ou un retrait de job) au lieu de remplacer les étapes une à une. Les autres cas sont inchangés. `StepsTab.vue::updateStepRuns()` lit l'étape dans le store rechargé : une étape supprimée n'ouvre pas l'offre de surproduction.

## Exemples chiffrés

Ces exemples serviront de tests rouges. Colonnes : `[runs, quantity, depth, meLevel, teLevel, splitIndex, totalGroupRuns]` pour le SDE golden (`IndustryProjectFactoryGoldenStepsCharacterizationTest`), `[runs, quantity, runsFixed, splitIndex, totalGroupRuns]` pour `SplitGroupQuantitiesTest`, `[id, runs, quantity, runsFixed, splitIndex, totalGroupRuns]` pour `UpdateStepFixedRunsTest`. « Dissous » = `splitGroupId` null.

### Cascade sur le SDE golden

Sans structure, réactions ME 0. Fernite Carbide : 10 000 unités par run, 100 Fernite Alloy et 5 Hydrogen Fuel Block par run. Fernite Alloy : 200 unités par run, 5 Hydrogen Fuel Block par run. Hydrogen Fuel Block : 40 unités par run.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Fernite Carbide 1 run (besoin d'Alloy 100, 1 run). Alloy 3 + 2 runs, sans `totalGroupRuns` (test #131 actuel) | `[1, 100, 1, 0, 20, 0, 1]`, `[1, 0, 1, 0, 20, 1, 1]` ; modifiés : Alloy 0 et 1 | `[1, 100, 1, 0, 20, 0, null]` dissous ; Alloy 1 supprimé ; modifiés : Alloy 0 |
| E2 | Cas de l'issue. Fernite Carbide 4 runs (besoin 400, 2 runs). Alloy 10 + 10 + 10 (2 000 chacun, total 30). Hydrogen Fuel Block 1 run, 40 | Alloy `[1, 200, …, 0, 2]`, `[1, 200, …, 1, 2]`, `[1, 0, …, 2, 2]` (3 runs pour un total de 2) ; Hydrogen Fuel Block `[1, 35, …]` (20 + 5 + 5 + 5) | Alloy `[1, 200, 1, 0, 20, 0, 2]`, `[1, 200, 1, 0, 20, 1, 2]` ; Alloy 2 supprimé ; Hydrogen Fuel Block `[1, 30, …]` (20 + 5 + 5) |
| E3 | Fernite Carbide 2 runs (besoin 200, 1 run). Alloy 10 + 10 + 10. Hydrogen Fuel Block 1 run, 40 | Alloy 1/1/1, quantités 200/0/0, total 1 ; Hydrogen Fuel Block 25 | Alloy `[1, 200, 1, 0, 20, 0, null]` dissous ; Alloy 1 et 2 supprimés ; Hydrogen Fuel Block `[1, 15, …]` (10 + 5) |
| E4 | R8. Fernite Carbide 5 runs (besoin 500, 3 runs). Alloy 8 + 1 + 1 (1 600, 200, 200, total 10). Hydrogen Fuel Block 1 run, 40 | Alloy 2/1/1, quantités 400/100/0, total 3 (somme 4) ; Hydrogen Fuel Block `[2, 45, …]` | Alloy `[1, 200, …, 0, 3]`, `[1, 200, …, 1, 3]`, `[1, 100, …, 2, 3]` ; Hydrogen Fuel Block `[1, 40, …]` |
| E5 | Garde-fou : Fernite Carbide 8 runs, Alloy 1 + 1, total 2 (test l. 958-972) | `[2, 400, …, 0, 4]`, `[2, 400, …, 1, 4]` | inchangé |

### `SplitGroupQuantities` (Oxygen Fuel Block, 40 unités par run)

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| U1 | Job 0 fixé à 15, job 1 non fixé à 5, besoin 480 (12 runs) | `[15, 600, true, 0, 16]`, `[1, 0, false, 1, 16]` | `[15, 600, true, 0, null]` dissous ; job 1 supprimé |
| U2 | Job 0 fixé à 10, jobs 1 et 2 non fixés à 5, besoin 440 (11 runs, reste 1) | `[10, 400, true, 0, 12]`, `[1, 40, false, 1, 12]`, `[1, 0, false, 2, 12]` | `[10, 400, true, 0, 11]`, `[1, 40, false, 1, 11]` ; job 2 supprimé |
| U3 | R5. Job 0 fixé à 6, job 1 non fixé à 4, job 2 fixé à 4, besoin 400 (10 runs, reste 0) | `[6, 240, true, 0, 11]`, `[1, 0, false, 1, 11]`, `[4, 160, true, 2, 11]` | `[6, 240, true, 0, 10]`, `[4, 160, true, 1, 10]` (ancien job 2) ; job 1 supprimé |
| U4 | R3. Jobs 10 + 10 + 10 non fixés, le job 2 porte un job ESI matché, besoin 80 (2 runs) | `[1, 40, …, 0, 2]`, `[1, 40, …, 1, 2]`, `[1, 0, …, 2, 2]` | `[1, 40, false, 0, 2]` (job 0), `[1, 40, false, 1, 2]` (ancien job 2, avec son job matché) ; job 1 supprimé |
| U5 | R3 et R4. Jobs 10 + 10 + 10 non fixés, jobs 1 et 2 portent chacun un job ESI matché, job 2 a 15 en stock, besoin 40 (1 run) | 1/1/1, quantités 40/0/0 | ancien job 1 `[1, 40, false, 0, null]` dissous, avec 2 jobs matchés et 15 en stock ; jobs 0 et 2 supprimés |
| U6 | R8. Jobs 8 + 1 + 1 non fixés, besoin 120 (3 runs) | `[2, 80, …, 0, 3]`, `[1, 40, …, 1, 3]`, `[1, 0, …, 2, 3]` (somme 4) | `[1, 40, false, 0, 3]`, `[1, 40, false, 1, 3]`, `[1, 40, false, 2, 3]` |
| U7 | R7. Jobs 1 + 1 non fixés, besoin 0 | `[1, 0, …, 0, 0]`, `[1, 0, …, 1, 0]` | `[0, 0, false, 0, null]` dissous ; job 1 supprimé |
| U8 | Garde-fou : jobs 25 + 10 fixés (surproduction), besoin 800 (20 runs) | `[25, 1000, true, 0, 35]`, `[10, 400, true, 1, 35]` | inchangé (jobs fixés jamais supprimés) |

### PATCH d'une étape et régénération (fixtures de `UpdateStepFixedRunsTest`)

Porteur (`CARRIER`, 120 fuel blocks par run, ME 10 : 108 par run) ; fuel block 40 par run. Croiseur (`CRUISER`, 4 platings par run, 1 plating par run de plating).

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| P1 | Porteur 8 runs, fuel block découpé en 4 jobs (6 + 6 + 5 + 5, besoin 864). PATCH du porteur à 1 run (besoin 108, 3 runs) | 1/1/1/1, quantités 40/40/28/0, total 3 | `[j0, 1, 40, false, 0, 3]`, `[j1, 1, 40, false, 1, 3]`, `[j2, 1, 28, false, 2, 3]` ; j3 supprimé ; réponse : porteur, j0, j1, j2 (j3 absent) |
| P2 | P1 sur un groupe surproduit : saisie 10 avec `overproduce` sur j0 (10/6/5/5, tous fixés), porteur à 1 run, puis `runsFixed: false` sur j3 | jobs 1/1/1/1, quantités 40/40/28/0 | `[j0, 1, 40, false, 0, 3]`, `[j1, 1, 40, false, 1, 3]`, `[j2, 1, 28, false, 2, 3]` ; j3 (l'étape éditée) supprimé ; réponse : j0, j1, j2 ; le store recharge le projet (R16) |
| P3 | Plating 10 + 10 (besoin 20), saisie 15 sur j0 ; croiseur 8 runs ME 7 (besoin 30) puis 3 runs (besoin 12). E8 de #185 | 15/15, puis `[j0, 15, 15, true, 0, 16]`, `[j1, 1, …, false, 1, 16]` | 15/15, puis `[j0, 15, 15, true, 0, null]` dissous ; j1 supprimé |
| P4 | Plating 10 + 10 (besoin 20, ME 0), saisie 15 sur j0, puis runs du projet à 3 et régénération (besoin 12) | `[j0, 15, 15, true, 0, 15 + 1]`, `[j1, 1, 0, false, 1, …]` | `[j0, 15, 15, true, 0, null]` dissous, même identifiant ; j1 supprimé, ses jobs matchés et achats passés à j0 |
| P5 | Garde-fou R14 : plating 10 + 10, saisie 25 sur j0 sans surproduction (E6 de #185) | 19 (fixé) / 1 | inchangé |
| P6 | Store (R16) : réponse liste sans l'étape éditée, ou avec une étape d'un groupe découpé | étapes remplacées une à une, l'étape supprimée reste affichée | un appel `GET /industry/projects/{id}`, le store reflète la réponse du serveur |

## Cas limites

- **Besoin 0** : R7, un job gardé et dissous, runs 0 et quantité 0 comme une étape non découpée (U7). Son statut « en stock » relève de #182.
- **Un seul job fixé qui couvre le besoin** : les jobs non fixés sont supprimés, le job fixé est dissous et reste fixé, au-dessus du besoin si c'est le cas (U1, P3). L'offre de surproduction n'est pas rouverte.
- **Jobs fixés dont la somme dépasse le besoin** (surproduction confirmée, ou besoin tombé ensuite) : aucun job fixé n'est supprimé ; seuls les jobs non fixés le sont (U3, U8). Deux jobs fixés ou plus gardent le groupe.
- **Job avec job ESI lié** : engagé, gardé en priorité (U4). S'il faut quand même le supprimer (U5), son job matché passe au job gardé : l'avancement de ce job peut dépasser 100 % (#3, l'avancement ne modifie jamais le plan). Le mode `manual` du matching suit le job matché (#187).
- **Achat, stock, marque « acheté »** : même règle que le job ESI (R3, R4).
- **Groupe d'un seul job déjà en base** (antérieur à #111) : non dissous par le recalcul tant qu'aucun job n'est supprimé ; règle R1 bis de #252 inchangée.
- **Job ajouté à la main à un groupe** (`CreateStepProcessor`, `splitGroupId`) : traité comme les autres au prochain recalcul, donc supprimé s'il est en trop et non engagé.
- **Étape de copie** : hors de la cascade (`productionStepsByProduct()` l'écarte) ; ses runs suivent la fabrication à la régénération (#235). Inchangé.
- **Étape racine** : aucun besoin écrit par la cascade ; inchangé.
- **Recalcul sans changement** : idempotent, liste vide (R4 de #252).

## Hors périmètre

- Définition de « en stock » d'un job à quantité 0 (`IndustryProjectStep::isInStock()`) : #182, lot D.
- Ajout et suppression manuels d'une étape ou d'un job (`CreateStepProcessor`, `DeleteStepProcessor`) : #191, #202.
- Fusion (`MergeStepsProcessor`) qui ne reporte ni le stock ni la marque « acheté » des jobs fusionnés : écart constaté, non traité ici.
- Saisie de runs sur un job (G3 de #185) : inchangée (R14).
- Groupes d'un seul job déjà en base : corrigés par la régénération (#111), pas de migration.
- Signal visuel d'une suppression de job dans l'onglet Étapes : aucune maquette, aucun élément visuel nouveau ; le groupe affiche simplement moins de jobs.

## Tests existants appelés à casser

- `SplitGroupQuantitiesTest::testAJobThatIsNotFixedKeepsOneRunWhenTheFixedJobCoversTheNeed` (#185) : devient U1.
- `IndustryProjectFactoryGoldenStepsCharacterizationTest::testSplitChildGroupCurrentlyKeepsTwoRunsForANeedOfOneIssue131` (groupe `issue-131`) : devient E1, renommé sans `Currently…Issue131`.
- `IndustryProjectFactoryGoldenStepsCharacterizationTest::testSplitGroupKeepingMoreRunsThanItsNeedTakesTheRunsOfTheNeedAsTotalGroupRunsOnEveryJob` (groupes `issue-252`, `issue-131`) : Alloy 1 (quantité 0) supprimé, Alloy 0 `[1, 100, 1, 0, 20, 0, null]` dissous, modifiés : Alloy 0.
- `UpdateStepFixedRunsTest::testAFixedJobKeepsItsRunsWhenTheNeedRisesThenFalls` : seconde assertion → P3.
- `UpdateStepFixedRunsTest::testRunsEnteredOnAJobWhoseCapFallsBelowOneRunAreFixedAtOneRun` : après le porteur à 1 run, le groupe n'a plus que 3 jobs (P1), la déstructuration en 4 identifiants tombe. Avec 3 jobs non fixés pour 3 runs, le plafond vaut 1 et n'atteint plus le plancher : le test est à réécrire sur un groupe où les autres jobs sont déjà fixés au-delà du besoin (par exemple saisie 5 avec `overproduce` sur j2, puis saisie 5 sur j0 → j0 fixé à 1 run).
- Si la signature de `applyNeedToSteps()` change pour signaler les jobs supprimés, les tests de `SplitGroupQuantitiesTest` qui lisent sa valeur de retour (`testSplitGroupWithoutRuns…`, `testJobWhoseRunsAndQuantityStay…`, `testAFixedStepThatIsNotSplit…`) suivent la nouvelle forme, sans changer de valeurs.

Restent verts (garde-fous) : `IndustryStepCalculatorTest::testRedistributeAfterSplitProportionally` (54 / 36), `testSplitGroupWithZeroOldTotalRunsUsesOneAsDefault` (besoin 45 runs pour 2 jobs), les tests #185 de `SplitGroupQuantitiesTest` hors U1, les tests #252 qui ne perdent pas de job, `UpdateStepFixedRunsTest` hors les deux cités (besoins de 20 à 30 runs pour 2 à 4 jobs), les tests de « recalcul d'un Nomad juste créé ne change rien », `frontend/tests/stores/stepsFixedRuns.spec.ts` (réponse d'une étape seule, pas de projet courant).

## Notes de mise en œuvre (non normatives)

- `SplitGroupQuantities` est statique et sans `EntityManager` : les jobs en trop doivent remonter aux appelants (`IndustryStepCalculator`, `SplitGroupMaterialNeeds`, factory), qui absorbent, retirent de `IndustryProject::getSteps()` (`orphanRemoval`) et appellent `remove()`.
- `walkNeedsFromConsumersToMaterials()` calcule les besoins des matériaux sur `$stepsByProduct[$productTypeId]` lu avant la callback : sans mise à jour de cette liste, un job supprimé compterait encore (R11). `needRunsOf()` passe par la même marche et ne doit rien supprimer.
- Après `flush()`, Doctrine remet à null l'identifiant d'une entité supprimée à identifiant généré : `UpdateStepProcessor` ne doit pas mapper l'étape éditée si elle a été supprimée (R15).
- Régénération : l'appariement par `splitIndex` de `matchPlanToExistingSteps()` associerait les jobs gardés non contigus (U3, U4) aux mauvaises entités, et `applyPlannedStep()` ne réécrit pas `runsFixed` : les jobs en trop d'un groupe fixé sont à retirer avant l'appariement.
- `releaseFixedRunsOfTheGroup()` appelle `runsBySplitIndex()` avec moins de runs que de jobs : R13 se fait en ne répartissant que sur les jobs gardés.

## Décisions prises en autonomie

- **R3 — Priorité des jobs engagés** sur le `splitIndex` : garder le job qui porte déjà un job ESI, un achat ou du stock évite de déplacer ces données ; à défaut, le plus petit `splitIndex`, comme l'appariement de la régénération.
- **R4 — Absorption plutôt qu'interdiction** : un job engagé n'est jamais gardé à 0 run (D1) ; ses données passent au dernier job gardé, comme la régénération (#21) le fait déjà avec `absorb()`.
- **R5 — Réindexage contigu** à partir de 0, conforme au glossaire (« position dans le groupe, à partir de 0 »).
- **R6 — Dissolution seulement après suppression** : un groupe d'un seul job déjà en base n'est pas dissous par un recalcul sans suppression, pour ne pas revenir sur R1 bis de #252 (décidé par Sylvain le 2026-10-28).
- **R7 — Un groupe n'est jamais vidé** : supprimer toute l'étape relève de #191/#202 ; le job restant prend la règle actuelle d'une étape seule, même avec 0 run.
- **R8 — Correction minimale de la répartition** : seul le dernier job sous 1 run est corrigé, pour garder à l'identique toutes les répartitions valides d'aujourd'hui (tests #185 et #252).
- **R14 — Saisie inchangée** : la réservation de 1 run par autre job non fixé reste ; la saisie d'un utilisateur ne fait jamais disparaître un job qu'il a créé ou découpé. Alternative écartée : plafonner au besoin et supprimer les jobs non fixés devenus vides.
- **R16 — Rechargement du projet** plutôt qu'un nouveau format de réponse : le PATCH garde son format, le store recharge comme après un découpage ou une fusion. Coût : un GET de plus quand un groupe découpé est touché.

## Impact prod

- **Suppression de lignes `industry_project_steps`** au prochain recalcul de chaque projet dont un groupe a plus de jobs que de runs nécessaires (jobs à quantité 0 laissés par #131, ou à 0 run laissés par le calcul automatique de #185). Déclencheurs : PATCH d'une étape (runs, ME, structure, calcul automatique), rattachement d'un job ESI qui corrige la structure, matching de la sync des jobs industrie (toutes les 30 min, quand une structure est corrigée), découpage et fusion manuels (groupes enfants), régénération (runs, ME, TE ou durée maximale du projet, `app:industry:regenerate-steps`, `app:industry:regenerate-multi-unit-steps`). Non réversible ; aucune migration.
- **Jobs matchés, achats et stock déplacés** d'un job supprimé vers un job gardé (R4) : rien n'est perdu, mais l'avancement d'un job gardé peut dépasser 100 %.
- **Groupes dissous** : des étapes perdent `splitGroupId` et `totalGroupRuns`.
- **Besoins des enfants en baisse** (moins de runs, arrondi job par job) : listes de courses, coût estimé et marges des projets concernés changent au recalcul (E2 à E4 : un run de Hydrogen Fuel Block en moins dans E4).
- Comptage à faire sur la base de prod avant le déploiement (lecture seule) :

```sql
SELECT project_id, split_group_id, COUNT(*) AS jobs, SUM(runs) AS runs, MAX(total_group_runs) AS total_group_runs
FROM industry_project_steps
WHERE split_group_id IS NOT NULL
GROUP BY project_id, split_group_id
HAVING MIN(quantity) = 0 OR MIN(runs) = 0 OR SUM(runs) <> MAX(total_group_runs);
```

## Glossaire

À reporter dans [`docs/glossary.md`](../glossary.md) :

- **Groupe découpé** (Split group), ajout : « Quand le besoin baisse sous le nombre de jobs, les jobs en trop sont supprimés et les jobs gardés renumérotés à partir de 0 ; un groupe réduit à un job est dissous (spec #131). »
- **Job en trop** (Surplus job), nouveau : job non fixé d'un groupe découpé qui ne recevrait aucun run du besoin au recalcul ; supprimé après absorption par le dernier job gardé (spec #131).
- **Job engagé** (Committed job), nouveau : job d'un groupe découpé qui porte un job ESI matché, un achat, du stock ou la marque « acheté » ; gardé en priorité quand des jobs sont en trop (spec #131).
- **Dissolution** (Split group dissolution), nouveau : retour d'un groupe découpé réduit à un job à une étape intacte (`splitGroupId` et `totalGroupRuns` null, `splitIndex` 0), en gardant identifiant, données et runs fixés du job (spec #131).
- **Runs fixés** (Fixed runs), précision : « un job non fixé qui ne recevrait aucun run est supprimé (spec #131) ».

Carte du module à mettre à jour : `docs/modules/industry.md`, ligne `SplitGroupQuantities` (jobs en trop, dissolution, nouveaux tests).
