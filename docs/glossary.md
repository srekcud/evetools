# Glossaire — langage ubiquitaire

Un symbole métier dans le code porte un nom de ce glossaire. Si un terme manque, on l'ajoute ici avant de l'utiliser, au lieu d'inventer un synonyme.

Colonnes : **Terme FR**, **Term EN**, **Définition**, **Symbole(s) dans le code** (vérifiés au 2026-10-07).

## Quantités et production

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **Run** | Run | Une exécution d'un job sur un blueprint. Un run de fabrication produit exactement `outputPerRun` unités du produit. Un BPC porte un nombre maximal de runs. | `IndustryProject::$runs`, `IndustryProjectStep::$runs` |
| **Quantité** | Quantity | Un nombre d'**unités** d'un produit ou d'un matériau (ce qu'on compte dans un hangar). Ne jamais l'employer pour un nombre de runs. | `IndustryProjectStep::$quantity`, `GroupIndustryContribution::$quantity` |
| **Unités par run** | Output per run | Nombre d'unités produites par un run (SDE, `quantity` du produit d'une activité). Vaut 1 pour un vaisseau, et souvent 100 ou plus pour des munitions, missiles ou fuel blocks. | `outputPerRun` (`IndustryTreeService`) |
| **BOM** (nomenclature) | Bill of materials | Liste des matériaux et quantités nécessaires pour produire une quantité donnée d'un produit, ME et bonus appliqués. Une BOM « feuilles » ne contient que les matériaux à acheter (non fabriqués). | `GroupIndustryBomItem`, `IndustryTreeService::buildProductionTree()` |
| **Stockpile** | Stockpile | Quantité cible d'un type d'item à garder en stock. On mesure l'écart entre le stock réel et la cible. | `IndustryStockpileTarget::$targetQuantity`, `StockpileService` |
| **Demande** | Demand | Quantité totale d'un item requise par tous les jobs consommateurs d'un plan, avant déduction du stock. Elle est agrégée sur tout le plan avant d'être arrondie en runs entiers. | aucun pour l'instant (cible : [spec du noyau](specs/industry-engine.md), R3) |
| **Surplus** | Surplus | Unités produites au-delà de la demande, parce que les runs sont entiers : `runs × unités par run − demande` (après déduction du stock). | aucun pour l'instant (cible : spec du noyau, R3) |
| **Job** | Job | Un lancement d'activité (fabrication, réaction, copie, invention) sur un blueprint, pour un nombre de runs, dans une structure. Les matériaux sont calculés job par job : un lot découpé en plusieurs jobs peut demander plus qu'un job unique (spec du noyau, R1 et R13). | `App\Industry\Domain\Job`, `Plan::jobsFor()` (tranche 3, en cours) ; activités : `ActivityKind` |
| **Plan** | Plan | Ensemble des jobs, intermédiaires, feuilles et surplus calculés pour une liste de cibles (produit, runs, ME, TE). En mode plan, les runs sont entiers ; en mode coût marginal, ils sont fractionnaires, sans arrondi ni surplus (spec du noyau, R12). | `Plan`, `MarginalCostPlan`, `ProductionPlanner`, `PlanTarget` (tranche 3, en cours) |
| **Intermédiaire** | Intermediate | Item fabriqué ou réagi dans le plan pour être consommé par un autre job. Sa demande est agrégée sur tout le plan. | `Intermediate`, `Plan::$intermediates` (tranche 3, en cours) |
| **Feuille** | Leaf | Matériau acheté, non fabriqué dans le plan : sans blueprint, blacklisté ou acheté de force. Une BOM « feuilles » est la liste des feuilles. | `Plan::$leaves`, `MarginalCostPlan::$leaves` (tranche 3, en cours) |

**Relations :**

```
quantité produite = runs × unités par run
runs nécessaires  = ceil((demande − stock) / unités par run)
surplus           = quantité produite − (demande − stock)
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
| **EIV** | Estimated Item Value | Valeur de base d'un job : `runs × Σ (quantité de base × adjusted price ESI)`. Les quantités sont celles du SDE, **avant ME et avant tout bonus** de structure ou de rig. Pour la copie et l'invention, on prend les matériaux de fabrication du blueprint concerné, jamais l'adjusted price du produit. | `EsiCostIndexService::calculateEiv()`, `calculateEivFromPrices()` |
| **Cost index** | System cost index | Indice par système solaire et par activité, publié par l'ESI (`/industry/systems/`). Il augmente avec l'activité industrielle du système. | `EsiCostIndexService::getCostIndex()` |
| **Coût d'installation** | Job install cost | Montant payé au lancement d'un job : `base × cost index × bonus de rôle de coût + base × taxe d'installation + surcharge SCC [+ base × 0,25 % pour un clone alpha]`. La base est l'EIV (fabrication, réaction) ou la base de coût de job (copie, invention). Le bonus de rôle ne porte que sur le terme du cost index. L'implémentation actuelle diffère (voir Ambiguïtés). | `EsiCostIndexService::calculateJobInstallCost()`, `IndustryProject::$estimatedJobCost` |
| **Base de coût de job** | Job cost base | Base du coût d'installation de la copie et de l'invention : 2 % de l'EIV de fabrication du blueprint concerné (copié ou inventé), multipliée par les runs. | aucun pour l'instant (aujourd'hui `0.02 * …` dans `InventionService`) |
| **Surcharge SCC** | SCC surcharge | Taxe fixe sur la base du coût d'installation : 4 % en fabrication, réaction et invention. Pour la copie, 4 % en attente de vérification en jeu. Elle ne dépend ni du cost index ni de la structure. | aucun pour l'instant |
| **Coût inconnu** | Unknown cost | Résultat d'un calcul où manque au moins un prix, un adjusted price, un cost index ou une probabilité. Il porte la liste des **données manquantes**. Il n'est jamais remplacé par 0, et une somme qui en contient un est inconnue. Hors du noyau, les services l'exposent par un motif (`unknownReason`) : `missing_invention_probability` (invention, #74) et `missing_material_price` (scanner, #82, avec `missingPriceTypeIds`). | `App\Industry\Domain\Cost` (`Cost::unknown()`, `plus()`) ; `InventionService::UNKNOWN_REASON_MISSING_PROBABILITY`, `BatchProfitScannerService::UNKNOWN_REASON_MISSING_MATERIAL_PRICE` |
| **Donnée manquante** | Missing data | Ce qui rend un coût inconnu : un motif (`adjusted_price`, `cost_index`) et le typeId concerné quand il y en a un (aucun pour un cost index). Deux données manquantes de même motif et même typeId ne sont comptées qu'une fois. | `App\Industry\Domain\MissingData` |
| **Classe de sécurité** | Security class | Sécurité du système d'une structure, qui module le bonus des rigs : highsec, lowsec ou nullsec. L'espace wormhole est traité comme nullsec, affiché « Nullsec / WH » (spec du noyau, D6). Ne pas confondre avec la `securityClass` du SDE stockée sur les systèmes solaires, qui est une autre donnée. | `App\Industry\Domain\SecurityClass` ; côté structure : `IndustryStructureConfig::$securityType` (`'highsec'`, `'lowsec'`, `'nullsec'`) |
| **Bonus de structure** | Structure bonus | Réduction de matériaux ou de temps apportée par le type de structure (Engineering Complex, Refinery…). | `IndustryBonusService`, `IndustryStructureConfig` |
| **Bonus de rig** | Rig bonus | Réduction de matériaux ou de temps apportée par un rig installé sur la structure, pour une catégorie de produits. Modulée par la sécurité du système. | `IndustryStructureConfig::getRigBonus()`, `IndustryBonusService` (rig bonus map) |
| **Structure de production** | Production structure | Structure (ou station NPC) déclarée par un utilisateur avec son type, la sécurité de son système et au plus 3 rigs. Elle porte les bonus de structure et de rig appliqués aux étapes. À ne pas confondre avec la **structure de marché préférée**. | `IndustryStructureConfig`, `StructureConfigResource` |
| **Structure corpo partagée** | Corporation shared structure | Structure de production importée (identifiant ESI connu) dont la corporation de l'utilisateur est propriétaire. Sa configuration est proposée aux autres membres ; chaque membre en garde sa propre copie. | `IndustryStructureConfig::$isCorporationStructure`, `IndustryStructureConfigRepository::findCorporationSharedStructures()`, `CorporationStructureProvider` |
| **Système favori** | Favorite system | Système solaire choisi par l'utilisateur pour une activité (fabrication, réaction). Il fournit le cost index d'une étape quand le système de sa structure est inconnu, et le système par défaut des analyses. Il sert aussi à choisir la structure d'une étape sans structure assignée : la meilleure structure située dans le système favori passe avant la meilleure de toutes (#77, spec du noyau, D3b). Seules les structures importées connaissent leur système. | `IndustryUserSettings::$favoriteManufacturingSystemId`, `$favoriteReactionSystemId`, `IndustryCalculationService::findBestInFavoriteSystem()` |
| **Blacklist** | Blacklist | Liste de groupes ou de types que l'on ne fabrique pas (achetés à la place). Ils sont traités comme des feuilles de la BOM. | `IndustryBlacklistService`, `User::getIndustryBlacklist*()`, `GroupIndustryProject::getBlacklist*()` |

## Industrie collaborative (Group / Corp Industry)

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **Contribution** | Contribution | Apport d'un membre à un projet collaboratif : matériaux, installation de job, BPC ou location de ligne. Elle est soumise à approbation (pending, approved, rejected). Une fois approuvée, elle ne diminue plus. | `GroupIndustryContribution`, `ContributionType`, `ContributionStatus` |
| **Location de ligne** | Line rental | Contribution qui rémunère l'usage d'un slot de production d'un membre, selon un barème (défaut sur l'utilisateur, override possible par projet). | `ContributionType::LineRental`, `User::getLineRentalRates()`, `GroupIndustryProject::getLineRentalRatesOverride()` |
| **Payout** (rétribution) | Payout | Montant reversé à un membre : `coûts apportés × (1 + marge %)`. | `GroupIndustryDistributionService` (`payoutTotal`) |

## Marché

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **Station Jita** | Jita station | Station de marché principale (hub de The Forge). C'est la référence des prix « Jita » : un carnet d'ordres Jita ne garde que les ordres de cette station, sauf repli région (voir **Carnet d'ordres**). | `EveConstants::JITA_STATION_ID`, `EveConstants::THE_FORGE_REGION_ID` |
| **Structure de marché préférée** | Preferred market structure | Structure joueur choisie par l'utilisateur pour comparer ses prix à Jita. Sans choix, certains écrans prennent la **structure de marché par défaut** (paramètre d'instance), d'autres n'affichent aucun prix structure (voir Ambiguïtés). Les prix structure ne viennent que du cache rempli par la sync : pas de lecture ESI à la demande. | `User::getPreferredMarketStructureId()`, `User::getMarketStructures()`, `app.default_market_structure_id`, `MarketService::getDefaultStructureId()` |
| **Lieu de vente** | Sell venue | Marché où l'on suppose vendre un produit pour calculer une marge : `jita` (par défaut) ou `structure`. Vendre à Jita ajoute un coût d'export au m³ ; vendre en structure ne l'ajoute pas. | `sellVenue` (`BatchProfitScannerService`, `BatchScanProvider`), `ProfitMarginService::calculateMargins()` |
| **Ordre de vente / d'achat** | Sell / buy order | Ordre ESI d'un marché, distingué par `is_buy_order`. Son volume est le volume **restant** (`volume_remain`), pas le volume initial. Le meilleur ordre de vente est le moins cher ; le meilleur ordre d'achat est le plus cher. | `JitaMarketService::getSellOrders()`, `getBuyOrders()`, `StructureMarketService::getSellOrders()`, `getBuyOrders()` |
| **Carnet d'ordres** | Order book | Pour un type, les 20 meilleurs ordres de chaque côté (vente croissante, achat décroissante), sous forme `{price, volume}`. Côté Jita : seuls les ordres de la station Jita, ou ceux de toute la région The Forge si la station n'en a aucun de ce côté (le repli se décide côté par côté). Côté structure : tous les ordres de la structure. Le « prix » simple d'un type est le premier ordre du carnet. | `JitaMarketService::getOrderBook()`, `getOrderBooksWithFallback()`, `StructureMarketService::getSellOrders()` |
| **Prix pondéré** | Weighted price | Prix unitaire moyen payé (ou reçu) pour une quantité donnée, en consommant les ordres du carnet du meilleur au pire jusqu'à couvrir la quantité. Il est accompagné de la **couverture** (part de la quantité servie par le carnet, plafonnée à 1) et du nombre d'ordres utilisés. Aucun prix si le carnet est vide ou ne contient aucun volume. | `JitaMarketService::getWeightedSellPrices()`, `getWeightedBuyPrices()` (`weightedPrice`, `coverage`, `ordersUsed`) |
| **Prix percentile** | Cheapest percentile price | Prix unitaire moyen des X % les moins chers du volume en vente (5 % par défaut). On consomme les ordres de vente du moins cher au plus cher jusqu'à X % du volume total du carnet, le dernier ordre étant pris partiellement. Sous une unité de volume cible, c'est le prix de l'ordre le moins cher. Le pourcentage est borné à 100 % ; 0 % ne donne aucun prix. | `JitaMarketService::getCheapestPercentilePrices()` |
| **Volume journalier moyen** | Average daily volume | Moyenne du volume échangé par jour, sur les 30 dernières entrées de l'historique de marché ESI d'une région (The Forge par défaut). Mis en cache 24 h par type et par région. | `JitaMarketService::getAverageDailyVolumes()`, `getAverageDailyVolumesForRegion()`, `getCachedDailyVolumes()` |
| **Prix à la demande** (repli) | On-demand price (fallback) | Lecture ESI directe des ordres d'un type absent du cache synchronisé, avec la même règle de carnet que la sync. Le résultat est mis en cache 5 min par type. Un type dont la lecture échoue reste sans prix et n'est pas mis en cache. | `JitaMarketService::*WithFallback()`, `MarketService::getJitaPrices()` |
| **Adjusted price** | Adjusted price | Prix de référence publié par l'ESI (`/markets/prices/`). Ce n'est pas un prix d'ordre : dans evetools, il sert uniquement à calculer l'EIV. Synchronisé et mis en cache 24 h. | `EsiCostIndexService::getAdjustedPrice()`, `getAdjustedPrices()`, `syncAdjustedPrices()` |

**Durées de vie en cache :** carnets Jita et structure, 2 h ; carnets à la demande, 5 min ; volumes journaliers et adjusted prices, 24 h ; cost indices, 2 h.

## Comptes et ESI

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **Autorisation révoquée** | Revoked authorization | Le refresh token d'un personnage est refusé par EVE SSO (`invalid_grant`) : le joueur a révoqué l'application ou le token a expiré. L'utilisateur passe en auth invalide, ses personnages sortent des syncs planifiées, et il doit se reconnecter par EVE SSO. | `AuthStatus::Invalid`, `User::markAuthInvalid()`, `EveAuthRequiredException` |

## Ambiguïtés connues

- **B1 — runs passés comme une quantité.** `IndustryTreeService::buildProductionTree(int $productTypeId, int $runs, …)` (`src/Service/Industry/IndustryTreeService.php:34`) transmet `$runs` à `buildNode(int $productTypeId, int $quantity, …)` (`:44`). Celui-ci calcule `runs = ceil($quantity / $outputPerRun)` (`:56`). Pour un produit à 100 unités par run demandé sur 10 runs, on obtient `ceil(10 / 100) = 1` run au lieu de 10. Les vaisseaux (1 unité par run) masquent le bug. Correction en Phase 1, en rendant la sémantique explicite (`Runs` / `Quantity`).
- **EIV avec prix manquant.** `calculateEivFromPrices()` remplace un adjusted price manquant par `0.0`, et `calculateEiv()` ignore le matériau. Dans les deux cas, l'EIV est sous-estimée en silence. Règle cible : **Coût inconnu** (spec du noyau, R5 et R10).
- **EIV avant ME** (levée). Les services qui calculent un coût d'installation chargent bien les quantités ME 0 du SDE (`ProfitMarginService.php:300-304`, `ProductionCostService.php:96-100`, `BuyVsBuildService.php:280-284`) : c'est conforme. Seule l'EIV de copie et d'invention (`InventionService::calculateBlueprintEiv()`) prend à tort l'adjusted price du produit (issue #7).
- **Coût d'installation.** L'implémentation actuelle calcule `EIV × runs × cost index × (1 + taxe %)` (`EsiCostIndexService.php:264-266`) : la taxe multiplie le cost index au lieu de s'ajouter, la surcharge SCC, le bonus de rôle et la taxe alpha manquent, et un cost index absent donne 0. Copie et invention reprennent la même forme (`InventionService.php:79`, `:298`). La règle cible est celle de la définition ci-dessus (issue #7, [spec du noyau](specs/industry-engine.md), R6).
- **Carnet Jita limité à la première page ESI.** `/markets/{region}/orders/` est paginé (`X-Pages`), mais la sync Jita et le prix à la demande n'en lisent que la page 1 (`JitaMarketService::orderEndpoints()`, sans paramètre `page`). Quand The Forge a plus d'une page d'ordres pour un type, les ordres suivants sont ignorés : le meilleur prix et le carnet peuvent être faux. Comportement figé par le test de caractérisation `testCharacterizationOnlyTheFirstPageOfOrdersIsReadEvenWhenEsiAnnouncesMorePages` (issue #26). La sync structure, elle, lit toutes les pages.
- **Prix pondéré et prix percentile bornés à 20 ordres.** Le carnet ne garde que 20 ordres par côté. Le prix pondéré d'une grosse quantité est donc calculé sur une couverture partielle, et le « volume total » du prix percentile est celui des 20 meilleurs ordres, pas celui du marché.
- **Volume journalier moyen.** La moyenne porte sur les 30 dernières *entrées* de l'historique ESI, pas sur 30 jours calendaires. Un type sans volume en cache vaut `0.0` dans `getCachedDailyVolumes()`, ce qui ne se distingue pas d'un marché réellement inactif.
- **Adjusted price absent.** `syncAdjustedPrices()` met en cache `0.0` quand l'ESI ne fournit pas d'`adjusted_price` pour un type : `getAdjustedPrice()` renvoie alors `0.0` et non `null`, ce qui contourne la gestion du prix manquant (voir **EIV avec prix manquant**).
- **Système favori sans contrôle du type de structure.** `IndustryCalculationService::findBestInFavoriteSystem()` filtre les structures sur le seul système, sans écarter les raffineries en fabrication ni les Engineering Complex en réaction, et retient une structure à bonus nul. La recherche globale (`IndustryBonusService::findBestStructureForCategory()`) fait ce filtrage. Règle cible : D3b (spec du noyau), « si le favori n'a aucune structure adaptée, la meilleure de toutes avec un avertissement ».
- **Structure de marché par défaut appliquée inégalement.** Sans structure préférée, la comparaison de prix, les réglages et l'historique de marché prennent la structure par défaut de l'instance ; le scanner et la fiche d'un type n'affichent aucun prix structure ; les marges (`ProfitMarginProvider`) prennent l'identifiant de la station Jita comme identifiant de structure.
