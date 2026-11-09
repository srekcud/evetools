# Relecture des specs Group Industry après la fusion de main (2026-10-28)

Worktree `fix/group-group-industry` (HEAD `dcba51d`, main fusionné, #141 livré : `cf72fc5` tests, `d8f1e7e` code).
Lecture seule du code. Les numéros de ligne ci-dessous sont ceux du code actuel.

Légende des questions : (a) tranchée par l'issue ou une décision écrite ; (b) détail technique, la recommandation suffit ; (c) décision produit ou structurelle non couverte, à bloquer.

## Synthèse

| Issue | Spec | Verdict | En une ligne |
|---|---|---|---|
| #162 | 162-group-access-control | **tient** (2 corrections mineures) | 3 copies privées `assertUserCanAccessProject` + copie dans `RecordSaleProcessor` toujours là |
| #170 | 170-group-roles-fees-character-id | **tient** | aucun contrôle du rôle de la cible ; `?? 3.6` ×3 ; `CHARACTER_ID_WITHOUT_MAIN_CHARACTER = 0` |
| #146 | 146-156-154-…-unknown-not-zero | **à corriger** | défaut présent, mais la liste des tests qui cassent est fausse (2 tests d'intégration assertent `totalBomValue` / `fulfillmentPercent`) |
| #156 | idem | **à corriger** | idem ; #141 ne pose toujours aucun prix sur les lignes de job |
| #154 | 154-group-contribution-price-source | **tient** | deux sources de prix, libellé « Weighted » codé en dur |
| #164 | 164-group-sale-input-validation | **à corriger** | colonne « Aujourd'hui » des items périmée (le noyau refuse déjà runs < 1, ME/TE hors bornes, TE impair, en 400) ; borne 100 000 runs dangereuse depuis #141 |
| #160 | 160-group-blueprint-missing-max-runs | **partielle** | ligne `sde_industry_blueprints` absente : erreur explicite livrée par #141 ; plus aucun repli 500/10 000 ; reste la limite ≤ 0 (message trompeur, sans test) |
| #158 | 158-group-container-nested-contents | **tient** | contenu direct seul, `unchecked`/0 dans 5 cas |
| #166 | 166-group-distribution-sales-queries | **tient** | `findApprovedByProject()` = `findBy` sans jointure ; pas de `findByProjectForListing` côté ventes |
| #168 | (sans spec) | **partielle** | `GroupProjectDeleteProviderTest` existe (6 tests) ; `UpdateGroupMemberProcessorTest` et `DeleteGroupProjectProcessorTest` absents |
| #242 | (sans spec) | **tient** | store `group-distribution` : état et `loading` partagés entre projets et entre ressources |
| #253 | (sans spec) | **tient** | `buildBom` ajoute une seconde BOM, figé par 2 tests `Currently…Issue253` |
| #257 | (sans spec, état seul) | état décrit § #257 | aucune contrainte par item ; garde-fous du noyau en 400 ; aucune borne haute des runs |

Blocages candidats (c) : #146 Q3 et Q4, #154 Q1, #170 Q2, #164 Q5 (à rouvrir), #158 Q1.

---

## #162 — contrôle d'accès

### Affirmations vérifiées

| Affirmation de la spec | Code actuel | Écart |
|---|---|---|
| `GroupProjectProvider::provide()` copie en ligne, sans court-circuit propriétaire | `GroupProjectProvider.php:47-61` | aucun |
| `GroupBomProvider::assertUserCanAccessProject()` | `GroupBomProvider.php:66-92` | aucun |
| `GroupDistributionProvider::assertUserCanAccessProject()` | `GroupDistributionProvider.php:61-83` | aucun |
| `GroupSaleCollectionProvider::assertUserCanAccessProject()` | `GroupSaleCollectionProvider.php:70-92` | aucun |
| Membres et contributions par `assertAcceptedMember()` | `GroupMemberCollectionProvider.php:58`, `GroupContributionCollectionProvider.php:54` | aucun |
| `RecordSaleProcessor::assertUserIsAdminOrOwner()`, message « …can record sales » | `RecordSaleProcessor.php:61`, `:107-123` | aucun |
| 5 appelants de `assertAdminOrOwner()` | `ContainerVerificationProvider:52`, `UpdateGroupProjectProcessor:61`, `UpdateGroupMemberProcessor:63`, `KickGroupMemberProcessor:59`, `ReviewContributionProcessor:65` | aucun |
| Tests cités (`GroupProjectProviderTest` 5 tests, `GroupBomProviderTest`, `RecordSaleProcessorTest` 2 tests) | présents | aucun ; pas de test unitaire pour Distribution/Sales provider (confirmé) |

Le checker est dans `src/State/Provider/GroupIndustry/GroupProjectAccessChecker.php` (la spec ne donne pas le chemin).

### Corrections à apporter

1. **R3** : « Le front n'affiche ni ne compare ce texte » est faux. `SalesTable.vue:220-221` affiche `store.error`, qui reçoit le `detail` de l'API (`services/api.ts:218`). Ce 403 reste inatteignable depuis l'écran, car le formulaire est réservé à `isOwnerOrAdmin`. Formulation exacte : « Le front affiche ce texte tel quel (`SalesTable.vue`, `store.error`), mais un non-admin n'atteint pas le formulaire ».
2. **Tests appelés à changer** : ajouter `tests/Integration/Service/Account/AccountDeletionServiceTest.php:820`, qui construit `GroupSaleCollectionProvider` avec `GroupIndustryProjectMemberRepository`. Il devra recevoir le checker.

### Questions

| Q | Recommandation tient ? | Classe |
|---|---|---|
| Q1 membres/contributions réservés aux `accepted` | oui (statu quo) | (b) |
| Q2 distribution nominative lisible par la corpo | oui (statu quo), incohérence à noter en issue séparée | (b) |
| Q3 403 plutôt que 404 | oui | (b) |

---

## #170 — rôles, frais, characterId

### Affirmations vérifiées

| Affirmation | Code actuel | Écart |
|---|---|---|
| Promotion réservée au propriétaire, rôle de la cible jamais lu | `UpdateGroupMemberProcessor::process()` `:81-83` (promotion), rien sur le rôle de la cible | aucun |
| Kick : refuse seulement propriétaire et soi-même | `KickGroupMemberProcessor::process()` `:67-75` | aucun |
| `?? 3.6` à la création | `CreateGroupProjectProcessor.php:73-74` | aucun |
| Littéral recopié | `CreateProjectData.php:26-27`, `GroupIndustryProject.php:58`, `:61` | aucun |
| `NewProjectModal.vue` n'envoie aucun frais | aucun `brokerFee`/`salesTax` dans le fichier | aucun |
| Modèle `CreateProjectProcessor::sellTaxRate()` | `src/State/Processor/Industry/CreateProjectProcessor.php:133-140` | aucun |
| `EveConstants::DEFAULT_BROKER_FEE_RATE` / `DEFAULT_SALES_TAX_RATE` = 0,036 | `src/Constant/EveConstants.php:12-13` | aucun |
| `CHARACTER_ID_WITHOUT_MAIN_CHARACTER = 0` | `GroupIndustryResourceMapper.php:18`, `:106` | aucun |
| Types déjà nullables | `GroupIndustryMemberResource.php:94`, `types.ts:99`, `useEveImages.ts:35-38` (repli si `null`) | aucun |
| `MemberTable.vue::canManage()` | `:67-69` (`props.isAdmin && role !== 'owner'`), boutons `:194-211` | aucun |
| Tests cités | `GroupIndustryResourceMapperTest:67`, `Integration\…\GroupMemberCollectionProviderTest:124`, `GroupProjectAccessCheckerTest:95`, `KickGroupMemberProcessorTest` (13 tests), `CreateGroupProjectProcessorTest` (8 tests) ; aucun `UpdateGroupMemberProcessorTest` | aucun |

Précision pour R5 : `MemberTable.vue` ne reçoit que `isAdmin`. Le masquage par propriétaire demande une prop de plus (ou `myRole`). C'est un détail d'implémentation.

### Questions

| Q | Recommandation tient ? | Classe |
|---|---|---|
| Q1 auto-rétrogradation d'un admin en 403 | oui ; aucun chemin d'écran | (b) |
| Q2 frais par défaut tirés des réglages Industry | tient, mais change les frais affichés des nouveaux projets | **(c)** |
| Q3 `characterId: null` au lieu de 0 | oui ; type déjà nullable, `null` déjà émis (#53) | (b) |
| Q4 corriger `MemberTable.vue` dans le lot | oui (conséquence de R1, aucun élément ajouté) | (b) |

Le lot peut se découper : rôles et characterId d'abord (sans blocage), frais après Q2.

---

## #146 + #156 — inconnu, jamais 0

### Affirmations vérifiées

| Affirmation | Code actuel | Écart |
|---|---|---|
| `marginRatio … : 0.0`, `sharePercent … : 0.0` | `GroupIndustryDistributionService.php:38-40`, `:99-101` | aucun |
| `DistributionResult`/`MemberDistribution` en `float` | `DistributionResult.php:18`, `MemberDistribution.php:38-40` | aucun |
| Lignes de job sans `estimatedPrice` | après #141 : `GroupIndustryProjectService::buildBom()` pose le prix `:122` (matériaux) et jamais sur les jobs `:126-141` | aucun, #141 ne change rien |
| `totalBomValue += (price ?? 0.0) × requis`, avancement en unités mélangées | `GroupIndustryResourceMapper::projectToResource()` `:59-72` | aucun |
| `JobsTable.vue` `totalEstCost` `?? 0` | `:55-56`, pied `:285` | aucun |
| `BomTable.vue` `totalEstCost`, `overallFulfillment` | `:29-35`, pied `:217-243` | aucun |
| `ProjectKpiCards.vue` | `:18-24` | aucun |
| `FinancialSummary.vue` `marginPercent ?? 0` | `:20` | aucun |
| `DistributionTable.vue` `marginPercent < 0` | `:289` | aucun |
| `testZeroCostsNoContributions` attend 0.0 | `GroupIndustryDistributionServiceTest.php:302`, `:314` | aucun |

### Corrections à apporter (verdict « à corriger »)

« Aucun test PHP n'asserte `totalBomValue` ni le `fulfillmentPercent` du projet » est faux, et ce l'était déjà avant la fusion (tests du 2026-10-07/08). À ajouter dans « Tests existants appelés à casser » :

| Test | Assertion actuelle | Après R3/R4 |
|---|---|---|
| `Integration\…\GroupProjectCollectionProviderTest::testSabreProjectCountsAcceptedMembersAndCapsFulfilledQuantityPerBomItem` (`:161-164`) | `fulfillmentPercent` 50.0 | 70.0 (moyenne de 40 % et 100 %) |
| idem `::testJackdawProjectFallsBackToUnknownOwnerAndIgnoresMissingPrices` (`:178-181`) | `totalBomValue` 15 000 000, `fulfillmentPercent` 25.0 | `null` ; 16.67 (moyenne de 33,33 % et 0 %). Le nom du test (« IgnoresMissingPrices ») contredit R3 et doit changer |
| idem `::testTenProjectsAreListedWithTheirTenMembersAndTenBomItems` (`:127-129`) | 50 000 / 25.0 | inchangé (toutes les lignes à 25 %) |
| idem `::testIshtarProjectOwnedByTheUserWithoutBom` (`:195-196`) | 0.0 / 0.0 | inchangé (cas limite « sans matériau ») |
| `Integration\…\AvailableGroupProjectsProviderTest::testPublishedProjectCountsAcceptedMembersAndCapsFulfilledQuantity` (`:162-164`) | 50.0 | 70.0 |

R7 ajoute une requête groupée aux providers de collection. `GroupProjectCollectionProviderTest::MAX_QUERIES = 4` (`:34`) est à revoir. Le test A/B d'`AvailableGroupProjectsProviderTest:93` reste valable.

### Questions

| Q | Recommandation tient ? | Classe |
|---|---|---|
| Q1 répartition impossible = inconnue | oui ; c'est la règle écrite (CLAUDE.md, glossaire « Coût inconnu ») | (a) |
| Q2 colonne coût des jobs gardée, total `—` | oui ; #141 ne pose toujours aucun prix de job | (b) |
| Q3 avancement = moyenne par ligne | tient, mais change le % affiché de chaque projet (50 → 70 sur Sabre) | **(c)** |
| Q4 KPI « Contributions totales » = Σ approuvées, nouveau champ API | tient, mais change le montant affiché et ajoute un champ | **(c)** |
| Q5 pipeline « N exclus » | oui ; c'est la règle de la spec #181 | (a) |

---

## #154 — source du prix d'une contribution

### Affirmations vérifiées

| Affirmation | Code actuel | Écart |
|---|---|---|
| `calculateMaterialValue()` renvoie `null` sans prix ; `resolveMaterialValue()` | `GroupIndustryContributionService.php:168-178`, `:180-188` | aucun |
| Valeur reçue > 0 prise telle quelle, sinon prix percentile courant | `:182-187` | aucun |
| Prix BOM figé à la création, seul appelant `createProject` | `GroupIndustryProjectService.php:86`, `:112-122` (inchangé par #141, R13) | aucun |
| Libellés « Jita Weighted Sell Price » / « Method: Jita weighted sell price » codés en dur | `ContributeModal.vue:298`, `:336` | aucun |
| `PositiveOrZero` sur `estimatedValue` | `SubmitContributionInput.php:22` | aucun |
| `submitAutoDetected()` sans appelant | aucun appelant dans `src/` | aucun |
| Tests cités | `GroupIndustryContributionServiceTest` : tous présents (`:67`, `:100`, `:361`, `:372`, `:382-472`, `:586`) ; vitest `contributeModalUnknownMaterialPrice.spec.ts`, `contributeModalJobInstallValue.spec.ts` présents | aucun |

### Questions

| Q | Recommandation tient ? | Classe |
|---|---|---|
| Q1 prix BOM figé plutôt que prix courant | tient ; choisir la source de valorisation des contributions est une décision produit (prix potentiellement vieux de semaines) | **(c)** |
| Q2 refus 400 d'une valeur envoyée sur un matériau à prix BOM | oui ; seul le SPA appelle l'API, et il n'enverra plus la valeur (R5) | (b) |
| Q3 pas de repli Jita courant | suit Q1 | (b) |

---

## #164 — saisies validées, vente corrigeable

### Écarts avec le code actuel

| Spec | Aujourd'hui réel | Correction exacte |
|---|---|---|
| Contexte : « runs ≤ 0, ME 11, TE 25 passent tels quels à `IndustryTreeService::buildProductionTree()` » | `GroupIndustryProjectService::targetsOf()` `:166-186` construit `Runs`, `MaterialEfficiency`, `TimeEfficiency`. Ils lèvent `\InvalidArgumentException` (`Runs.php:16-18`, `MaterialEfficiency.php:14-15`, `TimeEfficiency.php:17-18`), que le processor convertit en 400 (`CreateGroupProjectProcessor.php:77-79`) | « …sont refusés en 400 par les objets du noyau (`targetsOf`), avec un message qui ne nomme pas l'item, sauf pour un item de réaction, dont ME et TE sont ignorés (forcés à 0) mais enregistrés tels quels sur `GroupIndustryProjectItem` » |
| I1, I2 « accepté » | 400 « A job has at least 1 run, got 0. ». **Exception** : deux items du même produit au même ME/TE se somment (`targetsOf` `:173-175`). `runs: 5` + `runs: 0` passent, et l'item à 0 run est enregistré | I1/I2 : « 400 (noyau) » ; ajouter le cas de la somme |
| I5, I6, I7 « accepté » | 400 pour une fabrication ; accepté (ignoré) pour une réaction | idem |
| I11 `typeId: -3` « accepté » | 400 « No blueprint or reaction found for: … » (`resolveItemTypeIds()` `:131-144`) | « 400 (blueprint introuvable) » |
| I14 `Ishtar 12 25 x0` « accepté » | 400 sur la première violation seulement (ME 12) | « 400, une seule erreur » |
| I4 `runs: 100001` « accepté » | accepté, sans borne haute (limite connue de #141, Note de livraison) | inchangé, voir Q5 |
| I9 clé absente « 500 » | toujours 500 (`createProject()` `:77-81`, et `resolveItemTypeIds()` `:103` pour `typeId`) | aucun |
| Cas limites « TE impair (15) accepté, comme le solo » | **contredit par le noyau** : `TimeEfficiency` exige un niveau pair (`LEVEL_STEP`). TE 15 donne 400 pour une fabrication | soit `R1 teLevel : 0 à 20, pair` (422), soit acter le 400 du noyau ; ne plus écrire « accepté » |
| Cas limites « `GroupIndustryProjectService::flattenTree()` » | méthode supprimée par #141. ME/TE de l'item sur la ligne `final` ; composants ME 10 / TE 20 par `ProductionPlanner::MANUFACTURED_INTERMEDIATE_ME/_TE` ; réactions 0/0 (`targetsOf` `:170-172`) | remplacer la référence |
| Ventes (S1-S17), `RecordSaleInput` sans contrainte, pas de `Patch`/`Delete`, `SalesTable.vue` `typeId: 0` | inchangés (`RecordSaleInput.php`, `RecordSaleProcessor.php:63-73`, `GroupIndustrySaleResource.php:21-40`, `SalesTable.vue:27`, `:45`) | aucun |

### Questions

| Q | Recommandation tient ? | Classe |
|---|---|---|
| Q1 propriétaire ou admin corrige/supprime | oui (même règle que l'enregistrement) | (b) |
| Q2 refus sur projet terminé | oui, #152 R1 | (a) |
| Q3 aucune trace en base | oui (pas de migration) | (b) |
| Q4 `soldAt` en `DATE_ATOM` | oui ; le front n'envoie jamais `soldAt` | (b) |
| Q5 bornes 1-100 000 runs, sans pas pour TE | **ne tient plus** : (1) le TE impair est déjà refusé par le noyau ; (2) depuis #141, le nombre de lignes de BOM persistées vaut `ceil(runs / plafond)` par produit. Le plafond SDE vaut 1 pour Fenrir, Widow, Nomad et Marshal (golden), donc 10 items × 100 000 runs peuvent écrire 1 000 000 lignes. La borne de runs est à décider pour Group Industry, pas à copier du solo. Recouvre #257 | **(c)** |
| Q6 frontend Modifier/Supprimer avec maquette | oui ; maquette avant le vert frontend | (b) |

---

## #160 — limite de runs absente

### Ce que #141 a déjà corrigé (preuves)

- **Plus de découpage maison** : `splitJobsByMaxProductionLimit()` et `IndustryBlueprintRepository` ont disparu de `src/Service/GroupIndustry/` (recherche vide). Le plafond vient de `Recipe::$maxProductionLimit` par `ProductionPlanning::planOf()` (`ProductionPlanning.php:31-38`).
- **Ligne `sde_industry_blueprints` absente = erreur explicite** : `SdeBlueprintCatalog::recipeOf()` lève une `\UnexpectedValueException` qui nomme le blueprint (`SdeBlueprintCatalog.php:59-62`). Comme c'est une `RuntimeException`, le processor la convertit en 400 (`CreateGroupProjectProcessor.php:77-79`), et rien n'est persisté (levée dans `buildBom` avant `persist`, `GroupIndustryProjectService.php:86-89`). Tests : `GroupIndustryBomGoldenCharacterizationTest::testBlueprintWithoutMaxProductionLimitInTheSdeThrowsNamingItAndLeavesTheBomEmpty` (`:1148`), `Integration\Industry\Infrastructure\Sde\SdeBlueprintCatalogTest::testBlueprintWithoutMaxProductionLimitIsAnExplicitError` (`:214`).
- **Repli 500 / 10 000** : il n'en reste aucun dans `src/` (recherche `?? 500`, `10000`, `10_000`). Le solo lève aussi (`IndustryProjectFactory::maxProductionLimitOf()` `:667-680`). Le seul « défaut » restant est l'import, qui écrit 0 quand le SDE n'a pas de limite (`SdeBlueprintImporter.php:72`, `?? 0`).
- **Boucle infinie** : disparue avec l'ancien découpage.

### Ce qui reste

- **Limite ≤ 0, le cas réel en production** puisque l'import écrit 0. `recipeOf()` fait `new Runs($blueprint->getMaxProductionLimit())` (`:79`), ce qui lève `\InvalidArgumentException("A job has at least 1 run, got 0.")`. Le processor renvoie bien 400, mais le message ne nomme pas le blueprint et fait croire à une erreur de saisie des runs. Aucun test. L'effet touche tous les appelants du noyau (Profit Margins, Buy vs Build, coût), pas seulement Group Industry.
- R2 (lister tous les blueprints) n'est pas tenu : l'erreur part au premier recipe lu.

### Spec à réécrire

Elle se réduit à un mini-lot : dans `SdeBlueprintCatalog::recipeOf()`, une limite ≤ 0 lève la même `\UnexpectedValueException` nommant le blueprint ; un test d'intégration voisin de `:214` et un test golden voisin de `:1148`. À supprimer : R2 (liste groupée), R3 (exception dédiée), R4 (« lignes pleines puis le reste » contredit la répartition égale de #141 R7), R5, tout le tableau L1-L11 et la section « Tests appelés à changer ». Les tests qu'elle cite (`…SplitAt10000Runs`, `…SplitAt500RunsIssue2`, `testBuildBomUsesDefaultLimitWhenBlueprintNotInSde`) n'existent plus. `…IsSplitButNotItsCopy` est devenu `testJobAboveTheMaxProductionLimitOfItsBlueprintIsSplitEvenlyButNotItsCopy` (`:1078`). Le glossaire (« Plafond de runs d'un BPC », `glossary.md:71`) ne doit plus citer `splitJobsByMaxProductionLimit()`.

### Questions

| Q | Recommandation tient ? | Classe |
|---|---|---|
| Q1 erreur plutôt qu'état inconnu | tranchée par #141 R7/R10 (livré) | (a) |
| Q2 limite ≤ 0 = absente | oui, c'est le reste à faire | (b) |
| Q3 périmètre du plafonnement | tranchée par #141 Q2 (fabrication et réaction plafonnées, répartition égale) | (a) |
| Q4 une erreur listant tous les blueprints | ne tient plus (lecture paresseuse par recette) ; à abandonner, la première erreur suffit comme dans le solo | (b) |

---

## #158 — contenu imbriqué du container

| Affirmation | Code actuel | Écart |
|---|---|---|
| Contenu direct seul (`locationId` = container) | `GroupIndustryContainerService::getContainerQuantities()` `:57-85` | aucun |
| `buildAllUnchecked()` si pas de nom ou pas de corpo | `:39-42`, `:91-99` | aucun |
| `determineStatus()` rend `unchecked` pour 0 | `:119-130` | aucun |
| Finder : divisions visibles puis `findContentsOfContainers()` 5 niveaux | `VisibleCorporationAssetsFinder::findVisibleAssets()` `:22-41`, `CachedAssetRepository.php:19`, `:142-146` | aucun |
| `containerQuantity` en `int`, status `'verified' \| 'partial' \| 'unchecked'` | `GroupContainerVerificationResource.php:46`, `:49` ; `types.ts:16`, `:157` | aucun |
| `BomTable.vue` n'affiche que verified/partial ; bandeau seulement si nom de container | `BomTable.vue:140`, `:147` ; `GroupIndustryDetail.vue:418` | aucun |
| Tests cités | tous présents | **à compléter** : `GroupIndustryContainerServiceTest::testWithoutContainerNameEveryMaterialIsUncheckedInBomOrder` (`:75`) casse aussi (E15) |

| Q | Recommandation tient ? | Classe |
|---|---|---|
| Q1 libellés et affichage (bandeau + états de ligne) | tient ; nouveaux états visibles et maquette à produire | **(c)** |
| Q2 un seul `unknown` avec motif | oui | (b) |
| Q3 projet sans nom → liste vide | oui | (b) |
| Q4 container en division non visible → introuvable | oui (whitelist étanche) | (b) |
| Q5 coût de lecture hors périmètre | oui | (b) |

---

## #166 — requêtes distribution et ventes

| Affirmation | Code actuel | Écart |
|---|---|---|
| `findApprovedByProject()` = `findBy` sans jointure | `GroupIndustryContributionRepository.php:29-35` | aucun |
| Modèle `findByProjectForListing()` (jointure du token) | `GroupIndustryContributionRepository.php:82-94` | aucun |
| Aucune méthode de listing dans `GroupIndustrySaleRepository` | constructeur seul (`:16`) | aucun |
| `PilotName::of()` par membre et par enregistreur | `GroupIndustryDistributionService.php:106`, `GroupSaleCollectionProvider::mapToResource()` | aucun |
| Outil `tests/Integration/RecordsSqlQueries.php`, `MAX_QUERIES`, `GROUP_INDUSTRY_TABLES_PATTERN` | présents (`GroupContributionCollectionProviderTest.php:38`, `:41`, `:120`, `:295`) | aucun |
| `AccountDeletionServiceTest::testDistributionIsUnchangedAndTheDeletedAccountHasNoCharacterName` | `:299` | aucun |

Hors périmètre « duplication du contrôle d'accès » : c'est exactement #162. Si #162 passe avant, les deux nouveaux tests construisent les providers avec le checker.

| Q | Recommandation tient ? | Classe |
|---|---|---|
| Q1 corriger aussi les ventes | oui | (b) |
| Q2 jeu E1 seul | oui | (b) |
| Q3 borne absolue | oui | (b) |

---

## Issues sans spec

### #168 — tests manquants : partielle

| Classe | Test | État |
|---|---|---|
| `GroupProjectDeleteProvider` | `tests/Unit/State/Provider/GroupIndustry/GroupProjectDeleteProviderTest.php` (6 tests, `cd4a790`, 2026-10-23) | **existe** : propriétaire, admin non propriétaire, statuts et contributions/ventes. Manquent 401 et 404 (`GroupProjectDeleteProvider.php:35`, `:41`) |
| `UpdateGroupMemberProcessor` | aucun | **absent** ; la spec #170 le crée (R-E1, R-E3, R-E4, R-E6, R-E8, R-E10 à R-E12) |
| `DeleteGroupProjectProcessor` | aucun | **absent** ; le processor est trivial (`process()` : `find` + `remove` + `flush` si non null) |

### #242 — store des ventes : tient

`frontend/src/stores/group-industry/distribution.ts` (inchangé depuis V0.13) :
- `distribution`, `sales`, `members` sont des `ref` uniques, non indexés par `projectId` (`:13-15`), sans remise à zéro au changement de projet ;
- un seul `loading` (`:17`) est basculé par `fetchDistribution`, `fetchSales` et `fetchMembers` (`:21`, `:35`, `:67`). Il est lu par `SalesTable.vue:82` et `MemberTable.vue:136`, donc le premier fetch terminé éteint le spinner des autres ;
- un seul `error` partagé (`:18`), affiché par `SalesTable.vue:220-221`.

### #253 — seconde BOM à la reconstruction : tient

`GroupIndustryProjectService::buildBom()` (`:94-142`) est publique, n'a qu'un appelant (`createProject`, `:86`) et ajoute des lignes par `addBomItem` sans vider la BOM existante. Le comportement est figé par `GroupIndustryBomPersistenceTest::testRebuildingThePersistedBomCurrentlyAppendsASecondBomAndLeavesTheContributionsUntouchedIssue253` (`:156`) et `GroupIndustryBomGoldenCharacterizationTest:1299` (`#[Group('issue-253')]`). La spec #141 cite `:126` dans ses « Cas limites » (périmé) mais `:156` dans sa Note de livraison (juste).

### #257 — bornes de `CreateGroupProjectInput` : état actuel seul

- `src/ApiResource/Input/GroupIndustry/CreateGroupProjectInput.php` : `name` `Length(max: 255)` ; `items` `NotBlank` + `Count(min: 1, max: 10)`, sans aucune contrainte par item ; `blacklistGroupIds`, `blacklistTypeIds`, `blacklistCategoryKeys` et `lineRentalRatesOverride` sans contrainte ; `containerName` `Length(max: 255)` ; `brokerFeePercent` et `salesTaxPercent` en `PositiveOrZero`, sans borne haute.
- Comportement aux bornes (via le noyau, voir #164) : runs < 1 → 400 (sauf somme par produit/ME/TE) ; ME hors 0-10 ou TE hors 0-20 ou impair → 400 pour une fabrication, ignorés pour une réaction ; runs sans borne haute, d'où `ceil(runs / plafond)` lignes de job par produit (plafond 1 pour les capitaux) ; clé absente → 500.
- Recouvrement : c'est le périmètre de #164 R1. Il faut soit confier R1 à #257, soit fermer #257 avec #164.

---

## Chevauchements et ordre conseillé

### Fichiers touchés par plusieurs specs

| Fichier | Specs |
|---|---|
| `GroupProjectAccessChecker` | #162 (`assertCanRead`), #170 (`assertCanManageMember`) |
| `RecordSaleProcessor` (+ test) | #162 (checker), #164 (retrait des 400, nouveaux processors PATCH/DELETE sur le même contrôle) |
| `GroupSaleCollectionProvider` + `AccountDeletionServiceTest:820` | #162 (constructeur), #166 (repository) |
| `GroupDistributionProvider` | #162, #146 (`mapToResource` nullable), #166 (nouveau test d'intégration) |
| `GroupIndustryContributionRepository` | #146 R7 (somme par projets), #166 (`findApprovedByProject` en fetch join) |
| `GroupIndustryDistributionService` / `DistributionResult` | #146 (null) ; #164 R8 et #166 sans changement de calcul |
| `GroupIndustryResourceMapper` | #146 (`projectToResource`), #170 (`memberToResource`) |
| `CreateGroupProjectInput` | #164 R1, #257 |
| `frontend/.../types.ts` | #146, #158, #164 |
| `BomTable.vue` | #146 (pied), #158 (états de ligne) |
| `stores/group-industry/distribution.ts` | #164 R9, #242 |
| `MemberTable.vue` | #170 |
| `ContributeModal.vue` / `GroupIndustryContributionService` | #154 seul |
| Glossaire | « Prix BOM » (#154) utilisé par #146 R3 ; « Admin » (#170) et « Accès en lecture » (#162) dans la même section |

### Contradictions constatées

- #164 (TE impair accepté) contredit `TimeEfficiency` du noyau, livré avec #141.
- #160 R4 (« lignes pleines puis le reste ») contredit #141 R7 (répartition égale), livré.
- Aucune contradiction entre les 8 specs elles-mêmes. #166 renvoie la déduplication d'accès à « un autre lot », qui est #162.
- Hors des 8 : `docs/specs/69-group-bom-stockpile-demand-aggregation.md` (validée le 2026-10-28, commit `2e85894` une minute avant `d8f1e7e`) prévoit un lot « BOM de groupe » G1-G7 qui rebranche `buildBom` sur le noyau, ce que #141 vient de livrer. Ce lot est vraisemblablement caduc et doit être revu avant tout `/tdd-red` sur sa partie groupe.

### Ordre conseillé

1. **#162** : socle du checker, sans blocage.
2. **#170** rôles + characterId (Q1, Q3, Q4 en (b)) ; la partie frais attend Q2. Crée `UpdateGroupMemberProcessorTest` (couvre une partie de #168).
3. **#166** : après #162, pour construire les nouveaux tests avec le checker.
4. **#160** réduite (limite ≤ 0 dans `SdeBlueprintCatalog`) : indépendante, à tout moment.
5. **#242** puis **#164** (+ #257) : le store d'abord, la vente corrigeable ensuite ; #164 attend la nouvelle décision Q5.
6. **#154** puis **#146/#156** : même sujet de valeurs, glossaire « Prix BOM » d'abord ; attendent #154 Q1 et #146 Q3/Q4.
7. **#158** : backend indépendant ; son frontend touche `BomTable.vue` après #146 R5, et attend la maquette (Q1).
