# Glossaire — langage ubiquitaire

Un symbole métier dans le code porte un nom de ce glossaire. Si un terme manque, on l'ajoute ici avant de l'utiliser, au lieu d'inventer un synonyme.

Colonnes : **Terme FR**, **Term EN**, **Définition**, **Symbole(s) dans le code** (vérifiés au 2026-10-06).

## Quantités et production

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **Run** | Run | Une exécution d'un job sur un blueprint. Un run de fabrication produit exactement `outputPerRun` unités du produit. Un BPC porte un nombre maximal de runs. | `IndustryProject::$runs`, `IndustryProjectStep::$runs` |
| **Quantité** | Quantity | Un nombre d'**unités** d'un produit ou d'un matériau (ce qu'on compte dans un hangar). Ne jamais l'employer pour un nombre de runs. | `IndustryProjectStep::$quantity`, `GroupIndustryContribution::$quantity` |
| **Unités par run** | Output per run | Nombre d'unités produites par un run (SDE, `quantity` du produit d'une activité). Vaut 1 pour un vaisseau, et souvent 100 ou plus pour des munitions, missiles ou fuel blocks. | `outputPerRun` (`IndustryTreeService`) |
| **BOM** (nomenclature) | Bill of materials | Liste des matériaux et quantités nécessaires pour produire une quantité donnée d'un produit, ME et bonus appliqués. Une BOM « feuilles » ne contient que les matériaux à acheter (non fabriqués). | `GroupIndustryBomItem`, `IndustryTreeService::buildProductionTree()` |
| **Stockpile** | Stockpile | Quantité cible d'un type d'item à garder en stock. On mesure l'écart entre le stock réel et la cible. | `IndustryStockpileTarget::$targetQuantity`, `StockpileService` |

**Relations :**

```
quantité produite = runs × unités par run
runs nécessaires  = ceil(quantité demandée / unités par run)
```

## Blueprints et efficacité

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **BPO** | Blueprint Original | Blueprint original, aux runs illimités, qu'on peut rechercher en ME et en TE. | `IndustryProject::$bpoCost` |
| **BPC** | Blueprint Copy | Copie de blueprint aux runs limités. Elle est obtenue par copie d'un BPO, ou par invention pour un T2. | `ContributionType::Bpc`, `IndustryBpcPrice` |
| **ME** | Material Efficiency | Niveau de recherche (0 à 10) qui réduit les matériaux de `ME %`. Par défaut dans evetools : saisi par l'utilisateur à la profondeur 0, et ME 10 aux profondeurs supérieures. | `$meLevel` |
| **TE** | Time Efficiency | Niveau de recherche (0 à 20, par pas de 2) qui réduit la durée du job de `TE %`. Par défaut : TE 20 aux profondeurs supérieures à 0. | `$teLevel` |
| **Invention** | Invention | Activité qui transforme un BPC T1 en BPC T2 avec une probabilité de succès, en consommant des datacores (et éventuellement un decryptor). | `IndustryActivityType::Invention`, `InventionService` |
| **Decryptor** | Decryptor | Item optionnel consommé à l'invention. Il modifie la probabilité de succès, les runs, le ME et le TE du BPC obtenu. | `InventionService::buildDecryptorOptions()` |
| **Réaction** | Reaction | Activité de production en raffinerie, à partir d'une formule de réaction (pas de ME ni de TE sur la formule). Elle produit des matériaux intermédiaires (moon materials, polymères…). | `IndustryActivityType::Reaction`, `IndustryProjectFactory::recalculateReactionQuantities()` |

## Coûts et bonus

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **EIV** | Estimated Item Value | Valeur de base d'un job : la somme, sur les matériaux de base (avant ME) d'un run, de `quantité × adjusted price` ESI. | `EsiCostIndexService::calculateEiv()`, `calculateEivFromPrices()` |
| **Cost index** | System cost index | Indice par système solaire et par activité, publié par l'ESI (`/industry/systems/`). Il augmente avec l'activité industrielle du système. | `EsiCostIndexService::getCostIndex()` |
| **Coût d'installation** | Job install cost | Montant payé au lancement d'un job. Implémentation actuelle : `EIV × runs × cost index × (1 + taxe structure %)`. | `EsiCostIndexService::calculateJobInstallCost()`, `IndustryProject::$estimatedJobCost` |
| **Bonus de structure** | Structure bonus | Réduction de matériaux ou de temps apportée par le type de structure (Engineering Complex, Refinery…). | `IndustryBonusService`, `IndustryStructureConfig` |
| **Bonus de rig** | Rig bonus | Réduction de matériaux ou de temps apportée par un rig installé sur la structure, pour une catégorie de produits. Modulée par la sécurité du système. | `IndustryStructureConfig::getRigBonus()`, `IndustryBonusService` (rig bonus map) |
| **Blacklist** | Blacklist | Liste de groupes ou de types que l'on ne fabrique pas (achetés à la place). Ils sont traités comme des feuilles de la BOM. | `IndustryBlacklistService`, `User::getIndustryBlacklist*()`, `GroupIndustryProject::getBlacklist*()` |

## Industrie collaborative (Group / Corp Industry)

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **Contribution** | Contribution | Apport d'un membre à un projet collaboratif : matériaux, installation de job, BPC ou location de ligne. Elle est soumise à approbation (pending, approved, rejected). Une fois approuvée, elle ne diminue plus. | `GroupIndustryContribution`, `ContributionType`, `ContributionStatus` |
| **Location de ligne** | Line rental | Contribution qui rémunère l'usage d'un slot de production d'un membre, selon un barème (défaut sur l'utilisateur, override possible par projet). | `ContributionType::LineRental`, `User::getLineRentalRates()`, `GroupIndustryProject::getLineRentalRatesOverride()` |
| **Payout** (rétribution) | Payout | Montant reversé à un membre : `coûts apportés × (1 + marge %)`. | `GroupIndustryDistributionService` (`payoutTotal`) |

## Ambiguïtés connues

- **B1 — runs passés comme une quantité.** `IndustryTreeService::buildProductionTree(int $productTypeId, int $runs, …)` (`src/Service/Industry/IndustryTreeService.php:34`) transmet `$runs` à `buildNode(int $productTypeId, int $quantity, …)` (`:44`). Celui-ci calcule `runs = ceil($quantity / $outputPerRun)` (`:56`). Pour un produit à 100 unités par run demandé sur 10 runs, on obtient `ceil(10 / 100) = 1` run au lieu de 10. Les vaisseaux (1 unité par run) masquent le bug. Correction en Phase 1, en rendant la sémantique explicite (`Runs` / `Quantity`).
- **EIV avec prix manquant.** `calculateEivFromPrices()` remplace un adjusted price manquant par `0.0`, et `calculateEiv()` ignore le matériau. Dans les deux cas, l'EIV est sous-estimée en silence. À traiter par `/audit` en Phase 1.
- **Coût d'installation.** La formule actuelle n'inclut ni la surcharge SCC ni les bonus de rôle de structure. À confronter aux golden tests en Phase 1.
