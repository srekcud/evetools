# #73 — Matériaux d'un groupe découpé calculés job par job : note de câblage

Base : `fix/split-step-materials-per-job` @ 95f50cc (worktree `evetools-wt-EN`). Spec : `docs/specs/73-split-step-materials-per-job.md`.

Chiffres obtenus sur un prototype jetable (copie du worktree hors dépôt, supprimée depuis), suite Unit complète lancée sur chaque option.

---

## 0. Question bloquante avant la phase rouge : périmètre de R1 à la création (#69)

À la création, le besoin d'un intermédiaire de **fabrication** vient de l'arbre (`IndustryTreeService::buildNode()` l.164-167, une branche à la fois), puis `collectStepsFromTree()` additionne les quantités déjà arrondies de chaque branche (`IndustryProjectFactory.php` l.218-220). C'est l'issue #69 (ouverte). Appliquée à la lettre, R1 (« somme, sur chaque job de chaque étape qui le consomme ») recalcule ce besoin depuis les étapes consolidées, ce qui corrige aussi #69 côté étapes, même sans découpage.

| | Option A — R1 stricte (recommandée) | Option B — Δ de découpage seul |
|---|---|---|
| Règle | besoin = Σ sur les jobs des étapes consommatrices, pour tout intermédiaire (réaction et fabrication) | besoin d'aujourd'hui (arbre ou `recalculateReactionQuantities`) + Σ (matériaux des jobs − matériaux du job non découpé) |
| R1 vérifiée | oui partout (contrôle indépendant en Python sur Widow, Nomad, QM : 0 écart) | non pour les fuel blocks (#69 reste) : Nomad Hydrogen 12960 au lieu de 12855, Oxygen 1815 au lieu de 1735 |
| Code | une seule passe qui remplace `recalculateReactionQuantities()` (supprimée) et l'appel global à `splitLongJobs()` | la même passe, plus un `recalculateReactionQuantities()` conservé et une soustraction du job non découpé |
| Tests cassés | 5 (section 4) | 2 (section 4) |
| Effet de bord | Nomad égal au golden Ravworks : Hydrogen 322 runs, Nitrogen 106 (au lieu de 324 et 107). #69 n'est réglé que pour les étapes : liste d'achats, coût et arbre affiché lisent toujours l'arbre | aucun hors des groupes découpés |

Recommandation : **A**. Elle respecte R1 à la lettre et le code est plus court. Il faut alors ajouter à la spec « #69 corrigé pour les étapes (création et régénération) » et la liste des tests appelés à changer. B ne se justifie que pour garder ce lot strictement limité au découpage.

---

## 1. R2 — création et régénération (`IndustryProjectFactory`)

### Pipeline cible (identique dans `createProject()` l.62-66 et `regenerateSteps()` l.100-104)

```
collectStepsFromTree()     structure des étapes, ME/TE, outputPerRun ; enregistre en plus le niveau de planification
addTimeDataToSteps()       inchangé (timePerRun ne dépend pas des runs)
planStepsJobByJob()        remplace recalculateReactionQuantities() + splitLongJobs()
sortAndCreateSteps()       inchangé
```

### Ordre de traitement : profondeur maximale dans l'arbre, pas le champ `depth`

Le `depth` stocké est celui du premier nœud rencontré (#16 : sur Fernite Carbide, Hydrogen Fuel Block est à la profondeur 1 alors que des réactions de profondeur 1 le consomment). Un tri sur `depth` fixerait donc le besoin du fuel block avant le découpage de ses consommateurs. `collectStepsFromTree()` (l.218-234) doit enregistrer `planningLevel = max(depth des nœuds du blueprint)`. Tout matériau a un `planningLevel` strictement supérieur à celui de chacun de ses consommateurs (chaque nœud enfant est à profondeur + 1). Trier par `planningLevel` croissant suffit donc. Le champ `depth` persisté ne change pas (le test #16 l.185 reste vert).

### `planStepsJobByJob(array $steps, User $user, float $maxJobDurationDays): list` (privée)

1. Précharger les matériaux de toutes les étapes hors copie : `materialRepository->findMaterialEntitiesForBlueprints()` (même appel que l.279). Construire `productTypeId → clé d'étape` (hors copie).
2. Parcourir les étapes par `planningLevel` croissant :
   - copie : passe telle quelle (voir Q3) ;
   - un besoin a été accumulé pour ce produit : `quantity = besoin`, `runs = ceil(besoin / outputPerRun)` (option B : `quantity += Δ`, runs recalculés si Δ ≠ 0) ;
   - découper l'étape seule : `splitLongJobs([$step], $maxJobDurationDays)` (l.474-522, inchangée, appelée étape par étape) ;
   - pour chaque matériau de l'étape **qui a une étape** dans le projet, et pour chaque job : `besoin[matériau] += calculationService->calculateMaterialQuantity(base, job.runs, ME, base%, rig%)` (`IndustryCalculationService.php` l.81-91). ME = `meLevel` de l'étape pour une fabrication, 0 pour une réaction.
3. La racine n'a pas de besoin et garde les valeurs de l'arbre (#2 hors périmètre).

### Bonus de structure : garder la règle de chaque chemin d'aujourd'hui (helper privé `materialBonusOf()`)

- **Matériau produit par réaction** : la règle de `recalculateReactionQuantities()` l.311-329, catégorie du **consommateur** (`getCategoryForProduct(consumer, consumerIsReaction)`) puis `findBestStructureForCategory(user, catégorie, consumerIsReaction)` ; sans catégorie, aucun bonus.
- **Matériau fabriqué** : la règle de l'arbre, `IndustryTreeService.php` l.117-125 et l.149-158 : `findBestStructureForProduct(user, consumer, consumerIsReaction)`, puis, si `getCategoryForProduct(matériau, false)` est non nulle et différente de la catégorie du consommateur, `findBestStructureForCategory(user, catégorieMatériau, false)`.
- La logique reste privée dans la factory, sans nouvelle dépendance : `bonusService`, `calculationService` et `materialRepository` sont déjà injectés (l.30-39). **Le constructeur de la factory ne change pas.** La règle de fabrication est copiée depuis l'arbre, une duplication acceptée puisque l'arbre sera remplacé par le noyau (industry-engine §7 rang 7). Les trois règles (arbre, réaction, `getStructureBonusForStep`) diffèrent entre elles : voir Q2.

### Stock, achat, blacklist

- **Blacklist** : un type exclu n'est pas constructible dans l'arbre (`IndustryTreeService.php` l.173-175), il n'a donc pas d'étape et la boucle l'ignore (« matériau qui a une étape »). Rien à ajouter.
- **Stock et achat** : n'existent pas à la création. La régénération recrée toutes les étapes et perd stock et achat (#21, caractérisé dans l'intégration l.140). Rien ne change.

### Code mort

En A, `recalculateReactionQuantities()` (l.243-350) est supprimée. L'appel global à `splitLongJobs()` disparaît, la méthode reste appelée étape par étape.

---

## 2. R3 — découpage et fusion manuels

### Service partagé : `src/Service/Industry/SplitGroupMaterialNeeds.php` (nom à valider, termes du glossaire : besoin, groupe découpé)

```php
final class SplitGroupMaterialNeeds
{
    public function __construct(
        private readonly IndustryCalculationService $calculationService,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryActivityProductRepository $productRepository,
    ) {}

    /**
     * @param list<int> $jobRunsBefore
     * @param list<int> $jobRunsAfter
     * @return list<IndustryProjectStep> material steps changed
     */
    public function applyChangeOfJobs(IndustryProject $project, IndustryProjectStep $consumer, array $jobRunsBefore, array $jobRunsAfter): array
}
```

Déroulé :
1. Le consommateur est une copie : `[]`.
2. Matériaux SDE du blueprint du consommateur (`findMaterialEntitiesForBlueprints([bp], [activité])`).
3. Bonus : `calculationService->getStructureBonusForStep($consumer)['materialBonus']` (l.102-162), la règle du recalcul (`IndustryStepCalculator.php` l.93-94). Elle respecte la structure assignée à l'étape.
4. Pour chaque matériau dont le produit a des étapes hors copie : `Δ = Σ calculateMaterialQuantity(base, r, ME, base, rig) pour r dans after − même somme pour r dans before`. Si Δ = 0, on passe au suivant (cas limite « arrondi favorable »).
5. Les étapes du matériau sont toutes les étapes hors copie de ce produit (unique ou groupe découpé). `besoin = Σ quantity + Δ`, `outputPerRun` lu dans `productRepository`. Produit absent du SDE : refus explicite (Q4). On calcule tout avant de modifier quoi que ce soit, pour qu'un refus ne laisse rien à moitié fait (le test l.88 exige qu'aucun flush n'ait lieu).
6. Application de la règle du recalcul via un helper extrait (voir ci-dessous). Les étapes achetées ou en stock reçoivent Δ comme au recalcul. Les petits-enfants ne sont pas touchés (décision R3).

### Extraction de la répartition (pas de duplication, pas d'appel à `recalculateStepQuantities()`)

On déplace `IndustryStepCalculator.php` l.132-192 (runs = `ceil(besoin / unités par run)`, étape unique, sinon prorata, `max(1, …)`, dernier job = reste, `SplitGroupQuantities::share()` par `splitIndex`, `totalGroupRuns`) vers une méthode statique `SplitGroupQuantities::applyNeedToSteps(list<IndustryProjectStep> $steps, int $need, int $outputPerRun): list<IndustryProjectStep>` (renvoie les étapes modifiées). `recalculateStepQuantities()` l'appelle à la place du code en ligne, sans changer de comportement. Le choix d'une méthode statique évite de modifier le constructeur de `IndustryStepCalculator`, qui est construit dans 3 tests (`IndustryJobMatcherTest`, `IndustryStepCalculatorTest`, golden l.146).

### Branchement dans les processors

- `SplitStepProcessor` (l.80-119) : `before = [$step->getRuns()]`, `after = $runsBySplitIndex`. Appel après le calcul des runs et avant le flush l.119.
- `MergeStepsProcessor` (l.59-107) : `before = runs des frères` (déjà parcourus l.66-72), `after = [$totalRuns]`. Appel avant le flush l.107.
- Les réponses ne changent pas : le front recharge le projet après un découpage ou une fusion (`frontend/src/stores/industry/steps.ts` l.190-192, l.208-210). Aucun changement côté front, pas de maquette.
- **Les constructeurs changent** : `SplitStepProcessor` et `MergeStepsProcessor` reçoivent `SplitGroupMaterialNeeds`. Le service est câblé automatiquement (`src/Service`), rien à ajouter dans `config/`.

---

## 3. Harnais de test

| Dépendance | Fournie par |
|---|---|
| R2 : aucune nouvelle (factory inchangée côté constructeur) | golden `IndustryProjectFactoryGoldenStepsCharacterizationTest` tel quel : SDE golden (l.957-1011), bonus du pilote (l.1091-1183, `pilotOwnsTataraAndRaitaru`), `typeIdsWithoutBaseTime` (l.99) |
| `SplitGroupMaterialNeeds` dans les tests de processors | instance **réelle**, construite avec les 3 dépendances ci-dessous |
| `IndustryCalculationService` | réel, construit comme dans le golden l.123-132 ou dans `ResolvesTypeNamesFromSde::calculationServiceOnSdeKnowing()` l.62-75, **avec** un stub `IndustryBonusService` configuré : `findBestStructureForProduct` renvoie `['structure' => null, 'bonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], 'category' => null]`. Un `createStub()` brut renvoie `[]`, ce qui provoque une clé indéfinie dans `getStructureBonusForStep()` l.142. Les settings `findOneBy` doivent renvoyer null |
| `IndustryActivityMaterialRepository` | stub `findMaterialEntitiesForBlueprints` → clé `"{bp}-{activityId}"` ; recettes golden des types utilisés (section 5) |
| `IndustryActivityProductRepository` | stub `findProductsForBlueprints` (ou `findOneBy`, selon l'implémentation) ; en garder un seul lu par le service |
| `getStructureBonusForStep` | appelle `$step->getProject()->getUser()` : les étapes doivent être ajoutées au projet (déjà le cas, `stepOfTheProject()` l.112) |

**Squelette minimal à poser en phase rouge** :
- `src/Service/Industry/SplitGroupMaterialNeeds.php` avec le constructeur ci-dessus et `applyChangeOfJobs()` qui renvoie `[]` ;
- le paramètre ajouté aux constructeurs de `SplitStepProcessor` et `MergeStepsProcessor` (non utilisé en rouge) ;
- mise à jour des appels existants : `SplitStepProcessorTest.php` l.143-150 (helper `split()`), `MergeStepsProcessorTest.php` l.75 et l.77. Un service sans matériau (`findMaterialEntitiesForBlueprints` → `[]`) garde ces 6 tests verts.

Recommandation : un trait de test (`BuildsSplitGroupMaterialNeeds`) partagé par les deux tests de processors, qui construit le service sur une petite table de recettes golden (QM, Titanium Carbide, Scorpion, Nestor, Auto-Integrity Preservation Seal, Life Support Backup Unit).

---

## 4. Tests existants appelés à casser (suite Unit complète, 2878 tests)

Fichier : `tests/Unit/Service/Industry/IndustryProjectFactoryGoldenStepsCharacterizationTest.php`.

### Les deux options

| Test | Ligne | Actuel → attendu |
|---|---|---|
| `testSplitConsumerCurrentlyKeepsTheNeedOfTheUnsplitJobAtCreationIssue73` | 502-511 | Titanium Carbide `[1, 490, 1, 0, 0, 0, null]` → `[1, 491, 1, 0, 0, 0, null]` ; le nom perd `Currently…Issue73` (E1) |
| `testQuantitiesOfEachSplitGroupOfOneNomadAddUpToTheQuantityOfTheUnsplitStep` (#112) | 452-464 | contredit R1 : le projet découpé demande plus que le projet non découpé. Découpé / non découpé : Fernite Carbide 9719991 / 9719982, Plasmonic Metamaterials 38168 / 38164, Sylramic Fibers 2538076 / 2538072, Fullerides 950908 / 950905, Nanotransistors 34181 / 34176, Phenolic Composites 64863 / 64860, Ferrogel 11654 / 11653 ; les autres produits découpés sont égaux. À réécrire : par exemple, la quantité de chaque groupe découpé vaut le besoin job par job de ses consommateurs |

### En plus, option A seulement (effet #69)

| Test | Ligne | Actuel → attendu |
|---|---|---|
| `testStepsOfOneNomadCurrentlyOverplanTheFuelBlocksSummedAcrossBranchesIssue69` | 210-228 | Hydrogen `[[162, 6480, …, 0, 324], [162, 6480, …, 1, 324]]` → `[[161, 6440, 5, 10, 20, 0, 322], [161, 6415, 5, 10, 20, 1, 322]]` ; Nitrogen `[[107, 4260, …]]` → `[[106, 4225, 5, 10, 20, 0, null]]` ; Fernite Alloy 550, CECU 951, Nomad, 373 étapes inchangés. Le test perd `Currently…Issue69` (étapes conformes à Ravworks) |
| snapshot `tests/Fixtures/Characterization/IndustryProjectFactory/nomad-1run-me10-no-structure-per-product.json` (lu par le test ci-dessus) | — | 3 entrées : Hydrogen runs 324 → 322, `totalGroupRuns` [324] → [322] ; Nitrogen 107 → 106 ; Oxygen 46 → 44 |
| constante `FIVE_MANUFACTURING_STEPS_OF_ONE_NOMAD_IN_SORT_ORDER` (#110), lue par `testTwoCreationsOfOneNomadGiveTheSameStepsInTheSameSortOrder` (l.346) et `testRegeneratedStepsOfOneNomadKeepTheSortOrderOfTheCreation` (l.357) | 86-92 | Helium `107, 4265` → `107, 4245` ; Hydrogen 0 `162, 6480` → `161, 6440` ; Hydrogen 1 `162, 6480` → `161, 6415` ; Nitrogen `107, 4260` → `106, 4225` ; Oxygen `46, 1815` → `44, 1735` |

### Restent verts (vérifié)

- Snapshots `fernite-carbide-1run-tatara-nullsec.json` (aucun groupe découpé) et `nomad-1run-me10-no-structure-recalculated-per-product.json` (le recalcul réécrit tout).
- Issue16, Tatara 365 jours, issue-112 Oxygen 700 runs (consommateurs réactions à 5 unités par run, Δ = 0), `testOneRunLonger…`, régénérations, `IndustryProjectFactoryTest` (mocks), `CreateProjectProcessorTest`, `RegenerateProjectStepsCommandTest`.
- Intégration `IndustryProjectFactoryRegenerationTest` : aucun découpage (600 s × 5 runs), donc pas d'impact en A ni en B. Elle construit la factory (l.76), mais le constructeur ne change pas.
- Les tests de processors cassent seulement par le constructeur (section 3), pas par les assertions.

Aucun autre test dans `tests/` n'utilise `maxJobDurationDays` (seul `IndustryProjectFactoryTest` l.198 lit la valeur par défaut).

---

## 5. Exemples chiffrés sur le SDE des goldens (sans structure, ME 10 / TE 20)

Formule : `max(runs, ceil(round(base × runs × 0,9, 2)))`.

**E1 / E2** : Quantum Microprocessor 11540, 32 runs, 0,01 jour, Titanium Carbide 16671 sans durée de base : 490 → 491. Même résultat en A et en B. Phenolic Composites 16680 suit aussi, 173 → 174.

**E5 (intermédiaire de fabrication consommé par un groupe découpé)** : Widow 22436, 3 runs, ME 10 / TE 20, durée maximale par défaut 2 jours. Racine découpée en 3 jobs de 1 run (`[1, 1, 0, 10, 20, i, 3]`).
- Magpulse Thruster 11533 (225 par run) : `[608, 608, 1, 10, 20, 0, null]` → `[609, 609, 1, 10, 20, 0, null]` (607,5 → 608 en un job ; 3 × 203 = 609).
- Sustained Shield Emitter 11558 (1634 par run, groupe de 4 jobs) : 4 × `[1103, 1103, …, 4412]` → `[1104, 1104, 1, 10, 20, 0, 4413]` + 3 × `[1103, 1103, …, 4413]` (4411,8 → 4412 ; 3 × 1471 = 4413).
- Cascade (R2 « ses propres matériaux suivent ») : Titanium Carbide 2565054 → 2565096 (257 runs inchangés), Sylramic Fibers 536926 → 536940, Fermionic Condensates 2187 → 2188, Ferrogel 5020 → 5022, Fullerides 60143 → 60145, Phenolic Composites 93496 → 93499.
- Option A seulement : Oxygen Fuel Block `[93, 3710]` → `[91, 3615]`, Helium 715 → 710, Hydrogen 910 → 885, Nitrogen 1480 → 1455. Ces écarts viennent de #69 et apparaissent même à 10 000 jours.

**E3 / E4** : étapes construites à la main, QM 32 runs ME 10 + Titanium Carbide `[1, 490]` (sortie 10000). Découpage 11 + 11 + 10 : Δ = 169 + 169 + 153 − 490 = +1, d'où `[1, 491]`. Fusion : Δ = 490 − 491 = −1, d'où `[1, 490]`.

**E6 (Δ dépasse la production)** : Scorpion 640, 5 runs ME 10, découpage manuel en 5 jobs.
- Auto-Integrity Preservation Seal 57478 (112 par run, sortie 3) : besoin 504, `[168, 504]` ; 5 × ceil(100,8) = 505, Δ = +1 → `[169, 505]`.
- Life Support Backup Unit 57486 (56 par run, sortie 3) : `[84, 252]` ; 5 × 51 = 255, Δ = +3 → `[85, 255]`.
- Fusion des 5 jobs : retour à `[168, 504]` et `[84, 252]`.

**E7 (matériau partagé)** : mêmes étapes que E6 plus Nestor 33472, 1 run ME 10, non découpé, qui consomme 264 AIPS (237,6 → 238) et 132 LSBU (118,8 → 119).
- AIPS `[248, 742]` (504 + 238) → `[248, 743]` : seul Δ = +1 s'ajoute, la part de Nestor (238) est conservée.
- LSBU `[124, 371]` → `[125, 374]` (Δ = +3, les runs changent).

---

## 6. Questions de spec ouvertes

- **Q1 (bloquante)** : option A ou B (section 0). Si A, la spec doit citer #69 comme corrigé pour les étapes, ajouter les 3 tests et le snapshot de la section 4, et laisser la liste d'achats, le coût et l'arbre hors périmètre comme aujourd'hui.
- **Q2** : trois règles de bonus coexistent : l'arbre (catégorie du matériau), les réactions (catégorie du consommateur) et le recalcul (`getStructureBonusForStep`, structure assignée). Proposition : R2 garde les deux premières selon le chemin, R3 suit le recalcul. L'écart n'apparaît qu'avec des structures. À unifier dans une autre issue ?
- **Q3** : les étapes de copie gardent les runs de l'arbre (somme des nœuds), même quand R2 change les runs de la fabrication (Magpulse 608 → 609). Elles sont déjà incohérentes après un recalcul. Hors périmètre ? Dans les goldens, aucune copie n'existe (`fetchOne` → 0), donc aucun test ne le voit.
- **Q4** : en R3, le produit d'une étape matériau absent du SDE : refus 400 sans rien modifier, comme `SplitStepProcessor::getOutputPerRun()` l.140-142, plutôt que le repli à 1 unité par run du recalcul (caractérisé l.687). Recommandé : refus.
- **Q5** : « profondeur par profondeur » (R2) se lit comme « profondeur maximale dans l'arbre » (section 1), parce que le `depth` persisté est faux (#16). C'est une précision à ajouter à la spec, sans changement de règle.
