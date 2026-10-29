# Spec #200 — API Industry : validation aux bornes (TE pair, identifiants non UUID, date de début des jobs, frais des réglages)

**Statut : validée en autonomie en mode nuit le 2026-10-29 (hors blocages listés), à relire par Sylvain.**

Circuit : `circuit:m` (pas de note de câblage). Tests unitaires seulement, pas de base : contraintes des DTO par `Validation::createValidatorBuilder()->enableAttributeMapping()` (précédent `tests/Unit/ApiResource/Input/GroupIndustry/UpdateGroupProjectInputValidationTest.php`) ; processors et providers avec `Security`, dépôts et `EntityManagerInterface` doublés, utilisateurs, projets et étapes en vraies entités ; test d'architecture par lecture des sources (précédent `tests/Unit/Architecture/AdminByCharacterIdTest.php`). Tests de composants front (Vitest, `en.json`/`fr.json`).

## Contexte

Bilan sur `fix/group-mining-ops` (33b6ff5b). Déjà fait, hors de ce lot : borne des runs du projet (1 à 100 000) et durée maximale de job (≥ 0,5 jour), eaa05b5 (`CreateProjectInput.php:11-12`, `:20`, `:29` ; `UpdateProjectInput.php:12`, `:21`).

**Codes HTTP du module.**

- Contraintes `Assert\…` des DTO d'entrée : validées par API Platform **avant** le processor, **422** au format RFC 7807 (`rfc_7807_compliant_errors: true`, `config/packages/api_platform.yaml:17`), `violations[]` avec `propertyPath` et `message`. Rien n'est écrit.
- 422 levé par le code : un seul précédent, hors Industry, `ApiPlatform\Validator\Exception\ValidationException` dans `GroupProjectFees::rejectEffectiveFeesAboveMaximum()` (`src/ApiResource/Input/GroupIndustry/GroupProjectFees.php:29-45`). Aucun dans `src/State/*/Industry`.
- Règle métier refusée : **400** `BadRequestHttpException` (`UpdateStepProcessor.php:84`, `:91` ; `ProfitMarginProvider.php:76` pour un decryptor inconnu). Ressource inconnue ou d'un autre : **404** `NotFoundHttpException` (« Project not found », « Step not found »…). Conflit : 409 (spec #205).
- Aucune règle `exception_to_status` : une `\InvalidArgumentException` non attrapée (celle de `Uuid::fromString()`, de `new \DateTimeImmutable()` ou des objets du domaine) donne **500**.

**TE pair.** Le domaine exige un TE pair de 0 à 20 (`src/Industry/Domain/TimeEfficiency.php:9-19`, message `TE must be an even level between %d and %d, got %d.`), comme le glossaire (`docs/glossary.md:78`, « 0 à 20, par pas de 2 » ; l'issue cite la ligne 55, le glossaire a bougé). L'API ne contrôle que la plage :

| Champ | Contrainte actuelle |
|---|---|
| `CreateProjectInput::$teLevel` | `Range(0, 20)` (`CreateProjectInput.php:26-27`) |
| `UpdateProjectInput::$teLevel` | `Range(0, 20)` (`UpdateProjectInput.php:18-19`) |
| `CreateStepInput::$teLevel` | `Range(0, 20)` (`CreateStepInput.php:20-21`) |
| `UpdateStepInput::$teLevel` | `Range(0, 20)` (`UpdateStepInput.php:26-27`) |
| `GET /api/industry/profit-margin/{typeId}?te=…&me=…` | aucune : `(int)` (`ProfitMarginProvider.php:49-50`), puis `new MaterialEfficiency` / `new TimeEfficiency` dans `ProfitMarginService.php:76` → 500 pour `te=3` ou `me=11` |

Les projets et étapes personnels ne passent pas par `TimeEfficiency` : la durée par run est calculée par `1 − TE/100` (`IndustryCalculationService.php:293`). Un TE impair enregistré est donc lu et calculé sans erreur.

**Identifiants d'URL.** 35 appels (22 dans 16 processors, 13 dans 11 providers) `Uuid::fromString($uriVariables[…])` dans les processors et providers Industry, sans garde : un identifiant qui n'est pas un UUID donne 500.

- Les quatre de l'issue : `LinkJobProcessor.php:49`, `:54` ; `MatchJobsProcessor.php:45` ; `SplitStepProcessor.php:57`, `:63` ; `UpdateProjectProcessor.php:44`.
- `UpdateUserSettingsProcessor` (cité par le brief) n'en a pas : sa route est `/industry/settings` (`UserSettingsResource.php:20`, `:25`).
- Les autres :
  - processors : `CreateStepProcessor.php:50`, `UpdateCharacterSkillProcessor.php:44`, `UnlinkJobProcessor.php:37`, `DeleteStructureProcessor.php:37`, `UpdateStructureProcessor.php:47`, `DeleteStepProcessor.php:39`, `:45`, `CreatePurchaseProcessor.php:54`, `:60`, `MergeStepsProcessor.php:47`, `:53`, `UpdateStepProcessor.php:56`, `:62`, `DeleteProjectProcessor.php:37`, `UpdateStockpileTargetProcessor.php:39`, `DeleteStockpileTargetProcessor.php:39` ;
  - providers : `ProjectProvider.php:39`, `ShoppingListProvider.php:50`, `BpcKitProvider.php:40`, `CostEstimationProvider.php:38`, `CopyCostsProvider.php:38`, `AvailableJobsProvider.php:44`, `PurchaseSuggestionProvider.php:47`, `StructureConfigProvider.php:37`, `JobMatchDeleteProvider.php:36`, `StepPurchaseDeleteProvider.php:43`, `:49`, `:55`, `StockpileTargetProvider.php:36`.
- PATCH et DELETE d'un projet, d'une étape ou d'une structure passent par un provider de référence qui ne lit pas l'identifiant (`ProjectReferenceProvider.php:16-21`, `ProjectStepReferenceProvider.php:39-44`, `StructureDeleteProvider.php:16-19`) : le 500 vient du processor. Les POST (`match-jobs`, `split`, `merge`, `link-job`, `steps`, `purchases`) n'ont pas de provider.
- Les cibles de stockpile ont déjà une exigence de route `{id<[0-9a-f]{8}-…>}` (`StockpileTargetResource.php:34`, `:45`, `:52`) : leur `Uuid::fromString` n'est jamais atteint avec un identifiant invalide (404 du routeur).
- Identifiants du corps : `CreateStepInput::$stepId` → `CreateStepProcessor.php:71` (500) ; `UpdateStepInput::$structureConfigId` → `UpdateStepProcessor.php:106` (500). `CreatePurchaseInput::$transactionId` est déjà gardé (`CreatePurchaseProcessor.php:103-115`, spec #205 R2 : 404). `CreateStepInput::$splitGroupId` est comparé en texte, jamais converti (`CreateStepProcessor.php:113`).
- Précédent de garde : `Uuid::isValid()` puis 404, même message qu'un identifiant inconnu (`ResolvesSaleOfProject.php:21-31`, `RecordSaleFromWalletTransactionProcessor.php:112`, `CreatePurchaseProcessor.php:105`).

**`jobsStartDate`.** `UpdateProjectInput::$jobsStartDate` est un `?string` sans contrainte (`UpdateProjectInput.php:44`) ; `UpdateProjectProcessor.php:88-90` fait `new \DateTimeImmutable($data->jobsStartDate)` :

- `"x"` lève une exception → 500 ;
- `""` donne l'instant courant ;
- `"2026-02-30T00:00:00+00:00"` est reporté au 2 mars ;
- `"tomorrow"` est accepté.

Le front envoie `AAAA-MM-JJ` + `T00:00:00+00:00` (`ProjectDetail.vue:126-131`) ; l'API rend la date en `format('c')` (`IndustryResourceMapper.php:170`), même format (ATOM).

**Réglages.** `UpdateUserSettingsInput` n'a aucune contrainte (`UpdateUserSettingsInput.php:9-17`).

- `UpdateUserSettingsProcessor.php:62-72` ramène à 0 un taux ou un coût d'export négatif (`max(0.0, …)`) **sans le dire**, et accepte tout taux au-dessus.
- Les taux sont des **fractions** (`0.036` = 3,6 %, glossaire `docs/glossary.md:140`) : le front divise la saisie en % par 100 (`FeesConfig.vue:33-34`). Un taux de 3,6 envoyé en pourcents par erreur est enregistré tel quel (360 %).
- Système favori (`:50-60`) : `> 0` enregistré sans contrôle (un identifiant absent du SDE est gardé, son nom rendu `null`, `:84-92`), `0` ou négatif l'efface. Le front n'envoie que des systèmes trouvés par la recherche, et `0` pour effacer (`FavoriteSystemsConfig.vue:77-100`).
- Précédent de borne des frais : Group Industry, `Range(0, 100)` en **pourcents** sur `brokerFeePercent` et `salesTaxPercent` (`CreateGroupProjectInput.php:57-61`, `UpdateGroupProjectInput.php:21-25`, `GroupProjectFees::MAXIMUM_PERCENT`), plus une somme ≤ 100 %. Les réglages de l'utilisateur alimentent ces frais à la création d'un projet de groupe (`CreateGroupProjectProcessor.php:71-74`).

**`items` d'apply-stock.** Caduc. La route `POST /industry/projects/{id}/apply-stock` a été retirée par c8c11944 (« remove the never called apply-stock and adapt-stock endpoints », 2026-10-22), `ApplyStockProcessor` et `ApplyStockInput` supprimés par 91d077b9. Aucun DTO Industry n'a plus de champ `items`.

**Front.**

- PATCH d'une étape : `stepsStore.updateStep()` range les messages des violations dans `projectsStore.error` (`steps.ts:84-86`, `formatErrorMessage`, `compat.ts:12-20`), affiché par l'`ErrorBanner` d'`Industry.vue:371`. Le 422 est donc **déjà visible** (texte anglais de l'API). `StepTree.onTeChange()` borne de 0 à 20 sans parité (`StepTree.vue:451-454`).
- PATCH d'un projet : `updateProject()` range `e.message` (pour une `ValidationError`, la `description` de l'API) dans le même bandeau et relance (`projects.ts:111-114`). Déjà visible.
- Profit Margins : `analyzeMargin()` range le message d'erreur dans le même bandeau (`projects.ts:239-241`). Un 400 sera visible.
- Réglages : `updateUserSettings()` range le message dans `structuresStore.error` (`structures.ts:139-141`), **jamais affiché** ; `FeesConfig.save()` avale l'exception (`FeesConfig.vue:39-41`). Un 422 n'affiche rien.
- Champs TE : `type="number" min="0" max="20"` sans `step` (`StepTree.vue:713-722`, `ProjectTable.vue:416-424`, `Industry.vue:703-710`, `ProfitMarginTab.vue:451-458`).

## Règles métier

- **R1 — TE pair à l'entrée.** Les quatre champs `teLevel` du tableau ci-dessus acceptent un entier **pair** de 0 à 20 (bornes incluses), règle de `TimeEfficiency` et du glossaire.
  - Un TE impair, négatif ou > 20 : **422** sur `teLevel`, rien n'est écrit, aucune régénération des étapes.
  - Message de parité : `TE must be an even level between 0 and 20.` La violation de plage garde le message de `Range`.
  - `null` (champ absent du PATCH) reste accepté.
- **R2 — ME et TE de Profit Margins.** `GET /api/industry/profit-margin/{typeId}` refuse un `me` hors de 0 à 10 ou un `te` hors de R1 par un **400**, dont le message est celui de l'objet du domaine (`ME must be between 0 and 10, got 11.`, `TE must be an even level between 0 and 20, got 5.`), comme le decryptor inconnu (`ProfitMarginProvider.php:75-77`). `ProfitMarginService::analyze()` n'est pas appelé. Paramètres absents : ME 10, TE 20 (inchangé).
- **R3 — Identifiant d'URL qui n'est pas un UUID.** Il reçoit **la même réponse qu'un identifiant inconnu** : 404, même message (« Project not found », « Step not found », « Structure not found », « Purchase not found », « Job match not found », « Character not found », « Stockpile target not found »). Le dépôt n'est pas lu pour cet identifiant. Cela vaut pour tous les appels listés au Contexte, GET, POST, PATCH et DELETE.
- **R4 — Ordre.**
  - 401 utilisateur, puis les identifiants dans l'ordre de l'URL. Projet invalide : 404 projet, l'étape n'est pas lue. Projet valide et à l'utilisateur, étape invalide : 404 étape.
  - Les contraintes du corps (R1, R5, R6, R7) passent **avant** le processor : une requête à la fois mal adressée et mal formée reçoit un 422.
- **R5 — Identifiants du corps.**
  - `CreateStepInput::$stepId` qui n'est pas un UUID : **404 `Step not found`**, comme un `stepId` inconnu (`CreateStepProcessor.php:73-75`) et comme `transactionId` (spec #205 R2). Le dépôt n'est pas lu.
  - `UpdateStepInput::$structureConfigId` qui n'est pas un UUID : **422** sur `structureConfigId` (`Assert\Uuid`). Un UUID inconnu garde son comportement actuel (voir « Écarts relevés »).
- **R6 — `jobsStartDate`.** Absent (`null`) : inchangé. Sinon : une date-heure au format ATOM (`Y-m-d\TH:i:sP`, celui qu'envoie le front et que rend l'API), date réelle du calendrier, non vide. Tout le reste : **422** sur `jobsStartDate`, la date enregistrée ne change pas. La valeur acceptée est enregistrée telle qu'aujourd'hui.
- **R7 — Frais et coût d'export des réglages.**
  - `brokerFeeRate` et `salesTaxRate` : fraction de **0 à 1** incluses (0 à 100 %, borne des frais de Group Industry, `GroupProjectFees::MAXIMUM_PERCENT`).
  - `exportCostPerM3` : ≥ 0.
  - Hors bornes : **422** sur le champ, **rien** n'est enregistré, pas même les autres champs de la requête (la validation précède le processor).
  - Le processor ne ramène plus rien à 0 : la valeur validée est enregistrée telle quelle.
  - Champ absent : inchangé.
- **R8 — Système favori.** Non spécifié dans ce lot (Blocage Q1) : comportement actuel inchangé.
- **R9 — Écran des frais.** Un échec de `FeesConfig.save()` s'affiche sous les champs (`data-testid="fees-save-error"`, `role="alert"`, rouge) ; « saved » n'apparaît pas.

  | Erreur | Clé |
  |---|---|
  | `ValidationError` (422) | `industry.config.feesErrors.invalid` |
  | toute autre exception | `industry.config.feesErrors.failed` |

  Le message s'efface au début de l'enregistrement suivant. Aucun texte de l'API n'est affiché. Les autres écrans gardent leur bandeau actuel.
- **R10 — Champs TE du front.** Les quatre champs TE portent `step="2"` (flèches de 2 en 2, saisie impaire signalée par le navigateur). Aucune autre logique front ne change : une saisie impaire envoyée reçoit le 422 de R1, affiché par le bandeau existant.

## Contrat de l'API

| Cas | Avant | Après |
|---|---|---|
| `teLevel` impair (projet, étape, création ou PATCH) | 200/201, TE impair enregistré | 422 `teLevel` |
| Profit Margins `te` impair ou hors 0-20, `me` hors 0-10 | 500 | 400, message du domaine |
| Identifiant d'URL non UUID (Industry) | 500 (stockpile : 404 du routeur) | 404, message de la ressource inconnue |
| `stepId` du corps non UUID (création d'étape) | 500 | 404 `Step not found` |
| `structureConfigId` non UUID (PATCH d'étape) | 500 | 422 `structureConfigId` |
| `jobsStartDate` illisible, vide, date impossible ou hors format ATOM | 500, ou date fausse enregistrée | 422 `jobsStartDate` |
| Taux de frais < 0 | 200, enregistré 0 | 422 |
| Taux de frais > 1 | 200, enregistré | 422 |
| `exportCostPerM3` < 0 | 200, enregistré 0 | 422 |

Forme des ressources inchangée. Pas de consommateur externe connu.

## Exemples chiffrés

Données fictives. Utilisateur U1 ; projet J1 de U1 (`0192f0c4-7b1e-7a3c-9d2e-200000000001`, TE 6) ; étape S1 de J1 (`0192f0c4-7b1e-7a3c-9d2e-200000000011`, TE 14) ; structure C1 de U1 (`0192f0c4-7b1e-7a3c-9d2e-200000000021`).

TE (contraintes des DTO, R1) :

| # | Requête | Aujourd'hui | Attendu |
|---|---|---|---|
| T1 | PATCH S1, `teLevel` 3 | 200, S1 en TE 3 | 422 `teLevel` (parité) ; S1 reste TE 14 |
| T2 | PATCH S1, `teLevel` 4 | 200 | inchangé : 200, TE 4 |
| T3 | PATCH S1, `teLevel` 0 | 200 | inchangé |
| T4 | PATCH S1, `teLevel` 20 | 200 | inchangé |
| T5 | PATCH S1, `teLevel` 19 | 200, TE 19 | 422 `teLevel` |
| T6 | PATCH S1, `teLevel` 22 | 422 (`Range`) | inchangé : 422, une seule violation (`Range`) |
| T7 | PATCH S1, `teLevel` -2 | 422 (`Range`) | inchangé : 422 |
| T8 | PATCH J1, `teLevel` 13 | 200, J1 en TE 13, étapes régénérées | 422 `teLevel` ; J1 reste TE 6 ; `regenerateSteps` non appelé |
| T9 | PATCH J1, `teLevel` 12 | 200 | inchangé : TE 12, étapes régénérées |
| T10 | POST projet, `teLevel` 7 | 201 | 422 `teLevel` |
| T11 | POST projet, `teLevel` 10 | 201 | inchangé |
| T12 | POST étape dans J1, `teLevel` 1 | 201 | 422 `teLevel` |
| T13 | PATCH S1 sans `teLevel` (`runs` 5) | 200 | inchangé : aucune violation de TE |

Profit Margins (`GET /api/industry/profit-margin/12058`, R2) :

| # | Requête | Aujourd'hui | Attendu |
|---|---|---|---|
| P1 | `?te=5` | 500 | 400 `TE must be an even level between 0 and 20, got 5.` ; `analyze` non appelé |
| P2 | `?te=8` | 200 | inchangé : `analyze(…, me 10, te 8, …)` |
| P3 | `?me=11` | 500 | 400 `ME must be between 0 and 10, got 11.` |
| P4 | `?te=24` | 500 | 400 `… got 24.` |
| P5 | `?me=-1` | 500 | 400 `… got -1.` |
| P6 | sans paramètre | 200 | inchangé : ME 10, TE 20 |

Identifiants (R3 à R5) :

| # | Requête | Aujourd'hui | Attendu |
|---|---|---|---|
| U1 | `POST /projects/abc/match-jobs` | 500 | 404 `Project not found` ; `IndustryProjectRepository::find` non appelé ; aucune sync |
| U2 | `POST /projects/J1/steps/zz-9/link-job` | 500 | 404 `Step not found` ; J1 lu ; `IndustryProjectStepRepository::find` non appelé |
| U3 | `POST /projects/abc/steps/def/split`, `numberOfJobs` 3 | 500 | 404 `Project not found` ; aucun dépôt d'étape lu |
| U4 | `POST /projects/J1/steps/S1/split`, `numberOfJobs` 1, URL `/projects/abc/…` | 422 (`Range` 2-20) | inchangé : 422 (R4, la validation passe d'abord) |
| U5 | `PATCH /projects/not-a-uuid`, `name` « A » | 500 | 404 `Project not found` |
| U6 | `DELETE /projects/42` | 500 | 404 `Project not found` ; ni `remove` ni `flush` |
| U7 | `DELETE /projects/J1/steps/S1/purchases/p-77` | 500 | 404 `Purchase not found` (`StepPurchaseDeleteProvider`) ; J1 et S1 lus |
| U8 | `DELETE /step-job-matches/m-1` | 500 | 404 `Job match not found` (`JobMatchDeleteProvider`) |
| U9 | `GET /projects/xyz` | 500 | 404 `Project not found` (`ProjectProvider`) |
| U10 | `PATCH /structures/rig-3`, `name` « B » | 500 | 404 `Structure not found` |
| U11 | `PATCH /character-skills/pilot-one`, `industry` 5 | 500 | 404 `Character not found` |
| U12 | `POST /projects/0192f0c4-7b1e-7a3c-9d2e-2000000000ff/match-jobs` (UUID valide inconnu) | 404 | inchangé : 404, dépôt lu une fois |
| U13 | `POST /projects/J1/steps`, `stepId` « s-1 » | 500 | 404 `Step not found` ; `IndustryProjectStepRepository::find` non appelé |
| U14 | `PATCH /projects/J1/steps/S1`, `structureConfigId` « rig-1 » | 500 | 422 `structureConfigId` |
| U15 | `PATCH /projects/J1/steps/S1`, `structureConfigId` = C1 | 200 | inchangé : S1 rattachée à C1 (UUID v7 accepté par `Assert\Uuid`) |

`jobsStartDate` (PATCH J1, R6) :

| # | Valeur | Aujourd'hui | Attendu |
|---|---|---|---|
| D1 | `"x"` | 500 | 422 `jobsStartDate` ; date de J1 inchangée |
| D2 | `""` | 200, date = instant de la requête | 422 `jobsStartDate` |
| D3 | `"2026-11-02T00:00:00+00:00"` | 200 | inchangé : 2026-11-02 00:00 +00:00, rendu `2026-11-02T00:00:00+00:00` |
| D4 | `"2026-02-30T00:00:00+00:00"` | 200, 2026-03-02 | 422 `jobsStartDate` |
| D5 | `"2026-11-03"` | 200, minuit du fuseau du serveur | 422 `jobsStartDate` (hors format ATOM) |
| D6 | `"tomorrow"` | 200, date relative | 422 `jobsStartDate` |
| D7 | absent | — | inchangé |

Réglages (`PATCH /api/industry/settings`, R7) ; réglages enregistrés avant chaque cas : courtage 0.025, taxe 0.045, export 1200 :

| # | Corps | Aujourd'hui | Attendu |
|---|---|---|---|
| R1 | `brokerFeeRate` -5 | 200, courtage 0.0 | 422 `brokerFeeRate` ; courtage reste 0.025 |
| R2 | `salesTaxRate` -0.0001 | 200, taxe 0.0 | 422 `salesTaxRate` |
| R3 | `brokerFeeRate` 0 | 200, 0.0 | inchangé : 200, 0.0 |
| R4 | `salesTaxRate` 0.036 | 200 | inchangé : 0.036 |
| R5 | `brokerFeeRate` 1 (100 %) | 200 | inchangé : 200, 1.0 (borne incluse) |
| R6 | `salesTaxRate` 1.0001 (100,01 %) | 200 | 422 `salesTaxRate` |
| R7 | `brokerFeeRate` 3.6 (pourcents par erreur) | 200, courtage 360 % | 422 `brokerFeeRate` |
| R8 | `salesTaxRate` 100 | 200 | 422 `salesTaxRate` |
| R9 | `exportCostPerM3` -1500 | 200, export 0.0 | 422 `exportCostPerM3` ; export reste 1200 |
| R10 | `exportCostPerM3` 0 | 200 | inchangé : 0.0 |
| R11 | `brokerFeeRate` 1.5, `salesTaxRate` 0.02 | 200, les deux enregistrés | 422 sur `brokerFeeRate` seul ; taxe reste 0.045 (rien n'est écrit) |
| R12 | `favoriteManufacturingSystemId` 30000142 seul | 200 | inchangé (R8) |

Écran (`en.json` sauf mention) :

| # | Données | Attendu |
|---|---|---|
| F1 | « Save » dans `FeesConfig`, `updateUserSettings` rejette une `ValidationError` (`brokerFeeRate`) | `fees-save-error` : « Each rate must be between 0 and 100 % and the export cost 0 or more. Nothing was saved. » ; pas de « saved » ; bouton réactivé |
| F2 | rejet `ApiError(…, 500)` | « Failed to save the fees. » |
| F3 | F1 en `fr.json` | « Chaque taux doit être compris entre 0 et 100 % et le coût d'export être positif ou nul. Rien n'a été enregistré. » |
| F4 | F1, puis « Save » réussi | message effacé ; « saved » affiché |
| F5 | `FeesConfig` monté, enregistrement réussi | aucun `fees-save-error` |
| F6 | `StepTree` (étape modifiable), `ProjectTable` en édition ME/TE, formulaire de création d'`Industry.vue`, `ProfitMarginTab` | chaque champ TE porte `step="2"`, `min="0"`, `max="20"` |

## Clés i18n

Nouveau bloc `industry.config.feesErrors` dans `fr.json` et `en.json` (à côté de `industry.config.feesTitle`, `en.json:938`, `fr.json:938`) :

| Clé | FR | EN |
|---|---|---|
| `invalid` | `Chaque taux doit être compris entre 0 et 100 % et le coût d'export être positif ou nul. Rien n'a été enregistré.` | `Each rate must be between 0 and 100 % and the export cost 0 or more. Nothing was saved.` |
| `failed` | `Échec de l'enregistrement des frais.` | `Failed to save the fees.` |

## Cas limites

- **Données historiques avec un TE impair** (projets ou étapes enregistrés avant ce lot). Pas de migration, pas de correction.
  - Ils restent lus, affichés et calculés comme aujourd'hui : la durée par run applique `1 − TE/100` sans passer par `TimeEfficiency` (`IndustryCalculationService.php:293`), donc aucune exception.
  - Un PATCH qui n'envoie pas `teLevel` (runs, nom, statut…) n'est pas refusé : la validation ne porte que sur les champs envoyés (T13).
  - La régénération des étapes d'un projet en TE impair (changement de runs, de ME, de durée max) reprend son TE impair pour les étapes de profondeur 0 (`IndustryProjectFactory.php:216`). L'utilisateur le corrige en envoyant un TE pair.
- **Données historiques hors bornes dans les réglages.**
  - Un taux négatif ou un coût d'export négatif n'a pas pu être enregistré par cette route (`max(0.0, …)`, `UpdateUserSettingsProcessor.php:62-72`).
  - Un taux > 1 a pu l'être (R7). Il reste lu et utilisé tel quel par les calculs (`CreateProjectProcessor.php:136-139`, `BuyVsBuildProvider.php:52-53`, `BatchScanProvider.php:59-60`, `PivotAdvisorProvider.php:51-52`, `ProfitMarginProvider.php:59-60`) ; aucun calcul ne le borne.
  - `FeesConfig` envoie toujours les trois champs (`FeesConfig.vue:32-36`) : l'enregistrement échoue (F1) tant que l'utilisateur ne corrige pas le taux affiché (par exemple 360 %). C'est voulu : le formulaire montre la valeur fautive.
  - Un PATCH d'un seul autre champ (système favori) passe.
  - La création d'un projet de groupe reste refusée en 422 si la somme dépasse 100 % (`GroupProjectFees`, inchangé).
- **`jobsStartDate` historique** : toute date enregistrée est une date valide (elle sort de `new \DateTimeImmutable`), y compris une date reportée (`02-30` → `03-02`). Rien à faire.
- **UUID en majuscules ou sans tirets** : la réponse suit `Uuid::isValid()` (format RFC 4122 par défaut). Le front n'envoie que les identifiants rendus par l'API (minuscules). Les cibles de stockpile gardent leur exigence de route en minuscules : un UUID en majuscules y reste un 404 du routeur.
- **404 du routeur** (stockpile) : corps d'erreur de Symfony, pas du provider ; le front ne lit que le statut.
- **`teLevel` d'une étape de réaction** : la formule n'a pas de TE (glossaire, « Réaction ») mais le champ est le même ; R1 s'applique (pair de 0 à 20). Inchangé sur le fond.

## Écarts relevés, recouvrements

- **#233 (point 3)** : « un identifiant de projet ou d'étape qui n'est pas un UUID → 404 » est couvert par R3. Les points 1 (`uriVariables` avec `Link`), 2 (identifiant sans `Get`) et 4 (forme unique de la réponse du PATCH d'étape) restent à #233. R3 ne dépend pas des `uriVariables` : un `Link` vers une ressource dont l'identifiant est un `string` ne convertit pas la valeur.
- **#206** : conventions API Platform du module, aucun conflit.
- **#205 R2** : `transactionId` non UUID → 404, gardé. R5 applique la même règle à `stepId`. `structureConfigId` reçoit un 422, parce qu'un UUID inconnu n'y est pas une erreur aujourd'hui (voir ci-dessous).
- **`structureConfigId` d'une étape** (`UpdateStepProcessor.php:105-111`) : un UUID valide inconnu **retire** la structure de l'étape (`find` → `null`), et la structure d'un **autre utilisateur** est acceptée (aucun contrôle de propriétaire, même trou que #285). Le front n'envoie jamais ce champ (`grep structureConfigId frontend/src` : seulement `types.ts:64`). À ouvrir en issue : 404 pour un UUID inconnu ou d'un autre, et un moyen explicite de retirer la structure.
- **Group Industry, TE impair** : `CreateGroupProjectInput` n'a que `Range(0, 20)` (`CreateGroupProjectInput.php:32`), et le test `testItemWithAnOddTimeEfficiencyInsideTheRangePassesTheInputValidation` (`CreateGroupProjectInputValidationTest.php:291-294`) fige ce comportement. `targetsOf()` construit alors `new TimeEfficiency(15)` (`GroupIndustryProjectService.php:295`), et `CreateGroupProjectProcessor.php:89-91` le change en **400**. Pas de 500, mais pas de 422 non plus : hors de ce lot (module Group Industry, specs #164 et #257), à aligner en issue.
- **`StockpileImportInput::$te`** (`StockpileImportInput.php:20-21`) n'est lu par personne : l'import planifie en TE fixe (`PlansStockpileImports.php:25`, `:45`). Pas de contrainte de parité ajoutée sur un champ ignoré ; champ mort à retirer en issue.

## Hors périmètre

- Système favori (Q1).
- Somme des taux et plafonds réalistes (Q2).
- `Uuid::fromString` sur des variables d'URL hors Industry : Escalation (`UpdateEscalationProcessor.php:49`, `DeleteEscalationProcessor.php:41`, `EscalationProvider.php:43`, `EscalationDeleteProvider.php:42`), Notification (`MarkReadProcessor.php:45`, `DeleteNotificationProcessor.php:30`, `NotificationProvider.php:42`, figés par des tests de caractérisation), Market (`DeleteAlertProcessor.php:40`, `MarketAlertDeleteProvider.php:42`), Planetary (`ColonyDetailProvider.php:42`), Assets (`RefreshCharacterAssetsProcessor.php:47`, `CharacterAssetsProvider.php:44`), Group Industry (`DeleteGroupProjectProcessor.php:27`, `GroupProjectProvider.php:41`…). À ouvrir en issue ; le helper de D3 s'y réutilise.
- `runs` de Profit Margins ramené à 1 sans erreur (`ProfitMarginProvider.php:48`).
- Montants libres de `UpdateProjectInput` (`bpoCost`, `materialCost`, `transportCost`, `taxAmount`, `sellPrice`), sans borne.
- Effacer `jobsStartDate` (impossible aujourd'hui, `null` = champ absent).
- Fuseau de `jobsStartDate` : la colonne `datetime_immutable` ne garde pas le décalage.
- `structureConfigId` inconnu ou d'un autre (voir « Écarts relevés »).

## Décisions retenues en autonomie

- **D1 — 404 pour un identifiant d'URL non UUID**, même message qu'un inconnu (critère de l'issue, précédents `ResolvesSaleOfProject` et spec #205 R2). Un 400 dirait au client « l'URL est mal formée » sans rien lui apprendre de plus.
- **D2 — Garde dans le code plutôt qu'exigence de route** (`{id<regex>}` comme `StockpileTargetResource`). La garde se teste en unitaire, couvre aussi `stepId` du corps et ne touche ni l'OpenAPI ni les `uriVariables` de #233. Les trois appels de stockpile passent aussi par la garde, sans effet visible, pour qu'un seul test d'architecture couvre tout le module.
- **D3 — Un seul helper** : `App\Service\UuidOrNotFound::of(string $identifier, string $notFoundMessage): Uuid`. Il rend `Uuid::fromString()` si `Uuid::isValid()`, sinon lève `NotFoundHttpException($notFoundMessage)`. Il vit dans `Service` parce que deptrac interdit `StateProcessor → StateProvider` et que les deux couches l'appellent. Précédent d'un `Service` qui lève une `NotFoundHttpException` : `ResolvesSaleOfProject`. `CreatePurchaseProcessor::findOwnedTransaction()` l'utilise aussi (même comportement, spec #205 E5 inchangée).
- **D4 — Parité du TE par une contrainte composée** `App\ApiResource\Input\Industry\TimeEfficiencyLevel` (`Assert\Compound` : `Range(min: 0, max: 20)` + `DivisibleBy(value: 2, message: 'TE must be an even level between 0 and 20.')`). Elle remplace `#[Assert\Range(min: 0, max: 20)]` sur les quatre champs. La règle est écrite une fois côté API ; le domaine garde la sienne (deptrac interdit `ApiResource → IndustryDomain`).
- **D5 — Profit Margins en 400 avec le message du domaine** : le provider construit `new MaterialEfficiency($me)` et `new TimeEfficiency($te)` dans un `try`, et change l'`\InvalidArgumentException` en `BadRequestHttpException`. La règle n'est pas recopiée, et c'est le statut du refus voisin (decryptor inconnu). Les paramètres de requête ne passent pas par le validateur dans ce provider.
- **D6 — `jobsStartDate` : `NotBlank(allowNull: true)` + `DateTime(format: \DateTimeInterface::ATOM)`.** Format strict, celui du front et de la réponse. `NotBlank` est nécessaire parce que `DateTime` laisse passer `""` (D2). Le processor garde `new \DateTimeImmutable(…)` sur une valeur validée.
- **D7 — Taux de 0 à 1 (`Range(min: 0, max: 1)`), coût d'export `PositiveOrZero`.** 100 % est la borne déjà décidée pour les frais de groupe (`GroupProjectFees::MAXIMUM_PERCENT`), et les réglages alimentent ces frais. Le `max(0.0, …)` disparaît : une donnée hors bornes est refusée, jamais corrigée en silence (règle du `CLAUDE.md`). Constante nommée `UpdateUserSettingsInput::MAXIMUM_RATE = 1.0`.
- **D8 — `structureConfigId` non UUID en 422** (et non 404) : un UUID inconnu n'est pas une erreur aujourd'hui (il retire la structure). Un 404 pour le seul cas non UUID serait incohérent tant que l'issue « structure inconnue ou d'un autre » n'est pas tranchée.
- **D9 — Front minimal.** Seul `FeesConfig` gagne un message, parce que c'est le seul écran où le 422 serait invisible. Steps, projets et Profit Margins ont déjà le bandeau. Messages traduits par type d'erreur, pas le texte de l'API (précédent spec #205 D7). Pas de maquette : une ligne d'alerte sous des champs existants. `step="2"` sur les champs TE : un attribut, aucun calcul.

## Blocage : options

- **Q1 — Système favori invalide (non spécifié, comportement actuel gardé).** Aujourd'hui un identifiant > 0 absent du SDE est enregistré (nom rendu `null`, le front affiche `#id`), `0` et les négatifs effacent le favori. Aucun code ni glossaire ne fixe la règle.
  - (a) 422 sur le champ quand l'identifiant est > 0 et absent de `MapSolarSystemRepository` ; 422 pour un négatif ; `0` efface (inchangé, c'est ce qu'envoie le front). **Recommandé** : même principe que R7, pas d'état « favori inconnu » dans les calculs. Coût : une lecture SDE par favori envoyé, dans le processor (le validateur n'a pas de dépôt) via `ValidationException` comme `GroupProjectFees`.
  - (b) (a) plus refus d'un système high-sec comme favori de réaction (les réactions y sont impossibles). Règle produit nouvelle, à confirmer par Sylvain.
  - (c) Garder le comportement actuel.
- **Q2 — Plafond des taux (n'empêche pas le lot, défaut retenu : D7).**
  - (a) 0 à 100 % chacun. **Retenu** : précédent du code.
  - (b) (a) plus somme ≤ 100 %, comme `GroupProjectFees`. Contrôle dans le processor avec la valeur enregistrée de l'autre taux quand un seul est envoyé (`UpdateGroupProjectProcessor.php:74-78`). Aujourd'hui la somme n'est contrôlée qu'à la création d'un projet de groupe.
  - (c) Plafonds réalistes (par exemple 10 % et 15 %, les `max` des champs de `FeesConfig.vue:59`, `:74`). Règle de jeu non sourcée : `[TO CONFIRM : plafonds réels du courtage et de la taxe en jeu]`.

## Fichiers de production à modifier

| Fichier | Changement |
|---|---|
| `src/Service/UuidOrNotFound.php` (nouveau) | helper de D3 |
| `src/ApiResource/Input/Industry/TimeEfficiencyLevel.php` (nouveau) | contrainte composée de D4 |
| `src/ApiResource/Input/Industry/CreateProjectInput.php`, `UpdateProjectInput.php`, `CreateStepInput.php`, `UpdateStepInput.php` | `#[TimeEfficiencyLevel]` sur `teLevel` à la place de `Range(0, 20)` |
| `src/ApiResource/Input/Industry/UpdateProjectInput.php` | `jobsStartDate` : `NotBlank(allowNull: true)`, `DateTime(format: \DateTimeInterface::ATOM)` |
| `src/ApiResource/Input/Industry/UpdateStepInput.php` | `structureConfigId` : `Assert\Uuid` |
| `src/ApiResource/Input/Industry/UpdateUserSettingsInput.php` | `MAXIMUM_RATE` ; `Range(min: 0, max: self::MAXIMUM_RATE)` sur les deux taux ; `PositiveOrZero` sur `exportCostPerM3` |
| `src/State/Processor/Industry/UpdateUserSettingsProcessor.php` | retrait des trois `max(0.0, …)` ; système favori inchangé |
| `src/State/Provider/Industry/ProfitMarginProvider.php` | `me` et `te` contrôlés par les objets du domaine, 400 (D5) |
| `src/State/Processor/Industry/CreateStepProcessor.php` | `UuidOrNotFound` pour `id` (`Project not found`) et pour `stepId` du corps (`Step not found`) |
| Processors Industry : `LinkJobProcessor`, `MatchJobsProcessor`, `SplitStepProcessor`, `MergeStepsProcessor`, `UpdateStepProcessor`, `DeleteStepProcessor`, `UpdateProjectProcessor`, `DeleteProjectProcessor`, `CreatePurchaseProcessor`, `UnlinkJobProcessor`, `UpdateStructureProcessor`, `DeleteStructureProcessor`, `UpdateCharacterSkillProcessor`, `UpdateStockpileTargetProcessor`, `DeleteStockpileTargetProcessor` | chaque `Uuid::fromString($uriVariables[…])` → `UuidOrNotFound::of(…, <message 404 existant>)` ; `CreatePurchaseProcessor::findOwnedTransaction()` aussi |
| Providers Industry : `ProjectProvider`, `ShoppingListProvider`, `BpcKitProvider`, `CostEstimationProvider`, `CopyCostsProvider`, `AvailableJobsProvider`, `PurchaseSuggestionProvider`, `StructureConfigProvider`, `JobMatchDeleteProvider`, `StepPurchaseDeleteProvider`, `StockpileTargetProvider` | idem |
| `frontend/src/components/industry/FeesConfig.vue` | `saveError` ; `catch` : clé par type d'erreur (`isValidationError`) ; effacé au début de `save()` ; alerte `fees-save-error` (R9) |
| `frontend/src/components/industry/StepTree.vue`, `ProjectTable.vue`, `frontend/src/views/Industry.vue`, `frontend/src/components/industry/ProfitMarginTab.vue` | `step="2"` sur le champ TE (R10) |
| `frontend/src/i18n/locales/fr.json`, `en.json` | bloc `industry.config.feesErrors` |

Pas de migration, pas de message Messenger, pas de commande à lancer en production. Les messages 404 existants ne changent pas.

## Câblage des tests (tests unitaires)

**Tests existants appelés à casser :** aucun attendu.

- Les tests de processors et providers Industry passent des UUID valides (`PROJECT_ID`, `Uuid::v4()`, `getId()->toRfc4122()`).
- `CreatePurchaseProcessorTest.php:189` (`not-a-uuid` → 404) reste vert avec le helper.
- Aucun test de `UpdateUserSettingsProcessor` ni de `FeesConfig`.
- `ProfitMarginProviderTest` n'envoie que des ME et TE valides ou absents.
- `CreateGroupProjectInputValidationTest.php:291-294` n'est pas touché (hors périmètre).
- Les tests de caractérisation de Notification (500 figé) restent tels quels (hors périmètre).

**Tests rouges attendus** (`#[Group('issue-200')]`) :

- `tests/Unit/ApiResource/Input/Industry/UpdateStepInputValidationTest.php` (nouveau) : T1 à T7, T13, U14, U15 (`validatePropertyValue` et `PropertyPathsOfViolations`, trait de `tests/Unit/ApiResource/Input/GroupIndustry/`). Pour T1, le message de parité exact.
- `CreateStepInputValidationTest.php` (nouveau) : T12 et un TE pair accepté.
- `CreateProjectInputValidationTest.php` : T10, T11.
- `UpdateProjectInputValidationTest.php` : T8 et T9 (contrainte seule), D1 à D7.
- `UpdateUserSettingsInputValidationTest.php` (nouveau) : R1 à R11 (chemins des violations).
- `tests/Unit/State/Processor/Industry/UpdateUserSettingsProcessorTest.php` (nouveau) : une valeur validée est enregistrée telle quelle (courtage 0.0 → 0.0 ; export 0 → 0.0), sans plancher.
  - Harnais : `Security` stub, `IndustryUserSettingsRepository` stub rendant de vrais `IndustryUserSettings`, `MapSolarSystemRepository` stub, `EntityManagerInterface` mock.
- `tests/Unit/State/Provider/Industry/ProfitMarginProviderTest.php` : P1 à P6 (`new Request(['te' => '5'])`, `ProfitMarginService` en mock, `expects(never())->method('analyze')` pour P1, P3 à P5).
- `tests/Unit/Service/UuidOrNotFoundTest.php` (nouveau) : UUID v7 valide rendu à l'identique ; `abc`, `""`, `42`, UUID tronqué → `NotFoundHttpException` portant le message reçu.
- Processors, dans les tests existants :
  - `MatchJobsProcessorTest` : U1, U12 ;
  - `LinkJobProcessorTest` : U2 ;
  - `SplitStepProcessorTest` : U3, et étape invalide sous J1 ;
  - `UpdateStepProcessorTest` : projet invalide ;
  - `MergeStepsProcessorTest`, `UnlinkJobProcessorTest`, `DeleteStockpileTargetProcessorTest` : un cas invalide chacun.
  - Dépôt en mock, `expects(never())->method('find')` pour l'identifiant invalide.
- Nouveaux tests de processors, un cas invalide chacun (mêmes harnais que `LinkJobProcessorTest`) :
  - `UpdateProjectProcessorTest` (U5) ;
  - `DeleteProjectProcessorTest` (U6, `remove`/`flush` jamais appelés) ;
  - `CreateStepProcessorTest` (U13) ;
  - `UpdateStructureProcessorTest` (U10) ;
  - `UpdateCharacterSkillProcessorTest` (U11).
- Providers, un cas chacun :
  - `StepPurchaseDeleteProviderTest` (U7, nouveau) ;
  - `JobMatchDeleteProviderTest` (U8, nouveau) ;
  - `ProjectProvider` (U9, dans un nouveau `ProjectProviderTest`, mapper stub) ;
  - `PurchaseSuggestionProviderTest` et `CostEstimationProviderTest` (existants), un cas invalide chacun.
- `tests/Unit/Architecture/IndustryUriIdentifiersTest.php` (nouveau) : aucun fichier de `src/State/Processor/Industry/` ni de `src/State/Provider/Industry/` ne contient `Uuid::fromString($uriVariables` (liste des fichiers fautifs = `[]`). Il couvre les appels sans test dédié.
- Front :
  - `frontend/tests/components/industry/feesConfigSaveError.spec.ts` (nouveau) : F1 à F5. Pinia réel, `useIndustryStore().updateUserSettings` remplacé par un `vi.fn` qui rejette une `ValidationError` (précédent `groupDistributionSaleCorrection.spec.ts:119`) ou une `ApiError` ; `fetchUserSettings` remplacé. `fr.json` pour F3.
  - `frontend/tests/components/industry/timeEfficiencyInputStep.spec.ts` (nouveau) : F6, montage de `StepTree` et `ProjectTable` (fixtures des specs existantes `projectTable*.spec.ts`), lecture de l'attribut `step` du champ nommé `industry.createProject.te`. `Industry.vue` et `ProfitMarginTab` : même lecture si leurs harnais existants le permettent, sinon test d'attribut sur le gabarit source.

## Glossaire (proposé, à valider)

- Modification de **TE** (`docs/glossary.md:78`) : ajouter « Un TE impair est refusé à l'entrée de l'API (422) ; un TE impair déjà enregistré reste lu (spec #200). »
- Modification de **Frais de courtage et Taxe de vente** (`docs/glossary.md:140`) : ajouter « Dans les réglages, chaque taux est une fraction de 0 à 1 (0 à 100 %), refusée hors de ces bornes (spec #200). »
