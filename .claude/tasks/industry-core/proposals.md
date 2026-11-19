# Propositions de design — noyau de calcul Industry (entrée de l'étape 5 : spec et ADR)

Statut : **propositions, rien n'est décidé.** Les décisions à trancher sont en §7. Les formules F1 à F8 sont celles de `benchmark.md`. Les cas de référence sont dans `golden/`.

## 1. Emplacement et dépendances

```
src/Industry/
  Domain/                     # PHP pur : aucune dépendance Doctrine, Symfony ni HTTP
    Quantity/                 # Runs, Quantity, OutputPerRun, MaterialEfficiency, TimeEfficiency
    Money/                    # Isk, CostOrUnknown (prix absent explicite)
    Facility/                 # Facility, SecurityClass, JobModifiers, FacilityBonusResolver
    Blueprint/                # ActivityKind, BlueprintActivity, MaterialLine (lecture via port)
    Planning/                 # ProductionPlanner, PlanRequest, ProductionPlan, PlannedJob, Leaf, Surplus
    Cost/                     # EstimatedItemValue, JobInstallCost, InventionOdds
    Port/                     # BlueprintCatalog, AdjustedPriceBook, CostIndexBook, RigCatalog (interfaces)
  Application/                # cas d'usage : PlanProject, EstimateProfitMargin, CompareBuyVsBuild…
  Infrastructure/             # adaptateurs : SDE Doctrine, cache ESI (EsiCostIndexService), dogma
```

- Conforme à l'ADR-0002 (couches seulement pour Industry) et aux pistes ADR-0008 (`src/<Contexte>/`).
- deptrac : `Industry\Domain` n'importe que lui-même. `Industry\Application` importe le Domain. Les anciens dossiers `App\Service\Industry\*` peuvent appeler l'Application, jamais l'inverse.
- Doctrine : aucune entité dans le Domain pour le noyau de calcul. Les `IndustryProject` et `IndustryProjectStep` existants restent la persistance, alimentée par l'Application.

## 2. Value objects

| VO | Invariant | Remplace | Termes du glossaire |
|---|---|---|---|
| `Runs` | entier ≥ 1 | `int $runs` passé comme une quantité (B1) | Run |
| `Quantity` | entier ≥ 0, en unités | `int $quantity` | Quantité |
| `OutputPerRun` | entier ≥ 1. `Runs::toProduce(Quantity, OutputPerRun)` = `ceil`, `Runs::produces(OutputPerRun): Quantity` | `ceil($q / $out)` copié dans 6 fichiers | Unités par run |
| `MaterialEfficiency` | 0 à 10 | `int $meLevel` | ME |
| `TimeEfficiency` | 0 à 20, pair | `int $teLevel` | TE |
| `Multiplier` | 0 < m ≤ 1 (bonus) ; `of(...)` multiplie dans l'ordre | les floats `structureBase` / `rig` / `total` | Bonus de structure, bonus de rig |
| `JobModifiers` | `material`, `time`, `cost` : trois `Multiplier` | `array{total, base, rig}` | — |
| `Isk` | montant ≥ 0 (voir D1) | `float` nu | — |
| `CostOrUnknown` | `Known(Isk)` ou `Unknown(list<TypeId> manquants, raison)`. L'addition propage `Unknown` | `?? 0.0`, `if ($price !== null)` | à ajouter : **Coût inconnu** |
| `SecurityClass` | enum `HighSec`, `LowSec`, `NullSec` (voir D6) | `string $securityType` | Sécurité |
| `ActivityKind` | enum `Manufacturing`, `Reaction`, `Copying`, `Invention` | `'manufacturing'`, `'reaction'`, `'copy'` | Réaction, Invention |

Le typage interdit à la compilation (PHPStan) l'erreur B1 : `buildNode(Quantity $needed)` ne peut plus recevoir des `Runs`.

## 3. Une seule formule matériaux

```php
// Domain/Planning/MaterialRequirement.php
public static function forJob(Quantity $basePerRun, Runs $runs, MaterialEfficiency $me, Multiplier $materialModifier): Quantity
// max(runs, ceil(round(runs × base × (1 − ME/100) × modifier, 2)))   — F1
```

- Pour une réaction, `MaterialEfficiency::none()`. Le modificateur exclut le bonus de rôle (F7) : c'est le résolveur qui le garantit, pas l'appelant.
- Tests : les quatre goldens, plus des propriétés (résultat ≥ runs, monotone en runs, ME 10 ≤ ME 0, bruit flottant `x.0000001` → pas de +1).
- Supprime les 4 implémentations du #17 et la variante 1 run du scanner et du pivot (qui appellent `forJob(…, Runs::one(), …)`).

## 4. Une seule règle de bonus

```php
// Domain/Facility/FacilityBonusResolver.php
public function modifiersFor(Facility $facility, ActivityKind $activity, ProductClass $jobProduct): JobModifiers
```

- **Le bonus dépend du produit du job** (F7, référence S1), jamais de la catégorie du matériau consommé : on tranche ainsi le #6.
- Valeurs numériques lues dans les attributs dogma du SDE (`strEng*`, `strReactionTimeMultiplier`, `attributeEngRig*`, `RefRig*`, `attributeThukkerEngRigMatBonus`, `*SecModifier`), via le port `RigCatalog`. Le ciblage rig → groupes/catégories reste la table `IndustryRigCategory` existante (le SDE ne porte pas ce ciblage sous une forme simple, voir D10).
- On garde une seule politique de choix de la structure (D3), dans l'Application et non dans le Domain : `FacilitySelectionPolicy`.
- Supprime les tables de `IndustryBonusService` et `IndustryStructureConfig::get*Bonus()`. Les valeurs affichées par l'API des structures sont calculées par le même résolveur.

## 5. Un seul coût d'installation

```php
// Domain/Cost/EstimatedItemValue.php
public static function of(BlueprintActivity $activity, Runs $runs, AdjustedPriceBook $prices): CostOrUnknown   // F4

// Domain/Cost/JobInstallCost.php
public static function compute(ActivityKind $kind, CostOrUnknown $eiv, ?SystemCostIndex $sci,
    JobModifiers $modifiers, FacilityTax $tax, CloneState $clone): JobInstallCostBreakdown          // F3, F5
// breakdown : systemCost, facilityTax, sccSurcharge, alphaTax, total : CostOrUnknown
```

- Copie et invention : `jobCostBase = 2 % × EIV(fabrication du blueprint concerné)`. Ce n'est jamais l'adjusted price du produit.
- `SystemCostIndex` absent → `Unknown`, pas 0 (#7).
- Le taux SCC est une constante nommée par activité : 4 % en fabrication, réaction, invention et copie, 2 % en recherche ME/TE. La valeur pour la copie reste à confirmer (D11).
- Couvert par `golden/install-cost-vectors.json`.

## 6. Planificateur (remplace `buildProductionTree`)

```php
ProductionPlanner::plan(PlanRequest $request): ProductionPlan
// PlanRequest : list<Target(TypeId, Runs, ME, TE)>, Stock (TypeId → Quantity), BuildPolicy (blacklist / buy), FacilityAssignment
// ProductionPlan : list<PlannedJob> (une ligne par produit, runs, modifiers, inputs), list<Leaf>, list<Surplus>, list<Warning>
```

- Algorithme F2 : ordre topologique, demande agrégée, stock consommé avant le calcul des runs (le sous-arbre est réduit d'autant, #20), runs entiers par lot, surplus explicite.
- Un mode « coût marginal » (lots fractionnaires) pour Profit Margins, le scanner et le pivot (D4).
- Le découpage des jobs reste dans l'Application, mais il rappelle `MaterialRequirement::forJob` par job (D11).
- `IndustryProject` et `IndustryProjectStep` sont mappés depuis `PlannedJob` dans l'Application. Le Domain ne connaît ni les liens de jobs ESI ni les achats.

### Ordre de migration des appelants de `buildProductionTree()`

État au commit `191ad00` : **10 appels dans 7 classes**. Le 11ᵉ (`RegenerateStepsProcessor:60`) a été supprimé avec le code mort (`3f441e0`).

| # | Appelant | Effet | Pourquoi à ce rang | Caractérisation préalable |
|---|---|---|---|---|
| 1 | `ProfitMarginService.php:61` | lecture seule | valeur visible, calcul pur, tests existants (à réécrire : arbre mocké `ProfitMarginServiceTest.php:682-709`) ; embarque F3, F5 et F8 | sortie API de `/profit-margin` sur les 4 goldens |
| 2 | `BuyVsBuildService.php:65` | lecture seule | même arbre, ajoute la décision build/buy récursive | sortie API buy-vs-build |
| 3 | `StockpileService.php:39`, `:85` | lecture seule | petit périmètre, B1 direct | sortie stockpile |
| 4 | `ProjectProvider.php:57` | lecture seule (affichage de l'arbre) | valide le mapping `ProductionPlan` vers l'arbre affiché par le front | snapshot JSON du provider |
| 5 | `IndustryShoppingListBuilder.php:104`, `:124` | lecture seule | supprime le second arbre à overrides : l'écart « sous-optimal » se calcule par job avec le résolveur | liste d'achats sur un projet de test |
| 6 | `GroupIndustryProjectService.php:88` | **écrit** la BOM des projets de groupe | dépend de la PR #63 (memberships) ; les contributions approuvées ne doivent pas bouger | BOM persistée avant/après |
| 7 | `IndustryProjectFactory.php:51`, `:92` (+ `IndustryStepCalculator`, `IndustryCalculationService::calculateMaterialQuantity`) | **écrit** étapes, splits et liens de jobs | le plus risqué (#16, #21) : en dernier, quand le planner est stable | étapes générées sur les goldens, et conservation des liens de jobs à la régénération |

Ensuite, les calculs qui n'appellent pas l'arbre : `BatchProfitScannerService` (`:299`, `:310`), `PivotAdvisorService` (`:170`, `:212`, `:436`), `InventionService` (decryptors, F5, F8), `ProductionCostService`, `EsiCostIndexService::calculate*`. Il faut aussi corriger `CreateStepProcessor` et `SplitStepProcessor` (`setQuantity($runs)`) en passant par les VO.

## 7. Décisions à trancher

Questions fermées. La recommandation est donnée en dernier.

**D1 — Représentation de l'ISK.**
A) `float` encapsulé dans `Isk`, arrondi à 2 décimales à la présentation seulement, tolérance de 1 ISK dans les tests. B) `brick/math` `BigDecimal` (nouvelle dépendance). C) entier en centièmes d'ISK.
→ **A**. Les montants restent sous 1e13, la précision d'un double suffit au centime, et l'ESI ne donne que 4 décimales sur les indices. B ne se justifie que pour la comptabilité corp.

**D2 — Agrégation de la demande des intermédiaires.**
A) Agrégation sur tout le plan, runs entiers par lot, surplus (comme Ravworks). B) Calcul par branche, comme aujourd'hui.
→ **A**. B surestime jusqu'à 50 % sur le golden Nomad.

**D3 — Choix de la structure d'un job (politique unique).**
A) Structure assignée à l'étape, sinon meilleure structure de l'utilisateur pour (activité, produit du job), le système favori servant de filtre optionnel. B) Système favori d'abord, sinon la meilleure structure (comportement actuel de `getStructureBonusForStep`). C) Structure choisie explicitement à la création du plan, sans « meilleure » implicite.
→ **A** : c'est le comportement de Ravworks (« best structure per category ») et il reste prévisible.

**D4 — Modes de calcul.**
A) Deux modes exposés : *plan* (lots entiers et surplus : projets, liste d'achats, stockpile, Group Industry) et *coût marginal* (lots fractionnaires : Profit Margins, scanner, pivot). B) Mode plan partout.
→ **A**. Ravworks fait la même distinction, et un coût marginal en lots entiers fausse le classement du scanner.

**D5 — Prix absent (marché ou adjusted price).**
A) Résultat partiel : total `Unknown` avec la liste des typeId manquants, et le reste du détail affiché. B) Exception qui bloque tout le calcul. C) Exclure l'item et le signaler (scanner).
→ **A** dans le noyau. C reste une présentation possible côté scanner (#22).

**D6 — Wormholes.**
A) Ajouter `SecurityClass::Wormhole`. B) Libellé « Nullsec / WH » sur la valeur `NullSec` (mêmes multiplicateurs dans le SDE, y compris Thukker).
→ **B** : moins de code, aucune différence de calcul connue.

**D7 — Temps de job.**
A) `round` une seule fois sur le job entier (F6), sans implants. B) Garder `ceil` par run. C) A plus les implants BX.
→ **A**. Les implants iront dans une issue séparée si le besoin existe.

**D8 — Stock.**
A) Stock de départ consommé avant le calcul des runs, sous-arbre réduit (#20) ; pas de stock cible dans le noyau. B) A plus un stock cible en fin de plan (comme Ravworks).
→ **A**. Le stock cible reste le Stockpile existant, en Application.

**D9 — Périmètre fonctionnel du noyau v1.**
A) Fabrication, réaction, copie, invention (T2). B) A plus le T3 (reliques). C) A plus la recherche ME/TE.
→ **A**.

**D10 — Source des bonus de structure et de rig.**
A) Valeurs numériques lues dans les attributs dogma du SDE, ciblage par la table `IndustryRigCategory` existante. B) Table codée en dur, corrigée (Athanor, Thukker). C) Ciblage importé depuis une source tierce (Hoboleaks `industrymodifiersources`, utilisée par EVE Ref).
→ **A** : les valeurs suivent les patchs, et le ciblage change rarement. C est à évaluer si la table de ciblage s'avère fausse.

**D11 — Points de règle à confirmer en jeu avant de figer les tests.**
a) SCC de 4 % sur la copie (S1 l'applique, S6 ne cite que la recherche). b) Matériaux recalculés par job lors d'un découpage (règle du jeu) plutôt que sur les runs consolidés (Ravworks).
→ Pour a), un job de copie lancé en jeu tranche (lire le détail du coût). Pour b), recommandation **par job**, puisque evetools suit des jobs réels.

**D12 — Statut des goldens recalculés.**
A) Les goldens « recalculés » ont la même autorité que le golden Ravworks. B) Ils restent provisoires jusqu'à un recoupement externe (plan Ravworks avec structures, ou API EVE Ref).
→ **B** pour `hail-l` et `fernite-carbide` (bonus de structure et de rig) : Sylvain partage un plan Ravworks avec sa config de structures, ou on interroge `api.everef.net/v1/industry/cost` sur ces deux cas. `nitrogen-fuel-block` (sans bonus) peut passer en A tout de suite.

## 8. Ajouts proposés au glossaire

- **Surplus** (Surplus) : unités produites au-delà de la demande, parce que les runs sont entiers (`runs × unités par run − demande`).
- **Demande** (Demand) : quantité totale d'un item requise par tous les jobs consommateurs d'un plan, avant déduction du stock.
- **Base de coût de job** (Job cost base) : 2 % de l'EIV de fabrication, pour la copie et l'invention.
- **Surcharge SCC** (SCC surcharge) : taxe fixe sur l'EIV, de 4 % en fabrication, réaction et invention.
- **Coût inconnu** (Unknown cost) : résultat d'un calcul où au moins un prix ou un indice manque. Il n'est jamais remplacé par 0.
- Corriger **Coût d'installation** (formule F3) et lever l'ambiguïté « EIV avant ME » (vérifiée conforme dans le code, `benchmark.md` F4).
