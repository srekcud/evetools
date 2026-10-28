# Spec #151 — Choix de structure : système favori en filtre souple, coût d'installation pour copie et invention

- **Statut** : rédigée le 2026-10-26, en attente de validation. Les deux décisions de fond sont celles du 2026-10-22 (commentaire de l'issue), non rediscutées.
- **Termes en gras** : [glossaire](../glossary.md).

## Contexte

Le **choix de structure** d'un job (`StructureSelector::select()`) a deux défauts.

1. **D3 et D3b se contredisent** ([spec du noyau](industry-engine.md), R4 et points ouverts). D3 fait du **système favori** un filtre ; D3b dit qu'à bonus égal on préfère le favori et que, sans structure adaptée dans le favori, on prend la meilleure de toutes avec un avertissement. Le code applique un filtre strict (`StructureSelector.php:50-56`) : une structure du favori sans rig adapté passe devant une meilleure structure ailleurs, sans avertissement.
2. **Copie et invention n'ont pas de critère.** `ProductionCostCalculator::installCostOutsidePlan()` appelle `select($activity, null)` (`ProductionCostCalculator.php:228`) : sans catégorie de produit, le multiplicateur de matériaux vaut le bonus de rôle pour toutes les structures de même type (`ProductionStructure.php:45-48`), hors fabrication il vaut 1. Tout est à égalité et le départage retombe sur `configuredAt` (`StructureSelector.php:72-76`) : une Raitaru à 10 % de taxe configurée récemment passe devant une Sotiyo à 0 %.

Décisions du 2026-10-22 :

- **Système favori : filtre souple.** On retient la meilleure structure du système favori si elle a un bonus pour l'activité et le produit du job ; sinon la meilleure de toutes, avec un avertissement. Le filtre strict est remplacé.
- **Copie et invention : coût d'installation minimal** (bonus de coût, taxe de la structure, indice du système). Le système favori de l'activité sert à départager.

## Règles métier

### Fabrication et réaction

1. **Priorité inchangée.** Une **structure assignée** qui peut faire l'activité prime (#142, #149). Sans assignée, ou avec une assignée ignorée (`canRun()` faux), les règles 2 à 8 choisissent ; l'avertissement **structure assignée ignorée** s'ajoute alors à celui de la règle 5.
2. **Candidates.** Les structures de l'utilisateur dont le type peut faire l'activité (`StructureType::canRun()`).
3. **Structure adaptée.** Une candidate est adaptée au job si au moins un de ses rigs cible la catégorie de produit du job (`StructureRig::targets()`). Le bonus de rôle ne compte pas : il est le même pour toutes les structures d'un type, un filtre dessus ne filtrerait rien en fabrication.
4. **Favori avec une structure adaptée.** Le système favori de l'activité est défini et contient au moins une candidate adaptée : on retient la meilleure d'entre elles. Statut `BestInFavoriteSystem`, aucun avertissement. Elle l'emporte même si une structure hors favori a un meilleur multiplicateur.
5. **Favori sans structure adaptée.** Le favori est défini mais ne contient aucune candidate adaptée (ou aucune candidate) : on retient la meilleure de toutes les candidates, avec un **avertissement** (statut `BestOutsideFavoriteSystem` aujourd'hui). L'avertissement porte sur « le favori n'a rien d'adapté », même si la structure retenue, faute de mieux, est dans le favori.
6. **Pas de favori.** Meilleure de toutes les candidates, statut `BestConfigured`, aucun avertissement.
7. **Ordre « meilleure ».** Plus petit multiplicateur de matériaux ; à égalité, structure du système favori (si défini) ; puis la plus récemment configurée ; puis `id` croissant (ordre total, résultat indépendant de l'ordre de lecture). Dans la règle 4 toutes les candidates sont dans le favori : le départage favori est sans effet.
8. **Produit sans catégorie de rig.** Aucune structure ne peut être adaptée : ni filtre ni avertissement. Le favori ne sert qu'au départage de la règle 7 ; statut `BestInFavoriteSystem` si la structure retenue est dans le favori, `BestConfigured` sinon.
9. **Système inconnu.** Une structure sans système connu (structure non importée) n'est jamais dans le système favori (inchangé).
10. **Aucune candidate.** `NotConfigured`, multiplicateur 1, station NPC (inchangé).

### Copie et invention

11. **Pas de filtre.** Candidates : les structures dont le type peut faire l'activité (Engineering Complex ; une raffinerie n'est jamais candidate). Le favori ne filtre pas, il départage (règle 14).
12. **Taux d'installation d'une structure** : `cost index × bonus de rôle de coût + taxe d'installation` (fractions). Dans R6, `total = base × (cost index × bonus + taxe + SCC + alpha)` : base, SCC et alpha sont identiques pour toutes les structures d'un même job, l'ordre des **coûts d'installation** est donc celui des taux. On compare les **taux**, pas les montants : le montant dépend de l'EIV, qui peut être inconnue (adjusted price absent) alors que la structure doit être choisie quand même.
13. **Cost index** lu pour l'activité (`copying` ou `invention`) dans le système de la structure, à défaut dans le système favori de l'activité (comme `ProductionCostCalculator::solarSystemOf()`). Le système favori d'une copie ou d'une invention est celui de la fabrication (`FavoriteSystems::forActivity()`).
14. **Retenue.** Taux minimal. Deux taux sont égaux s'ils diffèrent de moins de 1e-9 (bruit flottant). À égalité : structure du système favori, puis la plus récemment configurée, puis `id` croissant.
15. **Statut.** `BestInFavoriteSystem` si la structure retenue est dans le système favori, `BestConfigured` sinon. Jamais d'avertissement de favori : il n'y a pas de filtre.
16. **Un seul critère, deux activités.** Copie et invention passent par la même sélection ; le résultat est le même en mode plan et en mode coût marginal (la sélection ne dépend ni des runs ni de l'EIV).
17. **Taxe non saisie = 0 %** (glossaire, #145) : une structure sans taxe saisie a donc le taux le plus bas à index égal.

### Contrat technique

18. Le sélecteur a besoin du port `SystemCostIndices` pour la règle 13 ; `ProductionCostCalculator` et `FacilityTaxResolver` le lui fournissent. La signature de `select()` ne change pas.

## Exemples chiffrés (tests rouges)

Multiplicateurs de matériaux (catégorie `ammunition`, vérifiés dans `StructureSelectorTest`) : Raitaru sans rig 0,99 ; rig T1 2,0 % en lowsec 0,95238 ; rig T2 2,4 % en nullsec 0,940104. Bonus de rôle de coût : Raitaru 0,97, Sotiyo 0,95 (R4). Les indices et taxes des exemples de copie sont des entrées de test ; les taux sont calculés à la main à partir d'elles.

### Fabrication

`F` : système favori de fabrication.

| Cas | Structures (système, configurée) | Attendu | Aujourd'hui |
|---|---|---|---|
| F1 favori sans rig adapté, meilleure ailleurs | A : Raitaru en F sans rig (0,99) ; B : Raitaru hors F, rig T2 nullsec (0,940104) | B, 0,940104, `BestOutsideFavoriteSystem` (avertissement) | A, sans avertissement |
| F2 favori adapté, moins bon qu'ailleurs | A : Raitaru en F, rig T1 lowsec (0,95238) ; B : Raitaru hors F, rig T2 nullsec (0,940104) | A, 0,95238, `BestInFavoriteSystem`, sans avertissement | A |
| F3 deux structures dans le favori | A : en F, sans rig, configurée en juin ; B : en F, rig T2, configurée en janvier | B, 0,940104, `BestInFavoriteSystem` | B |
| F4 rig d'une autre catégorie dans le favori | A : en F, rig T2 de catégorie `capital_ship` ; B : hors F, rig T2 `ammunition` (produit `ammunition`) | B, avertissement (A n'est pas adaptée) | A |
| F5 bonus égal, aucune adaptée | favori-janvier, favori-juin, favori-mars en F ; ailleurs-septembre hors F ; toutes sans rig (0,99) | favori-juin, `BestOutsideFavoriteSystem` (avertissement : aucune adaptée) | favori-juin, `BestInFavoriteSystem` |
| F6 bonus égal, favori contre récente | A : en F, sans rig, janvier ; B : hors F, sans rig, septembre | A, avertissement | A, sans avertissement |
| F7 réaction : favori de réaction sans rig adapté | A : Tatara en système de réaction favori, sans rig ; B : Tatara en système de fabrication favori, rig T2 `composite_reaction` nullsec (0,9736) | B, 0,9736, avertissement | A |
| F8 produit sans catégorie | A : Raitaru en F, sans rig, janvier ; B : Raitaru hors F, sans rig, septembre | A, `BestInFavoriteSystem`, sans avertissement | A |
| F9 assignée ignorée et favori sans adaptée | assignée : Tatara pour une fabrication ; A : Raitaru en F sans rig ; B : Raitaru hors F rig T1 lowsec (0,95238) | B, 0,95238, avertissement de favori **et** `ignoredAssignedStructure` renseignée | A, sans avertissement de favori |
| F10 sans favori | A : rig T2 nullsec, janvier ; B : sans rig, juin | A, `BestConfigured` | A |

Coûts d'installation et matériaux des jobs complets (calculateur) : `[TO CONFIRM: montants calculés par test-writer depuis les goldens Hail L pour F1 et F2]`.

### Copie et invention

Même système S pour les structures, sauf indication ; cost index de S : 0,05 (valeur de §4.5 du noyau). Taux = `index × bonus + taxe`.

| Cas | Structures | Taux | Attendu | Aujourd'hui |
|---|---|---|---|---|
| C1 copie : taxe contre ancienneté | A : Raitaru, taxe 10 %, configurée en septembre ; B : Sotiyo, taxe 0 %, configurée en janvier | A : 0,05 × 0,97 + 0,10 = 0,1485 ; B : 0,05 × 0,95 + 0 = 0,0475 | B | A |
| C2 invention, même cas | idem C1, activité invention (indice `invention` 0,05) | idem | B | A |
| C3 bonus de coût à taxe égale | A : Raitaru, taxe 5 %, juin ; B : Sotiyo, taxe 5 %, janvier | A : 0,0485 + 0,05 = 0,0985 ; B : 0,0475 + 0,05 = 0,0975 | B | A |
| C4 index différents | A : Raitaru en S1 (indice 0,10), taxe 0 % ; B : Raitaru en S2 (indice 0,02), taxe 5 % | A : 0,10 × 0,97 = 0,097 ; B : 0,02 × 0,97 + 0,05 = 0,0694 | B | selon `configuredAt` |
| C5 égalité parfaite, un en favori | A et B de même taux ; A en S favori, janvier ; B hors favori, septembre | égaux | A, `BestInFavoriteSystem` | B |
| C6 égalité parfaite, aucun en favori | A et B de même taux, janvier et septembre | égaux | B (la plus récente), `BestConfigured` | B |
| C7 égalité parfaite et même date | A (`id` `aaa`) et B (`id` `bbb`), même taux, même `configuredAt` | égaux | A (`id` croissant) | ordre de lecture |
| C8 aucune structure | liste vide | — | `NotConfigured`, station NPC 0,25 %, indice du système favori | idem |
| C9 indice inconnu pour une candidate | A : système sans indice ; B : indice 0,05, taxe 10 % | A inconnu ; B 0,1485 | B, coût connu | selon `configuredAt` |
| C10 indice inconnu pour toutes | A et B : systèmes sans indice | inconnus | départage de la règle 14 ; coût d'installation **inconnu**, donnée manquante `cost_index` | idem |

Montants d'installation de C1 à C4 (base = 2 % de l'EIV de fabrication du blueprint concerné) : `[TO CONFIRM: totaux en ISK, SCC de la copie en attente de D11a, à calculer par test-writer depuis les goldens de coût d'installation]`.

## Cas limites

| Cas | Comportement attendu |
|---|---|
| **Indice de coût inconnu** pour le système d'une candidate (copie, invention) | Jamais traité comme 0. Une candidate au taux inconnu passe après toutes les candidates au taux connu : on ne choisit pas sur une supposition. Si toutes sont inconnues, départage de la règle 14 sans le taux ; le coût du job est inconnu (`InstallCost::forJob()`, `InstallCost.php:55-57`, `MissingData::costIndex()`), comme aujourd'hui. Voir question Q5 |
| Structure sans système connu et pas de favori pour l'activité | Taux inconnu : même traitement |
| **Égalité parfaite** de taux | Règle 14 : favori, plus récente, `id` croissant |
| **Aucune structure configurée** | `NotConfigured`, station NPC 0,25 %, sans bonus de coût (`ProductionCostCalculator.php:36`, `:247-248`) |
| Aucune candidate pour l'activité (ex. Raitaru seule pour une réaction) | Idem `NotConfigured`, avec l'avertissement **structure assignée ignorée** si une assignée a été écartée |
| **Structure assignée** (#142, #149) | Inchangée : prime si `canRun()` ; sinon ignorée, la règle 2 à 8 choisit et l'avertissement s'ajoute. Copie et invention n'ont pas de structure assignée |
| Favori défini pour l'autre activité seulement | Pas de favori pour celle-ci : règle 6 |
| **Mode plan ou marginal** | Fabrication et réaction : une sélection par job et par typeId du produit, identique dans les deux modes (`ProductionCostCalculator.php:117`, `:138`, `:147`) ; un job découpé par le plafond de runs garde la même structure. Copie et invention : calculées une fois, hors du mode (`ProductionCostCalculator.php:62`, `:184-195`) ; le taux ne dépend ni des runs, ni de l'EIV, ni de leur fraction |
| EIV inconnue (adjusted price absent) | La structure est choisie sur le taux ; le coût du job est inconnu, comme aujourd'hui |
| Taxe non saisie | 0 % (glossaire). Voir question Q4 |
| **`FacilityTaxResolver`** | Pas concerné par le critère de copie et d'invention : ses appelants ne l'interrogent qu'en fabrication et en réaction (`PivotAdvisorService.php:73-74`, `BatchProfitScannerService.php:286-287`). Il appelle `select($activity, null)` avec le système demandé comme favori des deux activités : produit sans catégorie, règle 8, résultat inchangé (favori au départage, puis la plus récente). S'il était appelé pour une copie, le critère s'appliquerait par le sélecteur. Seule modification : fournir `SystemCostIndices` au sélecteur (règle 18) |

## Hors périmètre

- Chemin historique des étapes de projet : `IndustryCalculationService::findBestInFavoriteSystem()` (filtre strict, structure à bonus nul acceptée) et `ProjectStepStructureResolver`. Ils s'alignent à la migration des appelants (#141) ; aucune modification ici.
- Départage par coût d'installation à bonus de matériaux égal en fabrication et en réaction (question Q6).
- Structure assignée pour la copie ou l'invention.
- Station NPC comme candidate en concurrence avec les structures du joueur (question Q3).
- Prise en compte du temps (bonus de temps) dans le choix.
- Interface : aucun écran ne change dans ce lot, l'avertissement est porté par `StructureChoice` comme aujourd'hui.

## Tests existants à adapter

Dans `tests/Unit/Industry/Application/StructureSelectorTest.php` (à lire par `test-writer`, lignes à la date de la spec) :

- `testFavoriteSystemFiltersBeforeTheBonus` (`:238`) fige le filtre strict : devient F1.
- `testReactionFiltersOnTheFavoriteReactionSystemNotTheManufacturingOne` (`:266`) : devient F7 (le favori de réaction n'a pas de rig adapté).
- `testEqualBonusPrefersTheFavoriteSystemOverAMoreRecentStructure` (`:381`) : même structure, statut d'avertissement (F5).
- `testEngineeringComplexActivitiesFilterOnTheFavoriteManufacturingSystem` (`:280`) : copie et invention, le favori ne filtre plus, il départage (C5).
- Inchangés : `testBestStructureOfTheFavoriteSystemIsChosen`, `testStructureWithUnknownSystemIsNotInTheFavoriteSystem`, `testFavoriteReactionSystemWithOnlyAnEngineeringComplexFallsBack…`, `testFavoriteManufacturingSystemWithOnlyARefineryFallsBack…`, les tests de structure assignée.

## Questions ouvertes

Décisions produit non couvertes par la décision du 2026-10-22.

- **Q1 — « Bonus pour l'activité et le produit ».** Options : (a) un rig qui cible la catégorie du produit ; (b) un multiplicateur de matériaux inférieur à 1, bonus de rôle compris. Recommandation : (a), car avec (b) toute Engineering Complex (×0,99) serait adaptée et le filtre souple ne changerait rien en fabrication.
- **Q2 — Produit sans catégorie de rig** (règle 8). Options : (a) pas d'avertissement, le favori départage ; (b) avertissement systématique puisque rien n'est adapté. Recommandation : (a), un avertissement qu'aucun réglage ne peut lever est du bruit.
- **Q3 — Station NPC en concurrence** pour la copie et l'invention. Options : (a) candidate au taux de 0,25 % sans bonus (elle gagnerait presque toujours face à une structure taxée) ; (b) réservée à l'absence de structure, comme aujourd'hui. Recommandation : (b), les structures déclarées sont les sites que le joueur dit utiliser.
- **Q4 — Taxe non saisie à 0 %** (#145) : elle avantage au critère une structure dont le propriétaire n'a rien saisi. Options : (a) garder la convention ; (b) traiter une taxe non saisie comme inconnue dans le classement. Recommandation : (a), convention déjà validée, le classement reste prévisible.
- **Q5 — Indice inconnu** (cas limites). Options : (a) candidates au taux connu d'abord, inconnues ensuite ; (b) dès qu'une candidate est inconnue, ne classer que sur la taxe. Recommandation : (a), jamais de 0 implicite et un coût connu quand une structure le permet.
- **Q6 — Départage à bonus de matériaux égal** en fabrication et en réaction. Options : (a) D3b inchangé (favori, puis la plus récente) ; (b) départager aussi par le taux d'installation, ce qui toucherait `FacilityTaxResolver` et la taxe du scanner et du Pivot. Recommandation : (a) dans #151, (b) en issue séparée avec ses exemples.

## Termes du glossaire

Ajoutés : **Filtre souple** (système favori), **Structure adaptée**, **Taux d'installation d'une structure**. Modifiés : **Système favori**, **Choix de structure**. Voir [glossaire](../glossary.md).
