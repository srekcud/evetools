# Benchmark — noyau de calcul Industry (étapes 3 et 4)

Date : 2026-10-07. Lecture seule du code au commit `191ad00`. Les numéros de ligne renvoient à ce commit. Certains diffèrent de ceux des issues, rédigées avant `c8a024d`.

## 0. Sources et méthode

| Id | Source | Licence / statut | Usage |
|---|---|---|---|
| S1 | EVE Ref, `autonomouslogic/eve-ref`, `src/main/java/com/autonomouslogic/everef/industry/` (`ManufactureCalculator`, `IndustryMath`, `InventionCalculator`, `CopyingCalculator`, `IndustryConstants`, `IndustryRigs`) — https://github.com/autonomouslogic/eve-ref | **MIT-0** | Implémentation de référence (validée en CI contre l'API https://api.everef.net/v1/industry/cost) |
| S2 | Fuzzwork Blueprint Calculator, `js/core.js` — https://github.com/fuzzysteve/Eve-Blueprint-Calculator | MIT | Recoupement (matériaux, SCC, invention) |
| S3 | « Formulas for EVE Industry » v2.2 (2016), Adam4EVE — https://static.adam4eve.eu/Guides/Formulas/IndustryFormulas.pdf | document public | Formule d'arrondi `ceil(round(x, 2))`, invention |
| S4 | EVE University, Manufacturing (révision du 2026-01-25) — https://wiki.eveuniversity.org/Manufacturing | wiki CC | Formule du coût, SCC 4 %, alpha 0,25 %, taxe NPC 0,25 % |
| S5 | CCP, « EVE Industry – All you want to know » (2014, Crius) — https://www.eveonline.com/news/view/eve-industry-all-you-want-to-know | officiel | Arrondi par job, minimum 1 par run, base 2 % copie/invention, ME/TE d'invention |
| S6 | CCP, « Exploration & Industry Balance Rework » (Legion, 2025-07-17) — https://www.eveonline.com/news/view/exploration-and-industry-balance-rework | officiel | SCC ramené de 4 % à 2 % pour la **recherche ME/TE uniquement** |
| S7 | « How the Math Works » (2026-07) — https://github.com/beulea/eve-industry-releases/wiki/How-the-Math-Works | wiki public | Recoupement (tableau des structures, sécurité Thukker) |
| S8 | SDE local, base dev `eve_app` (`sde_dgm_type_attributes`) : `strEngMatBonus` (2600), `strEngCostBonus` (2601), `strEngTimeBonus` (2602), `strReactionTimeMultiplier` (2721), `attributeEngRig*Bonus` (2593-2595), `RefRig*Bonus` (2713-2714), `attributeThukkerEngRigMatBonus`, `hi/low/nullSecModifier` (2355-2357), modificateurs des decryptors | données CCP | Valeurs numériques (requêtes du 2026-10-07) |
| S9 | Ravworks : plan https://ravworks.com/plan/CBevjGf, FAQ https://ravworks.com/FAQ, config https://ravworks.com/config | lecture de pages publiques | Golden Nomad, comportement du planner |
| S10 | EVE Forge, annonce et changelog — https://forums.eveonline.com/t/eve-forge-all-in-one-industry-planner-free-open-beta/508372 | public | Comparaison fonctionnelle |

`eve-evaluator` (AGPL) n'a pas été consulté. Aucun code n'est repris : les formules sont réécrites ici.

---

## 1. Formules de référence et écarts

### F1 — Quantité d'un matériau pour un job

```
qty(job, matériau) = max(runs, ceil(round(runs × base × (1 − ME/100) × mod_structure × mod_rig, 2)))
mod_structure = 0,99 pour Raitaru/Azbel/Sotiyo (fabrication seulement), 1 sinon et pour les réactions
mod_rig       = 1 − (bonus_rig% × mod_sécurité_rig) / 100
```

- L'arrondi se fait **par job, pas par run**, avec un minimum d'une unité par run (S5).
- `round(…, 2)` avant `ceil` absorbe le bruit flottant (S3, S1 `ManufactureCalculator.materialQuantity`).
- Les bonus se multiplient, ils ne s'additionnent pas (S1, S2 `core.js:341`, S8).

| Écart evetools | Issue |
|---|---|
| 4 implémentations : `IndustryTreeService.php:128-135`, `IndustryCalculationService.php:81-91`, `IndustryProjectFactory.php:335-338` (reconstruit inline), `BatchProfitScannerService.php:299` et `PivotAdvisorService.php:170,212,436` (`max(1, ceil(base × ME))` : calculé pour 1 run, sans structure, ni rig, ni `round`) | #17 |
| `IndustryProjectFactory.php:312` : ME 10 en dur pour les consommateurs de réactions | #15 |
| `IndustryTreeService.php:120` : catégorie du matériau calculée avec `isReaction=false`, donc un matériau produit par réaction reçoit le bonus de fabrication | #6 |
| Un découpage (`splitLongJobs`, `IndustryProjectFactory.php:478-523` ; `SplitStepProcessor.php:83,95`) ne recalcule pas les matériaux par job. En jeu, deux jobs de 16 runs peuvent consommer plus qu'un job de 32. Le golden Nomad ne montre pas d'écart sur ses deux découpages, mais la règle est par job | nouveau (à créer) |

### F2 — Plan multi-niveaux : agrégation de la demande

```
pour chaque intermédiaire, dans l'ordre topologique (tous ses consommateurs traités avant lui) :
  demande = Σ qty(job consommateur, intermédiaire)     # F1 appliquée à chaque job consommateur
  runs    = ceil((demande − stock disponible) / unités_par_run)
  surplus = runs × unités_par_run − demande
```

C'est le comportement observé sur Ravworks (S9 : « ensuring batched products are made in batches », surplus listés en « End Amount »). Le recalcul indépendant reproduit le plan Nomad à l'unité : 63/63 feuilles et 65/65 jobs (`golden/nomad-1run-me10-no-structure.ravworks.json`).

| Écart evetools | Issue |
|---|---|
| `IndustryTreeService::buildNode` est récursif **par branche**, sans agrégation. Chaque occurrence d'un intermédiaire est arrondie à part (`:56`, `:132-135`, `:164`), puis la factory additionne les quantités déjà arrondies (`IndustryProjectFactory.php:222-224`). **Simulation hors ligne de l'algorithme sur le golden Nomad, sans structure** : 29 feuilles sur 63 sont surestimées (Oxygen Isotopes +50 %, Atmospheric Gases +24 %, Hydrocarbons +19 %, Coolant +12 %…) et 15 jobs ont trop de runs (Carbon Fiber 125 au lieu de 120, Thermosetting Polymer 125 au lieu de 120…) | **nouveau (à créer)**, distinct de B1 |
| B1 : `buildProductionTree(…, int $runs)` passe des runs à `buildNode(int $quantity)`, d'où `runs = ceil(runs / unités_par_run)` (`IndustryTreeService.php:34-36,56`). Golden `nitrogen-fuel-block` : 1 run au lieu de 25, Nitrogen Isotopes 405 au lieu de 10 125 | #2 |
| La même confusion runs/quantité existe hors de l'arbre : `CreateStepProcessor.php:87,130,186` et `SplitStepProcessor.php:83,95` font `setQuantity($runs)`. `UpdateStepProcessor.php:105` et `LinkJobProcessor.php:135` multiplient bien par `outputPerRun` | à rattacher à #2 |
| `IndustryStepCalculator.php:79,157-164` remet les besoins à zéro à chaque profondeur | #16 |
| Le stock d'un intermédiaire réduit sa quantité, mais pas son sous-arbre (`IndustryShoppingListBuilder.php:185-201`) | #20 |

### F3 — Coût d'installation (fabrication et réaction)

```
EIV          = runs × Σ (quantité_base_ME0 × adjusted_price)              (F4)
coût_système = EIV × SCI_activité × mod_coût_structure × mod_coût_rig
total        = coût_système + EIV × taxe_installation + EIV × 4 % (SCC) [+ EIV × 0,25 % si clone alpha]
```

- Sources : S1 (`ManufactureCalculator.calc`, `IndustryMath.systemCostBonuses`, `IndustryConstants.SCC_SURCHARGE_RATE = 0.04`, `ALPHA_CLONE_TAX = 0.0025`), S4, S2 (`core.js:461-464`, SCC 4 % additif, taxe additive).
- `mod_coût_structure` (S8) : Raitaru 0,97, Azbel 0,96, Sotiyo 0,95. Athanor et Tatara n'en ont pas. Les rigs de coût valent 0 depuis leur retrait (`attributeEngRigCostBonus = 0` dans le SDE).
- Le bonus de rôle s'applique **au seul terme SCI**. La taxe, le SCC et la taxe alpha s'appliquent à l'EIV.
- Station NPC : taxe d'installation 0,25 % (S4).
- Le SCC à 2 % de juillet 2025 ne concerne que la recherche ME/TE (S6), pas la fabrication, les réactions ni l'invention.

| Écart evetools | Issue |
|---|---|
| `EsiCostIndexService.php:264-266` : `eiv × runs × index × (1 + taxe)`. La taxe est multipliée par l'index au lieu de s'ajouter, et il manque le SCC, le bonus de rôle et la taxe alpha. Vecteur `install-cost-vectors.json` (EIV 1 M, SCI 5 %, taxe 10 %) : référence **190 000**, evetools **55 000** (−71 %) | #7 |
| Index absent → `0.0` (`EsiCostIndexService.php:259-262`) | #7 |
| La taxe n'est pas transmise par `BuyVsBuildService.php:210-215,286-291`. Le scanner ne l'applique pas non plus (`BatchProfitScannerService.php:310`, `eiv × index`) | #7 |
| Glossaire « Coût d'installation » : sa définition actuelle reprend la formule fausse | à corriger lors de la spec |

### F4 — EIV (Estimated Item Value)

```
EIV(activité de fabrication ou de réaction, runs) = runs × Σ_matériaux (quantité_base_SDE × adjusted_price_ESI)
```

Quantités **avant ME et avant tout bonus** (S4, S5 : « ME0 blueprint with no bonuses », S1 `IndustryMath.eiv`). On vérifie le point en suspens du glossaire : evetools charge bien les quantités ME0 du SDE (`ProfitMarginService.php:300-304`, `ProductionCostService.php:96-100`, `BuyVsBuildService.php:280-284`). L'EIV est donc conforme sur ce point.

| Écart evetools | Issue |
|---|---|
| Un adjusted price absent est mis en cache à `0.0` (`EsiCostIndexService.php:59`), remplacé par `0.0` (`:174`) ou ignoré (`:229`) : l'EIV est sous-estimée en silence. S1 ignore aussi le matériau, mais lève une alerte Sentry | #7, glossaire « EIV avec prix manquant » |
| Une troisième définition, `InventionService::calculateBlueprintEiv` (`:570-583`), prend l'adjusted price **du produit** et non la somme des matériaux | #7 |

### F5 — Copie et invention : coût du job

```
base_coût_job (jcb) = 2 % × EIV(activité de fabrication du blueprint concerné, runs)
coût = jcb × SCI_activité × mod_coût_structure + jcb × taxe + jcb × SCC [+ jcb × alpha]
```

- Copie : EIV des matériaux de fabrication du blueprint copié, multipliée par le total de runs copiés (S1 `CopyingCalculator`, S3 `baseJobCost × SCI × 0.02 × runs × runsPerCopy`).
- Invention : EIV des matériaux de fabrication du **blueprint inventé** (T2), multipliée par les runs d'invention (S1 `InventionCalculator`, S3 « The baseJobCost is that of the output blueprint »). S2 (`core.js:518`) applique encore `(1 + taxe)` en multiplicateur, sans SCC : c'est une formule plus ancienne.
- SCC sur la copie : 4 % chez S1. **À confirmer en jeu** (S6 ne cite que ME/TE).

| Écart evetools | Issue |
|---|---|
| `InventionService.php:79` et `:297-299` : `0.02 × EIV × index × (1 + taxe)`, sans SCC et avec la taxe en multiplicateur | #7 |
| EIV prise sur l'adjusted price du produit (`:570-583`) | #7 |
| Copie facturée pour 1 run par tentative (`:291`) : cohérent avec une copie à 1 run par tentative, à expliciter dans la spec | — |

### F6 — Durée d'un job

```
durée(job) = round(runs × temps_base × (1 − TE/100) × mod_compétences × mod_temps_structure × mod_temps_rig × mod_implant)
fabrication : mod_compétences = (1 − 4 % × Industry) × (1 − 3 % × Advanced Industry) × Π (1 − 1 % × compétence_science_requise)
réaction    : mod_compétences = (1 − 4 % × Reactions)
```

- S1 `ManufactureCalculator.activityTime` arrondit une fois, sur le job entier (`Math.round`). S3 et S2 (`core.js:478`) suivent la même structure.
- Structures (S8) : Raitaru 0,85, Azbel 0,80, Sotiyo 0,70 en fabrication. **Tatara 0,75 en réaction, Athanor aucun bonus.**
- Rigs : bonus de temps 20 % (T1) et 24 % (T2), modulés par la sécurité comme les bonus matériaux.
- Implants BX-801/802/804 : 1 %, 2 %, 4 % (S3).

| Écart evetools | Issue |
|---|---|
| **Athanor à 25 % de bonus de temps en réaction** (`IndustryBonusService.php:35`, `IndustryStructureConfig.php:336`) : faux selon le SDE (seul Tatara a `strReactionTimeMultiplier = 0.75`) | **nouveau (à créer)** |
| `ceil` par run puis multiplication par les runs (`IndustryBonusService.php:456`, `IndustryProjectFactory.php:422`, `IndustryCalculationService.php:265`), au lieu d'un arrondi unique sur le job | mineur, à trancher (D7) |
| Implants absents | hors périmètre ? (D7) |

### F7 — Modificateurs de structure et de rig, sécurité

| Rig | Bonus matériaux | Bonus temps | Haute | Basse | Nulle / WH |
|---|---|---|---|---|---|
| Fabrication M/L/XL, T1 | 2,0 % | 20 % (L/XL « Efficiency », M « Time Efficiency ») | ×1,0 | ×1,9 | ×2,1 |
| Fabrication T2 | 2,4 % | 24 % | ×1,0 | ×1,9 | ×2,1 |
| Réaction (Composite, Hybrid, Biochemical, L-Set Reactor) T1 / T2 | 2,0 / 2,4 % | 20 / 24 % | — (interdit) | ×1,0 | ×1,1 |
| Thukker (composants avancés, XL structure et composants) | 2,0 % | 20 % | ×0,1 | ×1,9 | ×0,1 |
| Thukker (composants capitaux de base) | **3,7 %** (`attributeThukkerEngRigMatBonus`) | 20 % (L) | ×0,1 | ×1,9 | ×0,1 |

Valeurs lues dans le SDE (S8), recoupées avec S1, S2, S7. L'espace wormhole utilise le multiplicateur de la sécurité nulle (S2, S7, et le libellé Ravworks « Null / Wormhole »).

| Écart evetools | Issue |
|---|---|
| Rigs Thukker codés à 2,4 % avec les multiplicateurs standards (`IndustryBonusService.php:609-610,657-658,677`). Réel : 2,0 % (3,7 % pour les composants capitaux), ×1,9 en basse sécurité seulement, ×0,1 ailleurs | nouveau (à créer) |
| `IndustryStructureConfig::getReactionMaterialBonus()` (`:279-292`) applique le multiplicateur **de fabrication** (`:288`, ×1,9/×2,1) aux rigs de réaction. Valeur exposée par l'API des structures (`IndustryResourceMapper.php:56`, `CorporationStructureProvider.php:77`) : affichage faux, alors que le calcul passe par `IndustryBonusService` | nouveau (à créer) |
| Deux tables de bonus parallèles (`IndustryStructureConfig` et `IndustryBonusService`) et une table de rigs codée en dur (`IndustryBonusService.php:574-692`) au lieu des attributs dogma du SDE | #6, #17 |
| Pas de valeur `wormhole` pour `securityType` (`IndustryStructureConfig.php:42`) | à trancher (D6) |
| Trois règles de choix de structure : arbre (meilleure structure pour la catégorie du matériau), factory (catégorie du consommateur), étape (système favori d'abord) | #6 |
| **Règle de référence pour trancher #6** : le bonus de rig dépend du **produit du job** (S1 `IndustryRigs.rigBonus(rig, productType, …)`, avec `productType` = produit fabriqué). L'arbre applique à la consommation d'un job le bonus de la catégorie **de chaque matériau** (`IndustryTreeService.php:113-126`). Exemple : les composants consommés par un job de vaisseau capital reçoivent le bonus du rig « composants capitaux » au lieu de celui du rig « vaisseaux capitaux ». La factory (`IndustryProjectFactory.php:318-330`, catégorie du consommateur) et l'étape (`IndustryStepCalculator.php:313`, produit de l'étape) suivent la bonne règle | #6 (préciser la règle attendue) |

### F8 — Invention : probabilité, decryptors, espérance

```
P        = min(1, base × (1 + (science1 + science2)/30 + encryption/40) × mod_decryptor)
BPC T2   : runs = runs_base + Δruns_decryptor ; ME = 2 + ΔME ; TE = 4 + ΔTE
coût par BPC réussie   = coût_tentative / P         (espérance, pas ceil(1/P))
tentatives pour N runs = N / (P × runs_par_BPC)
```

Sources : S1 (`InventionCalculator.inventionProbability`), S2 (`core.js:507`), S3, S5 (ME 2 / TE 4).

Modificateurs des decryptors (S8, attributs dogma) :

| typeId | Decryptor | P | ME | TE | Runs |
|---|---|---|---|---|---|
| 34201 | Accelerant | 1,2 | +2 | +10 | +1 |
| 34202 | Attainment | 1,8 | −1 | +4 | +4 |
| 34203 | Augmentation | 0,6 | −2 | +2 | +9 |
| 34204 | Parity | 1,5 | +1 | −2 | +3 |
| 34205 | Process | 1,1 | +3 | +6 | 0 |
| 34206 | Symmetry | 1,0 | +1 | +8 | +2 |
| 34207 | Optimized Attainment | 1,9 | +1 | −2 | +2 |
| 34208 | Optimized Augmentation | 0,9 | +2 | 0 | +7 |

| Écart evetools | Issue |
|---|---|
| **Table `InventionService::DECRYPTORS` (`:33-42`) décalée par rapport aux typeId** : 34204 à 34207 portent le nom et les modificateurs du decryptor suivant, Optimized Attainment est à P 1,0 au lieu de 1,9, et Optimized Augmentation à ME −2 au lieu de +2. Le prix est lu sur le typeId (`:263`) : le prix d'un decryptor est combiné aux modificateurs d'un autre | **nouveau (à créer), priorité haute** |
| Pas de compétences dans la probabilité : `P = base × mod_decryptor` (`InventionService.php:266`) | #18 |
| `expectedAttempts = ceil(1/P)` (`:270`) arrondit une espérance | #18 |
| Une seule réussite comptée, quel que soit le nombre de runs (`ProfitMarginService.php:131`) ; ME du decryptor ignoré pour les matériaux | #18 |
| `getProbability() ?? 0.0` (`InventionService.php:161`) : une probabilité absente devient 0, d'où une division par zéro plus loin | nouveau |

### F9 — Vente (hors noyau BOM, rappel)

S7 : taxe de vente `7,5 % × (1 − 0,11 × Accounting)` (valeur à revérifier dans les notes de version courantes). Courtage NPC : `3 % − 0,3 % × Broker Relations − ajustement de standing`. Vendre à un buy order ne coûte que la taxe de vente.

| Écart evetools | Issue |
|---|---|
| Courtage appliqué à une vente à un buy order (`ProfitMarginService.php:374,423`) | #18 |
| Marge sur le coût (`:426`) contre marge sur le revenu (front) | #19 |

---

## 2. Comparaison fonctionnelle

Légende : ✔ couvert, ◐ partiel ou faux, ✘ absent.

| Capacité | Ravworks | EVE Forge | Fuzzwork BP Calc | evetools aujourd'hui | À couvrir dans le noyau |
|---|---|---|---|---|---|
| BOM multi-niveaux complète (réactions comprises) | ✔ | ✔ (« full nested component trees ») | ◐ (un niveau de composants) | ◐ arbre complet, mais B1 et pas d'agrégation (F2) | **Oui** : F1 + F2 |
| Demande agrégée et runs entiers par lot, surplus explicite | ✔ (« End Amount ») | ? | ✘ | ✘ | **Oui** |
| Plusieurs produits par plan | ✔ | ✔ (bulk) | ✘ | ◐ (steps de profondeur 0 ajoutés à la main) | Oui (entrée = liste de produits × runs) |
| Stock de départ consommé, sous-arbre réduit | ✔ | ✔ (assets ESI : REQUIRED / IN STOCK / ELSEWHERE) | ✘ | ◐ (#20) | **Oui** |
| Stock cible à conserver en fin de plan | ✔ (« Stock level target ») | ? | ✘ | ◐ (Stockpile, hors plan) | Option (D8) |
| Blacklist / build vs buy par intermédiaire | ✔ (blacklist, fuel blocks, tools) | ✔ | ✘ | ◐ blacklist ✔, buy vs build au seul niveau 1 (`BuyVsBuildService.php:245-313`) | Oui : décision build/buy par nœud, récursive |
| Structures, rigs et sécurité, meilleure structure par catégorie | ✔ | ✔ (lieux fabrication et réaction séparés) | ◐ (une facility par matériau) | ◐ trois règles divergentes (#6), Athanor, Thukker | **Oui** : une seule règle (F7) |
| Coût d'installation complet (SCC, rôle, taxe) | ✔ (par job, systèmes par activité) | ✔ (SCC) | ✔ (SCC, taxe) | ✘ (F3) | **Oui** : F3 à F5 |
| Prix absent signalé | ? | ? | ✘ | ✘ (`?? 0.0` partout, #8) | **Oui** : valeur « inconnu » explicite |
| Temps de job, compétences | ✔ | ✔ (skills SSO) | ✔ | ✔ (Athanor faux, `ceil` par run) | Oui (F6) |
| Découpage des jobs (durée max, remplissage des slots T2/T3) | ✔ (modes par défaut / T2 / T3) | ✔ (Gantt) | ✘ | ◐ (durée max seulement, matériaux non recalculés) | Oui pour la règle F1 par job, le reste en Application |
| Invention : probabilité avec compétences, decryptors, espérance | ✔ (onglet dédié, hors planner) | ✔ (+ T3) | ✔ | ◐ (F8 : table fausse, sans compétences) | Oui (F8) |
| T3, reliques | ✔ | ✔ | ✘ | ✘ | Hors périmètre (D9) |
| Valorisation des surplus et flux de trésorerie | ✔ (Cash-Flows) | ? | ✘ | ✘ | Option |
| Suivi des jobs ESI réels | ✘ | ✔ (jobs actifs) | ✘ | ✔ (job matching, slots) | Hors noyau (Application) |
| Industrie collaborative | ✘ | ✘ | ✘ | ✔ (Group Industry) | Hors noyau, consommateur du noyau |

Ce que Ravworks fait et que nous devons reproduire pour passer le golden : agrégation (F2), ME 10 / TE 20 sur les intermédiaires, aucune ME sur les réactions, runs entiers par lot, surplus. D'après sa FAQ, l'invention n'entre pas dans le coût du planner. Ses onglets Profit Margins et Invention supposent des lots partiels (« infinite number of the item »). Il y a donc deux modes : **plan** (lots entiers) et **coût marginal** (lots fractionnaires). Le noyau devrait exposer les deux (D4).

---

## 3. Constats principaux

1. **Pas d'agrégation de la demande** (F2) : même sans B1 et sans structure, l'arbre surestime 29 feuilles sur 63 du Nomad, jusqu'à +50 %. C'est le plus gros écart avec Ravworks, et il ne fait l'objet d'aucune issue.
2. **B1 confirmé par deux goldens recalculés** : runs racine = 1 au lieu de 10 (Hail L) et de 25 (Nitrogen Fuel Block). La confusion runs/quantité existe aussi dans `CreateStepProcessor` et `SplitStepProcessor`.
3. **Coût d'installation sous-estimé d'environ 70 %** sur un cas typique (taxe 10 %, SCI 5 %) : SCC absent, taxe multipliée par l'index. L'invention et la copie reposent en plus sur une EIV fausse (adjusted price du produit).
4. **Table des decryptors décalée** : quatre decryptors sur huit ont les modificateurs d'un autre, un a un ME de signe inversé.
5. **Bonus de structure faux à la source et mal rattaché** : l'arbre applique le rig de la catégorie du matériau au lieu de celle du produit du job (#6). S'y ajoutent Athanor à 25 % de temps en réaction, des rigs Thukker mal modélisés et le multiplicateur de fabrication appliqué aux rigs de réaction dans `IndustryStructureConfig`. La correction passe par les attributs dogma du SDE plutôt que par des tables codées en dur.
