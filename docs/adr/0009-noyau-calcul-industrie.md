# ADR-0009 — Noyau de calcul Industrie

- **Statut** : Acceptée
- **Date** : 2026-10-07

## Contexte

Les règles de calcul Industrie sont dupliquées et divergent. Constats au commit `fb9cc98` :

- **Runs lus comme une quantité.** `IndustryTreeService::buildProductionTree(int $runs)` (`src/Service/Industry/IndustryTreeService.php:34`) transmet les runs à `buildNode(int $quantity)` (`:36`, `:44`), qui recalcule `runs = ceil($quantity / $outputPerRun)` (`:56`). La même confusion existe dans `CreateStepProcessor.php:87,130,186` et `SplitStepProcessor.php:83,95` (`setQuantity($runs)`).
- **Formule matériaux en quatre versions** : `IndustryTreeService.php:132-135`, `IndustryProjectFactory.php:335-338`, `IndustryCalculationService.php:87-90`, et une variante sans structure, ni rig, ni `round` dans `BatchProfitScannerService.php:299` et `PivotAdvisorService.php:170,212,436`. `IndustryProjectFactory.php:311-312` fixe ME 10 pour les consommateurs de réactions.
- **Pas d'agrégation de la demande.** `buildNode` est récursif par branche (`IndustryTreeService.php:164`). La factory additionne ensuite des quantités déjà arrondies (`IndustryProjectFactory.php:222-224`). Sur un plan de référence d'un vaisseau capital, 29 feuilles sur 63 sont surestimées.
- **Bonus de structure.** L'arbre prend le bonus de la catégorie du matériau consommé, avec `isReaction=false` (`IndustryTreeService.php:119-125`), la factory celle du consommateur (`IndustryProjectFactory.php:318-330`). Deux tables codées en dur coexistent (`IndustryBonusService.php:31-37,574-692`, `IndustryStructureConfig.php:279-292,335-338`), avec des valeurs fausses par rapport au SDE (Athanor à 25 % en temps, rigs Thukker, multiplicateur de fabrication appliqué aux rigs de réaction).
- **Coût d'installation.** `EsiCostIndexService.php:264-266` calcule `eiv × runs × index × (1 + taxe)` : la taxe multiplie l'index, et le SCC manque. Un index absent donne `0.0` (`:259-262`). Copie et invention reprennent la même erreur (`InventionService.php:77-79,296-299`) sur une EIV prise sur l'adjusted price du produit (`:570-586`).
- **Valeurs par défaut sur des données absentes** : adjusted price à `0.0` (`EsiCostIndexService.php:59,174`), probabilité d'invention à `0.0` (`InventionService.php:161`).
- **Durée** : `ceil` par run puis multiplication (`IndustryBonusService.php:456`, `IndustryProjectFactory.php:422`, `IndustryCalculationService.php:265`).

`buildProductionTree()` est appelée 10 fois dans 7 classes. Corriger chaque copie en place multiplierait les écarts. Les règles cibles ont été recoupées avec des sources externes et validées (décisions D1 à D12 du 2026-10-07). Elles sont décrites dans la [spec du noyau](../specs/industry-engine.md).

## Décision

- **Noyau isolé** dans `src/Industry/Domain/` : PHP pur, sans Doctrine (attributs compris), sans Symfony ni HTTP. Les données du SDE et de l'ESI arrivent par des ports (interfaces) implémentés en `src/Industry/Infrastructure/`. Les cas d'usage vivent en `src/Industry/Application/`.
- **Couches limitées à Industry**, conformément à l'ADR-0002. Corp Industry et Pricing ne sont pas concernés par cette ADR.
- **Value objects** pour les grandeurs métier : `Runs`, `Quantity`, `OutputPerRun`, `MaterialEfficiency`, `TimeEfficiency`, `Multiplier`, `Isk`, un coût connu ou inconnu, `SecurityClass`, `ActivityKind`. Un nombre de runs ne peut plus être passé pour une quantité.
- **Une seule règle par concept** : une formule matériaux, une règle de bonus (valeurs des attributs dogma du SDE, ciblage par `IndustryRigCategory`), une formule d'EIV, un coût d'installation, un planificateur à demande agrégée.
- **Une donnée absente donne un résultat inconnu**, jamais 0.
- **TDD** (ADR-0004) : les exemples chiffrés de la spec sont écrits en tests rouges avant le code. Le MSI du noyau est fixé en cliquet dès sa première mesure (ADR-0005).
- **Strangler** : le noyau est construit à côté de l'existant. Les appelants migrent un par un vers l'Application, du plus sûr (lecture seule) au plus risqué (écriture des étapes), chacun sous un test de caractérisation. L'ancien code est supprimé quand son dernier appelant a migré.

## Conséquences

**Positives**

- Une même entrée donne les mêmes quantités et les mêmes coûts sur tous les écrans.
- Les erreurs de type runs / quantité sont détectées par PHPStan.
- Les bonus suivent les patchs via le SDE, sans table à maintenir.
- Le noyau se teste sans base de données ni conteneur.

**Négatives**

- Deux implémentations coexistent pendant la migration : un écran migré et un écran non migré peuvent afficher des chiffres différents.
- Des chiffres visibles vont changer (coût d'installation plus élevé, BOM plus basse sur les produits à plusieurs niveaux, runs corrects sur les produits à plusieurs unités par run). Il faut le signaler aux utilisateurs.
- Un mapping est nécessaire entre le plan du noyau et les entités `IndustryProject` / `IndustryProjectStep`, qui restent la persistance.
- Les goldens Hail L et Fernite Carbide restent provisoires tant qu'ils ne sont pas recoupés (D12), et le SCC de la copie attend une vérification en jeu (D11a).

**Suites**

- Ajouter les couches `Industry\Domain`, `Industry\Application` et `Industry\Infrastructure` à `deptrac.yaml` (aujourd'hui en rapport seul, `deptrac.yaml:1-3`), le Domain n'important que lui-même.
- Ajouter un périmètre Infection pour `src/Industry/Domain/`.
- L'ADR-0008 (structure de code, en attente) reprend l'emplacement `src/Industry/` décidé ici, ou le remplace explicitement.
