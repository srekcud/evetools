# Spec #182 — « En stock » : une seule règle, portée par le domaine, lue par l'écran

**Statut : rédigée le 2026-10-31 en mode nuit (circuit m), à valider par Sylvain.** Aucune question n'a pu être posée : les choix sont listés dans « Décisions prises en autonomie » ; trois points qui changent un montant visible sont dans « Questions ouvertes », avec une option recommandée. La spec suit l'option recommandée de chacune (Q1 A, Q2 A, Q3 A).

Circuit : `circuit:m` (pas de note de câblage). Harnais disponibles : tests d'entité (`tests/Unit/Entity/`, précédent `IndustryStockpileTargetTest` et son fournisseur de données), `ProductionCostServiceTest` (étape A doublée, étape B réelle avec structure, précédent `testEstimateJobInstallCostsStillCountsAStepPartiallyInStock`), tests du mapper (précédent `IndustryResourceMapperDeletableStepTest`, projets Fernite Carbide sur le SDE golden), `IndustryShoppingListBuilderFromStepsTest` (fixture `oneRunOfFerniteCarbide()`), Vitest pour le store (précédent `frontend/tests/stores/projectsMatchJobsFailedCharacters.spec.ts`, `apiRequest` doublé) et pour `StepTree.vue` (précédent `stepTreeDeletableStep.spec.ts`).

## Contexte

Issue #182 : « en stock » est calculé deux fois, différemment. Le frontend (`compat.ts`) donne la priorité à la quantité achetée sur le stock et exige un stock non nul ; le backend (`IndustryProjectStep::isInStock()`) compare le seul stock à la quantité, donc un job de quantité 0 est « en stock ». `ProductionCostService` lit la règle du backend, l'écran celle du frontend. Attendu : une seule règle calculée à un seul endroit, identique à l'écran et dans les calculs ; cas de l'issue : quantité 100, stock 40, quantité achetée 0 → pas en stock des deux côtés. Le serveur n'a pas pu être lu (`gh` sans réseau au moment de la rédaction) : l'issue est reprise de la demande de lot.

État vérifié sur `fix/group-production-tree` d5c1c5fc (contient `origin/main` 05d85b46, #131, #191/#190/#287 et #202) :

| Site | Aujourd'hui | Appelants, résultat |
|---|---|---|
| `IndustryProjectStep::isInStock()` (l. 248-251) | `inStockQuantity >= quantity` : ignore les achats ; quantité 0 → vrai | `ProductionCostService::estimateJobInstallCosts()` (l. 93) ; `IndustryJobMatcherTest::testInStockStepIsStillMatched` (l. 918) |
| `IndustryProjectStep::getMissingQuantity()` (l. 253-256) | `max(0, quantity − inStockQuantity)` | aucun appelant (`grep` sur `src/` et `tests/`) |
| `IndustryResourceMapper::stepToResource()` (l. 216-337) | `inStockQuantity` (l. 233) ; `purchasedQuantity` = somme des quantités des achats de l'étape dont `typeId` = produit de l'étape (boucle l. 322-329) ; aucun champ « en stock » | toutes les réponses qui portent une étape (`ProjectProvider` l. 47-50, PATCH, POST, découpe, fusion, liaison et matching de jobs) |
| `ProjectStepResource` | `inStockQuantity` (l. 110), `purchasedQuantity` (l. 163-164), `deletable` sans défaut (l. 169) ; pas de `inStock` | — |
| `frontend/src/stores/industry/compat.ts::enrichStep()` (l. 26-29) | `effectiveStock = purchasedQuantity > 0 ? purchasedQuantity : inStockQuantity` ; `inStock = effectiveStock >= quantity && effectiveStock > 0` | `stores/industry/projects.ts` (l. 50, 69, 82, 93, 136), `stores/industry/steps.ts` (l. 43, 59, 84, 92, 123, 227) : chaque étape reçue de l'API |
| `frontend/src/stores/industry/types.ts` | `purchasedQuantity` (l. 82) parmi les champs de l'API ; `inStock` (l. 86) parmi les « champs calculés par `enrichStep()` », requis | 16 fixtures Vitest le renseignent déjà |
| `ProductionCostService::estimateJobInstallCosts()` (l. 80-138) | saute copie et invention (l. 89), puis `isPurchased() \|\| isInStock()` (l. 93) ; sinon `EsiCostIndexService::estimateJobInstallCost()` avec les runs de l'étape | `estimateTotalCost()` → `CostEstimationProvider` (onglet Coût : `jobInstallCost`, `totalCost`, `perUnit`) ; `CreateProjectProcessor` (l. 66, instantané `estimatedJobCost` à la création, sans stock ni achat) |
| `JobInstallCostEstimator::estimate()` → `Domain\Runs` | `new Runs($runs)` refuse 0 run (`InvalidArgumentException`, `Runs::MINIMUM = 1`) ; atteint seulement si le cost index et les prix ajustés sont connus | une étape à 0 run n'y arrive pas aujourd'hui : quantité 0 → `isInStock()` vrai → sautée |
| `ProductionStepNeedsResolver::resolve()` (l. 62-96) | stock d'un produit = Σ `inStockQuantity` de ses jobs (l. 67, `inStockQuantityOf()` l. 157-160), 0 pour une racine ; à fournir = besoin − stock, runs effectifs réduits (stock partiel compris) ; achats ignorés ; marque « acheté » par `hasPurchasedJob()` (l. 165-168) | `IndustryShoppingListBuilder::getShoppingList()` (liste d'achats, coût matériaux de `estimateMaterialCost()`), `ProductionTreeFromSteps` (arbre affiché) |
| `IndustryShoppingListBuilder::getPurchasedQuantities()` (l. 85-96) | somme des achats **par type**, toutes étapes confondues, matériaux compris | `ShoppingListProvider` (l. 220) → `ShoppingListMaterialResource::$purchasedQuantity` : homonyme, autre notion (ligne de la liste d'achats) |
| `CreatePurchaseProcessor::process()` (l. 68-98) | ajoute un achat (transaction wallet ou saisie) à l'étape désignée ; ne touche ni au stock ni à la marque « acheté » | `ShoppingTab.vue::linkSuggestion()` (l. 410) puis rechargement du projet |
| `ShoppingTab.vue::findStepForType()` (l. 288-301) | une transaction d'un type va à la **première** étape (ordre du projet, pas `splitIndex`) de profondeur > 0, hors copie, de ce produit ; sinon à la première racine | `linkSuggestion()` ; `PurchaseSuggestionProvider` (l. 53-58) ne propose que les produits des étapes de profondeur > 0 quand il y en a |
| `UpdateStepProcessor::update()` | stock > 0 refusé sur une racine (l. 88-90, 400 `Cannot mark root products as in stock`) ; stock écrit sans cascade (l. 102-104) | `steps.ts::toggleStepInStock()` (l. 57-71), collage de stock (`useShoppingStockAnalysis.ts::analyzeStock()` l. 213-243, plafonné à la quantité de l'étape, #173) |
| `IndustryProjectStep::isCommitted()` (l. 366-369), `absorb()` (l. 371-385) | stock > 0 ou achat = job engagé ; reprise additionne les stocks | #131, #202 ; sans lien avec « en stock » |
| `IndustryJobMatcher` (l. 206) | lit `isPurchased()` seul | inchangé |

Lecteurs de `step.inStock` côté écran (tous via `enrichStep()`) :

| Lecteur | Usage |
|---|---|
| `StepTree.vue` | durée inconnue (l. 78), durée d'un groupe (l. 89), tri par statut (l. 144), runs à lancer / en cours / terminés (l. 164), libellé et couleur du statut (l. 346-361), personnage recommandé (l. 765), durée affichée (l. 821), case « acheté » masquée si en stock (l. 839) |
| `StepHierarchyTree.vue` | libellé et couleur du statut (l. 47-62), case « acheté » masquée (l. 109) |
| `useProjectTime.ts` (l. 32) | **durée estimée du projet** : étapes ni achetées ni en stock |
| `useShoppingStockAnalysis.ts::performStockAnalysis()` (l. 86) | intermédiaires du stock collé : calculés, non affichés (#173) |
| `IndustryDashboard.vue::projectProgress()` (l. 276-284) | progression d'un projet de la liste ; `ProjectCollectionProvider` ne renvoie pas d'étapes : sans effet |
| `useStockAnalysis.ts` (l. 205, 427) | fonction `useStockAnalysis()` sans appelant (#173) ; seul `parseEveStock` est importé |

Ne lisent pas les étapes d'un projet : `StockpileService` et `StockpileStartingStockReducer` (cibles et plan du noyau), Group Industry (`GroupIndustryProjectService`, BOM du noyau), `ProfitMarginService` et `BuyVsBuildService` (noyau), `PivotAdvisorService` (son `inStock` est le stock d'assets de l'utilisateur, homonyme). Le profit d'un projet lit `estimatedJobCost`, figé à la création (`IndustryResourceMapper` l. 144) : inchangé.

Écart entre les deux règles aujourd'hui (q = quantité, s = `inStockQuantity`, a = quantité achetée du produit) :

| Cas | Backend | Écran |
|---|---|---|
| q 0, s 0, a 0 | en stock | pas en stock |
| a ≥ q > s | pas en stock | en stock |
| s ≥ q > a > 0 | en stock | pas en stock (libellé « In stock » en ambre par la branche du stock partiel, l. 349-350 ; case « acheté » affichée ; durée comptée) |

### Points caducs

| Point | Preuve |
|---|---|
| Références de l'issue | inchangées : `compat.ts` l. 27-29, `ProductionCostService` l. 93 |
| « `step.inStock` lu dans ~9 fichiers » | 6 lecteurs vivants (`StepTree.vue`, `StepHierarchyTree.vue`, `useProjectTime.ts`, `useShoppingStockAnalysis.ts`, `IndustryDashboard.vue` sans effet), `useStockAnalysis.ts` sans appelant, plus `compat.ts` (écriture) et `types.ts` (type) |
| Jobs à quantité 0 | depuis #131, un groupe découpé ne garde plus de job sans besoin, sauf R7 de #131 (besoin 0 : un job gardé et dissous, runs 0, quantité 0). Un besoin 0 demande des consommateurs à 0 run : en pratique données antérieures à #131 (jobs `[runs ≥ 1, quantité 0]`, U7 de #131) jusqu'à leur prochaine cascade |

Restent vrais : les deux règles divergent (achats, quantité 0) ; le coût d'installation suit la règle du backend.

## Règles métier

Notations : **quantité** = `IndustryProjectStep::$quantity` ; **stock** = `inStockQuantity` ; **achats de l'étape** = ses `IndustryStepPurchase` ; **produit** = `productTypeId` de l'étape.

### Une seule règle

- **R1 — Quantité achetée d'une étape.** Somme des quantités des achats de l'étape dont le `typeId` est son produit. Un achat d'un autre type (matériau rattaché à l'étape, ou à la racine par `findStepForType()`) ne compte pas. Portée par le domaine (`IndustryProjectStep::purchasedQuantityOfProduct(): int`, nom indicatif), sans requête. `ProjectStepResource::$purchasedQuantity` vaut cette somme (valeur inchangée).
- **R2 — Étape en stock.** Une étape est **en stock** si et seulement si sa quantité couverte, `max(stock, quantité achetée)`, est **strictement positive** et **au moins égale à sa quantité**. Stock et achats ne s'additionnent pas : le stock collé décrit le hangar, qui contient déjà les items achetés et livrés (avertissement `stockPurchaseWarnings` de #173). La marque « acheté » n'entre pas dans la règle : une étape achetée n'est pas « en stock » pour autant (les deux restent distinctes, comme à l'écran).
- **R3 — Quantité 0.** Conséquence de R2 : une étape de quantité 0 sans stock ni achat de son produit n'est **pas** en stock ; avec du stock ou un achat de son produit, elle l'est. C'est la règle de l'écran.
- **R4 — Par job.** La règle s'applique à chaque étape, job d'un groupe découpé compris, avec son stock et ses achats ; aucune agrégation par produit. Racine et copie suivent la même règle (le stock d'une racine reste refusé par l'API).
- **R5 — Un seul endroit.** `IndustryProjectStep::isInStock()` porte R2 (nom gardé). `getMissingQuantity()`, sans appelant, est supprimée : c'était une seconde définition du stock.

### Écran

- **R6 — Ressource.** `ProjectStepResource::$inStock` (booléen, sans valeur par défaut, comme `deletable`) = `isInStock()`, posé par `IndustryResourceMapper::stepToResource()` : présent dans toute réponse qui porte une étape. La boucle de `purchasedQuantity` du mapper est remplacée par la méthode de R1.
- **R7 — Le front ne recalcule pas.** `compat.ts::enrichStep()` n'écrit plus `inStock` ; `types.ts` range `inStock` parmi les champs de l'API. Tous les lecteurs de `step.inStock` restent tels quels. `purchasedQuantity` reste dans la ressource et le type, sans lecteur côté front.

### Calculs

- **R8 — Coût d'installation.** `ProductionCostService::estimateJobInstallCosts()` saute une étape de fabrication ou de réaction **achetée**, **en stock** (R2) ou **à 0 run** : aucune estimation demandée, absente de `steps`, hors `total`, ne rend jamais le total inconnu (règles de #127, R1 et R2). Les runs nuls sont sautés explicitement : `Domain\Runs` refuse 0 run, et R3 ne les protège plus. Une étape partiellement couverte reste comptée sur tous ses runs (#127 R3).
- **R9 — Liste d'achats et arbre inchangés (Q2 A).** `ProductionStepNeedsResolver` garde le stock = Σ `inStockQuantity` des jobs du produit, stock partiel compris, achats ignorés. La liste d'achats, le coût matériaux et l'arbre affiché ne changent pas.
- **R10 — Inchangés.** `isCommitted()`, `absorb()`, le matcher, `UpdateStepProcessor` (refus du stock sur une racine, aucune cascade sur un stock), Stockpile, Group Industry, marges, Buy vs Build, instantané `estimatedJobCost`.

### Contrat de l'API

| Cas | Avant | Après |
|---|---|---|
| Réponses qui portent une étape | pas de `inStock` | `inStock` en plus |
| `GET /industry/projects/{id}/cost-estimation`, étape couverte par ses achats (a ≥ q > s) | coût d'installation compté | sauté |
| idem, job hérité `[runs ≥ 1, quantité 0]` sans stock ni achat | sauté | compté (Q3 A) |

Pas de consommateur externe connu de l'API.

## Exemples chiffrés

Ces exemples serviront de tests rouges.

### Règle du domaine — `IndustryProjectStepInStockTest` (nouveau, `tests/Unit/Entity/`)

Étape de produit Fernite Alloy (16656). Achats construits comme `DeleteStepProcessorTest::purchaseOf()` ; Hydrogen Fuel Block = 4246. Colonnes « Backend avant » = `isInStock()` actuel, « Écran avant » = `enrichStep()` actuel.

| # | Quantité | Stock | Achats de l'étape | Marque « acheté » | Quantité achetée (R1) | Backend avant | Écran avant | Après |
|---|---|---|---|---|---|---|---|---|
| E1 | 100 | 40 | — | non | 0 | faux | faux | **faux** (cas de l'issue) |
| E2 | 0 | 0 | — | non | 0 | vrai | faux | **faux** |
| E3 | 100 | 0 | 100 × 16656 | non | 100 | faux | vrai | **vrai** |
| E4 | 100 | 0 | 60 × 16656 | non | 60 | faux | faux | **faux** |
| E5 | 100 | 100 | 50 × 16656 | non | 50 | vrai | faux | **vrai** |
| E6 | 100 | 70 | 50 × 16656 | non | 50 | faux | faux | **faux** (la somme donnerait 120) |
| E7 | 100 | 0 | 500 × 4246 | non | 0 | faux | faux | **faux** |
| E8 | 100 | 0 | — | oui | 0 | faux | faux | **faux** |
| E9 | 100 | 30 | 60 × 16656 et 40 × 16656 | non | 100 | faux | vrai | **vrai** |
| E10 | 0 | 5 | — | non | 0 | vrai | vrai | **vrai** |
| E11 | 0 | 0 | 10 × 16656 | non | 10 | vrai | vrai | **vrai** |

### Coût d'installation — `ProductionCostServiceTest`

Montage de `testEstimateJobInstallCostsStillCountsAStepPartiallyInStock` : étape A doublée (10 runs, 1 000 ISK), étape B réelle (produit 11399, blueprint 11398, fabrication, structure du système 30002510, 500 ISK pour 5 runs) ; estimation enregistrée par `recordInstallCostEstimatesOfStepsAAndB()`.

| # | Étape B | Avant | Après |
|---|---|---|---|
| C1 | runs 5, quantité 5, stock 0, achat de 5 × 11399 | `total` 1 500, étapes A et B | `total` 1 000, étape A seule, aucune estimation demandée pour B |
| C2 | runs 5, quantité 5, stock 0, achat de 5 × 34 (autre type) | 1 500, A et B | inchangé (garde-fou) |
| C3 | runs 5, quantité 5, stock 3, achat de 5 × 11399 | 1 500, A et B | 1 000, A seule |
| C4 | runs 5, quantité 5, stock 5, achat de 2 × 11399 | 1 000, A seule | inchangé (garde-fou de E5) |
| C5 | runs 0, quantité 0, ni stock ni achat | 1 000, A seule | inchangé : B sautée par ses 0 run, aucune estimation demandée, aucune exception |
| C6 | runs 5, quantité 0, ni stock ni achat (job hérité) | 1 000, A seule | 1 500, A et B (Q3 A ; option B : inchangé) |
| C7 | runs 5, quantité 0, stock 5 | 1 000, A seule | inchangé (en stock par R3) |
| C8 | `estimateTotalCost`, liste d'achats doublée (2 000 Pyerite à 5 ISK), B de C1 | `materialCost` 10 000, `jobInstallCost` 1 500, `totalCost` 11 500 | `materialCost` 10 000, `jobInstallCost` 1 000, `totalCost` 11 000 |
| C9 | runs 5, quantité 5, stock 2 (cas de l'issue à l'échelle) | 1 500, A et B | inchangé (garde-fou) |

### Ressource — test du mapper (nouveau, précédent `IndustryResourceMapperDeletableStepTest`)

Projet FC4 de la spec #202 (Fernite Carbide `[4, 40 000]`, Fernite Alloy `[2, 400]`, Hydrogen Fuel Block `[1, 30]`), SDE golden.

| # | Cas | `inStock` | `purchasedQuantity` |
|---|---|---|---|
| M1 | Fernite Alloy avec un achat de 400 Fernite Alloy | vrai | 400 |
| M2 | Fernite Alloy avec 400 en stock | vrai | 0 |
| M3 | Fernite Alloy avec un achat de 400 Hydrogen Fuel Block | faux | 0 |
| M4 | Fernite Carbide, Fernite Alloy et Hydrogen Fuel Block sans stock ni achat | faux, faux, faux | 0, 0, 0 |

### Liste d'achats — garde-fou de R9, `IndustryShoppingListBuilderFromStepsTest`

| # | Cas | Attendu |
|---|---|---|
| L1 | `oneRunOfFerniteCarbide()`, l'étape Fernite Alloy porte un achat de Fernite Alloy égal à sa quantité, ni stock ni marque | liste identique à celle du même projet sans achat (Q2 A). Option B : liste identique à celle du même projet où l'étape a ce stock (`setInStockQuantity()`) ; l'écart est la liste des matières premières d'un run de Fernite Alloy hors Hydrogen Fuel Block, valeurs du SDE golden lues par le test |

### Front (Vitest)

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| F1 | `fetchProject` reçoit une étape `quantity` 6, `inStockQuantity` 0, `purchasedQuantity` 6, `inStock: false` | `inStock` vrai dans le store (recalculé) | faux (valeur du serveur) |
| F2 | `fetchProject` reçoit une étape `quantity` 100, `inStockQuantity` 0, `purchasedQuantity` 0, `inStock: true` | faux (recalculé) | vrai (valeur du serveur) |
| F3 | `matchJobs` (test existant « updates the current project steps and jobsCost ») | étapes = `{ ...matchedKestrelStep(), inStock: true }` | étapes = `[matchedKestrelStep()]` |
| F4 | `toggleStepInStock` : la réponse du PATCH porte `inStockQuantity` 100, `quantity` 100, `purchasedQuantity` 50, `inStock: true` (E5) | faux dans le store | vrai |
| F5 | `StepTree`, garde-fou : étape `inStock: true` ; étape `inStock: false`, `purchasedQuantity` = `quantity` | libellé « In stock » sans case « acheté » ; case « acheté » affichée | inchangé |

## Cas limites

- **Groupe découpé** : un achat lié depuis la liste d'achats va à un seul job (`findStepForType()`, premier job de l'ordre du projet). Ce job peut être en stock et les autres non, même si l'achat couvre tout le produit : les autres restent « à lancer » et leur coût d'installation compté. Comportement actuel de l'écran (R4).
- **Achat plus grand que la quantité** : en stock ; aucun report sur les autres jobs.
- **Stock collé et achats du même produit** (E5, E6) : le plus grand des deux couvre, jamais la somme.
- **Racine** : stock refusé par l'API ; un achat manuel dont le type est le produit racine (`POST …/purchases` avec `typeId`) met la racine en stock et retire son coût d'installation, comme l'écran le montrait déjà.
- **Étape achetée et en stock** : libellé « En stock » (priorité de `stepStatusPriority()`), coût d'installation sauté une fois.
- **Quantité 0** : 0 run → aucun coût, « à lancer » à l'écran avec une durée nulle (inchangé) ; runs ≥ 1 (hérité) → coût compté (Q3) et durée comptée à l'écran (inchangé) ; la prochaine cascade le supprime (#131).
- **PATCH du stock** (`toggleStepInStock`, collage) : la réponse porte `inStock` recalculé par le serveur ; pas de cascade (inchangé).
- **Lier ou retirer un achat** : `linkSuggestion()` et `removePurchase()` rechargent déjà le projet : le statut suit.
- **Copie** : jamais en stock en pratique (collage limité à la fabrication et à la réaction, `findStepForType()` exclut la copie) ; hors coût d'installation.

## Hors périmètre

- Déduction des achats dans la liste d'achats, le coût matériaux et l'arbre (Q2).
- Case « acheté » masquée pour une étape en stock (`StepTree.vue` l. 839, `StepHierarchyTree.vue` l. 109) : une étape couverte par ses achats ne peut pas être marquée achetée sans retirer l'achat. Inchangé.
- Affichage du stock partiel (`getRunsCoveredByStock()`, libellé `n/N runs`), dérivé de `inStockQuantity` côté front.
- Couverture par produit sur tous les jobs d'un groupe.
- `ShoppingListMaterialResource::$purchasedQuantity` (par type, toutes étapes), `PivotAdvisorService` (`inStock` = assets), progression du tableau de bord (liste sans étapes), `useStockAnalysis()` sans appelant.
- Cascade après un PATCH du stock.

## Tests existants appelés à casser

- **Front** : `frontend/tests/stores/projectsMatchJobsFailedCharacters.spec.ts` › « updates the current project steps and jobsCost » (l. 132-141) attend `inStock: true`, recalculé depuis `purchasedQuantity` 6 ≥ `quantity` 6 ; le store garde désormais la valeur du serveur (`inStock: false` dans la fixture) : attendu `[matchedKestrelStep()]` (F3). Aucune fixture TypeScript à compléter : `inStock` est déjà requis et renseigné.
- **Backend** : aucun. `ProductionCostServiceTest` double `isInStock()` (nom gardé) et `getRuns()` (toujours ≥ 1 dans `createStep()`) ; ses étapes réelles n'ont pas d'achat. `IndustryJobMatcherTest::testInStockStepIsStillMatched` (quantité 10, stock 10) reste vrai. Les tests qui construisent `new ProjectStepResource()` comme double du mapper ne lisent pas `inStock` (même constat que `deletable` dans #202).

Restent verts (garde-fous) : `ProductionCostServiceTest` (#127 : `…IgnoresAStepFullyInStock`, `…StillCountsAStepPartiallyInStock`, `…IgnoresAPurchasedStep`), `FacilityTaxInstallCostTest`, `IndustryShoppingListBuilderFromStepsTest`, `IndustryShoppingListBuilderGoldenCharacterizationTest`, `ShoppingListProviderGoldenCharacterizationTest`, `ProjectProviderGoldenTreeCharacterizationTest`, `useProjectTime.spec.ts`, `projectDetailUnknownEstimatedProjectTime.spec.ts`, `stepTreeUnknownTimePerRun.spec.ts`, `useShoppingStockAnalysis.spec.ts`, `shoppingTabStockDeductedOnce.spec.ts` (fixtures passées sans `enrichStep()` ou cohérentes avec R2).

## Notes de mise en œuvre (non normatives)

- `IndustryProjectStep` : `purchasedQuantityOfProduct()` (boucle sur `$this->purchases`), `isInStock()` = `max(inStock, achetée) > 0 && max(…) >= quantity` ; suppression de `getMissingQuantity()`.
- `IndustryResourceMapper::stepToResource()` : `$resource->inStock = $step->isInStock();` et `$resource->purchasedQuantity = $step->purchasedQuantityOfProduct();` à la place de la boucle (l. 322-329).
- `ProductionCostService::estimateJobInstallCosts()` l. 93 : ajouter `|| $step->getRuns() === 0`. Ne pas tester la quantité : un `getQuantity()` non doublé rend 0 dans `ProductionCostServiceTest` et sauterait toutes les étapes.
- `compat.ts` : retirer les lignes 27-29 ; `types.ts` : déplacer `inStock` au-dessus du commentaire « Backward-compatible computed fields ».
- Carte du module (`docs/modules/industry.md`) : ligne « Stock, Stockpile » (l. 93), remplacer « stock d'une étape : `IndustryProjectStep::getInStockQuantity` » par la règle `isInStock()` (stock ou achats du produit, quantité couverte > 0) et `ProjectStepResource::$inStock` ; ligne `ProductionCostService` (l. 58) : étapes sautées (achetée, en stock, 0 run).
- Aucune maquette : aucun élément visuel nouveau, seul l'état d'un badge existant change dans le cas E5.

## Questions ouvertes

### Q1 — Les achats du produit comptent-ils dans « en stock » ?

- **Option A (recommandée, suivie)** : oui, R2 (règle de l'écran, avec `max` au lieu de la priorité). L'écran ne change que dans le cas E5 (badge ambre « In stock » → vert, case « acheté » masquée, durée estimée du projet sans cette étape). Montant visible changé : le coût d'installation d'une étape couverte par ses achats sort de l'onglet Coût (C1 : 1 500 → 1 000 ; C8 : `totalCost` 11 500 → 11 000, `perUnit` en proportion).
- **Option B** : non, règle du backend (stock seul) avec R3. Aucun montant ne change ; l'écran change : les étapes E3, E9 et E11 passent de « En stock » à « À lancer », leur case « acheté » réapparaît, la durée estimée du projet les compte.

### Q2 — Déduire les achats du produit dans la liste d'achats et l'arbre ?

Avec Q1 A, une étape couverte par ses achats n'a plus de coût d'installation, mais la liste d'achats achète toujours ses matériaux et l'arbre la montre à fabriquer ; la case « acheté » étant masquée, l'utilisateur ne peut pas la marquer achetée.

- **Option A (recommandée, suivie)** : rien ne change dans ce lot (R9) : liste d'achats et coût matériaux tels qu'affichés aujourd'hui.
- **Option B** : `ProductionStepNeedsResolver` déduit la quantité couverte de chaque job (`max(stock, quantité achetée)`) au lieu du seul stock. La liste d'achats, le coût matériaux et l'arbre baissent des matériaux des runs couverts (L1 : les matières premières d'un run de Fernite Alloy) ; cohérent de bout en bout avec R2. À ouvrir en issue si retenue.

### Q3 — Jobs hérités à runs ≥ 1 et quantité 0

- **Option A (recommandée, suivie)** : comptés au coût d'installation (C6 : 1 000 → 1 500), comme l'écran les montre « à lancer » avec leur durée. Ils disparaissent à la prochaine cascade (#131).
- **Option B** : sautés aussi (quantité 0 = rien à livrer), coût inchangé ; demande de doubler `getQuantity()` dans `createStep()` de `ProductionCostServiceTest`.

## Décisions prises en autonomie

- **Règle de l'écran plutôt que du backend** (Q1 A) : c'est le comportement visible ; l'issue demande que le calcul suive, et #127 justifie déjà de sauter le coût d'une étape qui ne lance pas de job.
- **`max(stock, achats)` plutôt que la priorité aux achats ou la somme** : la priorité cachait un stock suffisant derrière un petit achat (E5, alors que le libellé affichait déjà « In stock » en ambre) ; la somme compterait deux fois les items achetés puis collés. Aucun montant ne change par ce choix (C4) ; seule la durée estimée du projet baisse dans le cas E5.
- **Quantité 0 = formule de l'écran** (R3) : en stock seulement avec du stock ou un achat ; jamais « en stock » avec 0 en stock et 0 acheté.
- **Par job, sans agrégation par produit** (R4) : l'écran et le coût travaillent job par job ; l'agrégation relève de Q2.
- **Marque « acheté » hors de la règle** : deux états distincts à l'écran et dans le résolveur.
- **Champ `inStock` calculé par le serveur**, précédent `deletable` (#202) : une seule source, sans requête ; le front ne recalcule rien.
- **`isInStock()` garde son nom** : vocabulaire de #127, doubles de `ProductionCostServiceTest` intacts.
- **`purchasedQuantity` gardé dans la ressource** : contrat de l'API ; calculé par le domaine, plus par le mapper.
- **`getMissingQuantity()` supprimée** : sans appelant, seconde définition du stock.
- **0 run sauté explicitement au coût d'installation** : invariant de `Domain\Runs`, que R3 ne couvre plus.
- **Liste d'achats inchangée** (Q2 A) et **jobs hérités comptés** (Q3 A) : options qui suivent l'écran.
- **Glossaire non modifié** : termes reportés à l'implémentation.

## Impact prod

- **Aucune migration, aucune commande** ; aucune donnée modifiée au déploiement.
- **Montants visibles changés** (onglet Coût, à la lecture) : coût d'installation, coût total et coût unitaire baissent pour les étapes couvertes par leurs achats sans l'être par le stock (C1, C3, C8) ; ils montent pour les jobs hérités à runs ≥ 1 et quantité 0 (C6). Liste d'achats, coût matériaux, marges, profit et instantané `estimatedJobCost` : inchangés.
- **Écran changé** : étapes au stock suffisant avec un achat plus petit que la quantité (E5) : badge vert, case « acheté » masquée, durée estimée du projet plus courte.
- Comptage en lecture seule avant le déploiement (fabrication et réaction ; les étapes marquées achetées ne changent pas de coût) :

```sql
WITH purchased AS (
  SELECT pu.step_id, SUM(pu.quantity) AS quantity
  FROM industry_step_purchases pu
  JOIN industry_project_steps s ON s.id = pu.step_id AND pu.type_id = s.product_type_id
  GROUP BY pu.step_id
)
SELECT p.status,
  COUNT(*) FILTER (WHERE s.quantity > 0 AND s.in_stock_quantity < s.quantity AND b.quantity >= s.quantity) AS covered_by_purchases_only,
  COUNT(*) FILTER (WHERE s.quantity > 0 AND s.in_stock_quantity >= s.quantity AND b.quantity BETWEEN 1 AND s.quantity - 1) AS stock_behind_smaller_purchases,
  COUNT(*) FILTER (WHERE s.quantity = 0 AND s.runs > 0 AND s.in_stock_quantity = 0 AND b.step_id IS NULL) AS zero_quantity_jobs_with_runs,
  COUNT(*) FILTER (WHERE s.quantity = 0 AND s.in_stock_quantity = 0 AND b.step_id IS NULL) AS zero_quantity_steps_not_in_stock
FROM industry_project_steps s
JOIN industry_projects p ON p.id = s.project_id
LEFT JOIN purchased b ON b.step_id = s.id
WHERE s.activity_type IN ('manufacturing', 'reaction')
  AND s.purchased = false
GROUP BY p.status;
```

## Glossaire

À reporter dans [`docs/glossary.md`](../glossary.md) à l'implémentation :

- **Quantité achetée d'une étape** (Step purchased quantity), nouveau : somme des quantités des achats rattachés à une étape dont le type est le produit de l'étape ; un achat d'un matériau n'y entre pas (spec #182). Symboles : `IndustryProjectStep::purchasedQuantityOfProduct()`, `ProjectStepResource::$purchasedQuantity`. À ne pas confondre avec `ShoppingListMaterialResource::$purchasedQuantity` (par type, toutes étapes).
- **Étape en stock** (In-stock step), nouveau : étape dont le plus grand de son stock et de sa quantité achetée est positif et atteint sa quantité ; la marque « acheté » n'y entre pas ; une étape de quantité 0 sans stock ni achat n'est pas en stock. Une étape en stock n'a pas de coût d'installation (spec #127, #182). Symboles : `IndustryProjectStep::isInStock()`, `ProjectStepResource::$inStock`.
- **Durée estimée du projet**, précision : « en stock » au sens de l'**étape en stock** (spec #182).
