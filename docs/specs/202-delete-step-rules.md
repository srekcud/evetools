# Spec #202 — Suppression d'une étape : règles appliquées par l'API, groupe dissous, enfants recalculés

**Statut : rédigée le 2026-10-31 en mode nuit (circuit m), à valider par Sylvain.** Aucune question n'a pu être posée : les choix sont listés dans « Décisions prises en autonomie » ; deux points qui changent un montant visible ou suppriment des données sans être couverts par l'issue sont dans « Questions ouvertes », avec une option recommandée. Le reste de la spec suit l'option recommandée de Q2 et le comportement actuel pour Q1.

Circuit : `circuit:m` (pas de note de câblage). Harnais disponibles : tests unitaires des processors sur le SDE golden (trait `BuildsStepProcessorsOnGoldenSde`, `IndustryStepCalculator` réel, précédent `CreateStepProcessorAddedJobTest` et ses projets FC4 et FC10), tests du mapper (`IndustryResourceMapperTest`), Vitest pour le store (précédent `frontend/tests/stores/stepsAddChildJob.spec.ts`, `apiRequest` doublé) et pour `StepTree.vue` (précédent `stepTreeReactionMeLevel.spec.ts`).

## Contexte

Issue #202 (audit du moteur industrie du 2026-10-22) : les règles de suppression d'une étape ne sont appliquées que dans l'interface (bouton masqué pour une étape liée à un job ESI) ; l'API supprime n'importe quelle étape du projet ; la suppression ne recalcule pas les étapes enfants ; un groupe découpé réduit à un job subsiste comme groupe. Test attendu : DELETE d'une étape avec un job ESI rattaché → refus ; suppression d'un job dans un groupe de deux → le job restant n'est plus découpé et les besoins des enfants sont recalculés.

État vérifié sur `fix/group-production-tree` 44634578 (contient `origin/main` 05d85b46, #131, #200 et #191/#190/#287) :

| Site | Aujourd'hui | Appelants |
|---|---|---|
| `DeleteStepProcessor::process()` (l. 31-54) | 401 sans utilisateur ; projet par `UuidOrNotFound::of()` (#200), 404 `Project not found` si inconnu ou à un autre utilisateur ; étape par `UuidOrNotFound::of()`, 404 `Step not found` si inconnue ou d'un autre projet ; puis `removeElement` + `remove` + `flush` (l. 51-53) : aucun refus, aucun recalcul, aucune dissolution, aucune reprise des données | `DELETE /industry/projects/{id}/steps/{stepId}` (`ProjectStepResource`, l. 45-50, provider `ProjectStepReferenceProvider`), réponse 204 |
| `StepTree.vue` bouton supprimer (l. 896-909) | affiché si `!isLinkedToEsi(step) && !readonly`, placeholder `w-7` sinon ; bouton présent sur chaque job de chaque carte, racine (profondeur 0) et copie comprises | `deleteStep()` → modale → `emit('delete-step')` → `StepsTab.vue::deleteStepHandler()` |
| `StepTree.vue::isLinkedToEsi()` (l. 316-318) | `step.esiJobId !== null` ; `esiJobId` vient de `compat.ts::enrichStep()` (l. 31-54) : premier élément de `jobMatches`, quel que soit son statut. Aussi utilisé pour la saisie des runs (l. 648), le calcul automatique (l. 694), ME/TE (l. 703), la découpe (l. 854) | — |
| `stores/industry/steps.ts::deleteStep()` (l. 163-173) | DELETE puis retire l'étape du store par filtre ; aucune autre étape mise à jour ; erreur : `projectsStore.error = e.message` (message du serveur via `ApiError`) | `StepsTab.vue` ; `stores/industry.ts` (l. 126) |
| `IndustryStepCalculator::recalculateStepQuantities()` (l. 28-48) | cascade : écrit le besoin d'un produit qui a au moins un consommateur (l. 79), supprime les jobs en trop (#131, `IndustryProject::removeStep()`), `flush` si quelque chose change ; un produit sans consommateur (racine, étape orpheline) n'est pas écrit | `UpdateStepProcessor`, `CreateStepProcessor`, `IndustryJobMatcher` ; **pas** `DeleteStepProcessor` |
| `SplitGroupQuantities::applyNeedToSteps()` (l. 53-105) | un seul job : règle d'une étape seule (l. 57-58), **sans dissolution** : `applyNeedToSingleStep()` garde `splitGroupId` et écrit `totalGroupRuns` = runs (l. 111) ; la dissolution (l. 72-77) n'a lieu que quand des jobs en trop sont supprimés | cascade, `SplitGroupMaterialNeeds`, factory |
| `IndustryProjectStep::isCommitted()` (l. 366-369) | job matché, achat, stock > 0 ou marque « acheté » | `SplitGroupQuantities::keptStepsNotFixed()` (#131 R3) |
| `IndustryProjectStep::absorb()` (l. 371-385) | reprend jobs matchés et achats, additionne le stock, « acheté » si l'un des deux l'est | `SplitGroupQuantities::absorbSurplusJobs()`, `IndustryProjectFactory::applyPlanToExistingSteps()` |
| `IndustryStepPurchase::$step` | `onDelete: 'CASCADE'`, collection `cascade: remove`, `orphanRemoval` : supprimer une étape supprime ses achats ; ses jobs matchés aussi | — |
| `ProductionStepNeedsResolver::resolve()` (l. 62-96) | un produit sans consommateur est traité comme une racine (`$isRoot`, l. 65) : ses runs enregistrés comptent dans la liste d'achats et l'arbre ; un matériau sans étape est acheté | liste d'achats, arbre affiché (#121) |
| `IndustryProjectFactory::applyPlanToExistingSteps()` (l. 312-342) | une étape du plan sans étape existante est recréée (l. 318) | régénération (runs, ME, TE, durée maximale du projet, commandes `regenerate-*`) |
| `IndustryResourceMapper::stepToResource()` (l. 216) | aucune information sur la possibilité de supprimer l'étape | toutes les réponses qui portent une étape (`ProjectProvider` l. 47-50, PATCH, POST, découpe, fusion, liaison de job) |
| Refus voisins | 400 `BadRequestHttpException` au style `Cannot …` : `Cannot mark root products as purchased` (`UpdateStepProcessor` l. 85), `Cannot set a material efficiency on a reaction step` (l. 93), `Step is already split. Merge first before re-splitting.` (`SplitStepProcessor` l. 79) ; 409 seulement pour un doublon d'import (`CreatePurchaseProcessor`, `CreateStructureProcessor`) | — |

Aucun test ne couvre `DeleteStepProcessor` ni `steps.ts::deleteStep()` ni le bouton supprimer (`grep` sur `tests/` et `frontend/tests/`).

### Points caducs

| Point | Preuve |
|---|---|
| Références de l'issue | déplacées : `DeleteStepProcessor.php:44-54` → l. 45-53 (#200) ; `StepTree.vue:850` → l. 896-909 ; `docs/glossary.md:34` → l. 54 (groupe découpé, qui mentionne depuis #131 la dissolution d'un groupe réduit à un job, au recalcul seulement) |
| Identifiants d'URI non UUID (hors issue) | réglé par #200 : 404 sans lecture, `IndustryUriIdentifiersTest` |
| Mécanismes manquants | n'existent plus comme manque : dissolution (#131 R6), absorption (`absorb()`), cascade avec jobs en trop (#131). Seul `DeleteStepProcessor` ne s'en sert pas |

Restent vrais : les trois constats de l'issue (aucun refus côté API, aucun recalcul, aucune dissolution).

## Règles métier

Notations : **étape supprimée** = l'étape désignée par `{stepId}` ; **groupe de l'étape** = les étapes du projet de même `splitGroupId` (l'étape seule si `splitGroupId` est null) ; **autres jobs** = groupe de l'étape sans elle ; **étape de production** = étape d'activité autre que `copy` ; **produit du projet** = `IndustryProject::getProductTypeId()`.

### Refus

- **R1 — Étape liée à un job ESI.** Une étape qui porte au moins un **job matché** (`IndustryProjectStep::getJobMatches()` non vide ; `jobMatches` dans la ressource), quel que soit le statut du job ESI (`active`, `ready`, `delivered`…) et le mode de matching, n'est pas supprimable : **400** `Cannot delete a step linked to an ESI job`. C'est la règle que l'interface applique aujourd'hui (`esiJobId` = premier élément de `jobMatches`). Le chemin pour la supprimer : retirer le lien (`DELETE /industry/step-job-matches/{id}`, existant) puis supprimer.
- **R2 — Dernière étape du produit du projet.** Une étape de production dont le produit est le produit du projet, quand aucune autre étape de production du projet n'a ce produit, n'est pas supprimable : **400** `Cannot delete the last step of the project product`. Un job d'une étape racine découpée reste supprimable tant qu'un autre job de ce produit existe ; un produit ajouté depuis un type (profondeur 0, autre produit) reste supprimable en entier.
- **R3 — Données sans repreneur.** Une étape **engagée** (`isCommitted()` : achat, stock > 0 ou marque « acheté », le job matché relevant de R1) qui n'a **aucun autre job** dans son groupe n'est pas supprimable : **400** `Cannot delete a step holding purchases, stock or a purchased mark`. Le chemin : retirer ses achats (`DELETE …/purchases/{purchaseId}`), remettre le stock à 0, décocher « acheté ». Une étape engagée qui a d'autres jobs passe ses données à l'un d'eux (R6).
- **R4 — Ordre des refus.** 401, 404 projet, 404 étape (inchangés, `UuidOrNotFound`, propriétaire du projet, projet de l'étape), puis R1, R2, R3, puis les refus de la cascade (R11).
- **R5 — Rien d'écrit sur un refus.** R1 à R3 sont vérifiés avant toute modification, même en mémoire. Un refus de la cascade (R11) a lieu après des modifications en mémoire mais avant tout `flush`. Une suppression acceptée est enregistrée avec les changements de la cascade.

### Suppression acceptée

- **R6 — Reprise des données.** Si l'étape a d'autres jobs, l'autre job de plus grand `splitIndex` l'absorbe (`IndustryProjectStep::absorb()` : achats, stock additionné, « acheté » si l'un des deux l'est), comme #131 R4. Aucun job matché à déplacer (R1). Le stock total, les achats et la marque « acheté » du produit sont inchangés.
- **R7 — Retrait.** L'étape est retirée du projet (`IndustryProject::removeStep()`, `orphanRemoval`).
- **R8 — Groupe restant d'au moins deux jobs.** Les jobs restants sont renumérotés de 0 à N − 1 dans l'ordre de leur `splitIndex` d'avant la suppression (#131 R5). `totalGroupRuns` = somme des runs des jobs restants, écrite sur chacun (même règle que l'ajout d'un job, A5 de #191) ; la cascade la réécrit ensuite pour un groupe qui reçoit un besoin (#252 : runs du besoin sans job fixé, somme des runs avec un job fixé).
- **R9 — Dissolution.** Un groupe réduit à un job par la suppression est dissous (#131 R6) : `splitGroupId` null, `splitIndex` 0, `totalGroupRuns` null. Identifiant, runs, `runsFixed`, quantité, jobs matchés, achats, stock, « acheté », ME, TE, structure et mode de matching du job gardé sont conservés. Il suit ensuite la règle d'une étape seule dans la cascade (non fixé : runs et quantité du besoin ; fixé : ses runs, quantité = runs × unités par run, G1 de #185).
- **R10 — Cascade.** Après R6 à R9, `IndustryStepCalculator::recalculateStepQuantities($project)`, avec toutes les règles en vigueur (#16, #73, #185, #252, #131). Conséquences :
  - (a) l'étape supprimée ne demande plus rien à ses matériaux ;
  - (b) les jobs **non fixés** restants d'un produit qui reçoit un besoin se partagent ce besoin : les runs du job supprimé passent aux autres, la production ne change qu'à l'arrondi job par job près ;
  - (c) les jobs **fixés** restants gardent leurs runs (G6 de #185) : les runs du job supprimé sont perdus et la production peut passer sous le besoin (voir Q2) ;
  - (d) racine, produit ajouté depuis un type, copie : aucun besoin écrit, les runs du job supprimé sont perdus ; les matériaux sont recalculés sur les runs restants ;
  - (e) produit dont l'étape supprimée était la seule étape : ses consommateurs le reçoivent comme matériau à acheter (liste d'achats, arbre, #121) ; aucun refus ;
  - (f) produit qui n'a plus aucun consommateur parmi les étapes (étape **orpheline**) : non écrit par la cascade, garde runs et quantité, compté comme une racine par la liste d'achats (comportement actuel, voir Q1) ;
  - (g) un groupe enfant dont le besoin baisse perd ses jobs en trop et peut être dissous (#131 R1 à R11).
- **R11 — Erreurs de la cascade.** `BlueprintProductMissingFromSdeException` → **400** avec son message (`Product of blueprint N missing from the SDE`), aucun `flush`, comme `UpdateStepProcessor::process()`. Le cycle entre produits (`LogicException`) reste une 500.
- **R12 — Réponse inchangée.** 204 sans corps. Les étapes modifiées par la cascade ne sont pas renvoyées.

### Une seule source pour le bouton

- **R13 — Ressource.** `ProjectStepResource::$deletable` (booléen) vaut vrai si et seulement si R1, R2 et R3 laissent supprimer l'étape. Une seule règle du domaine (par exemple `IndustryProject::refusalToDeleteStep(IndustryProjectStep): ?string`, message de refus ou null), appelée par `DeleteStepProcessor` pour refuser et par `IndustryResourceMapper::stepToResource()` pour `deletable`. Elle ne lit que le projet et ses étapes, sans requête : `deletable` est présent dans toute réponse qui porte une étape.
- **R14 — Bouton.** Dans `StepTree.vue`, le bouton supprimer s'affiche si `step.deletable && !readonly`, le placeholder sinon. `isLinkedToEsi()` reste la règle des runs, du calcul automatique, de ME/TE et de la découpe. Aucun autre élément visuel ; texte et modale de confirmation inchangés.
- **R15 — Store.** `stores/industry/steps.ts::deleteStep()`, après une réponse réussie et quand le projet est le projet courant, recharge le projet (`projectsStore.fetchProject(projectId)`, comme après une découpe, une fusion ou un ajout de job, A10 de #191) au lieu de filtrer l'étape. Projet non courant : aucun rechargement. Erreur : `projectsStore.error` = message du serveur (inchangé), aucun rechargement, rejet propagé.

### Contrat de l'API

| Cas | Avant | Après |
|---|---|---|
| DELETE d'une étape avec un job matché | 204, étape et lien supprimés | 400 `Cannot delete a step linked to an ESI job` |
| DELETE de la seule étape du produit du projet | 204 | 400 `Cannot delete the last step of the project product` |
| DELETE d'une étape seule avec achat, stock ou « acheté » | 204, achats et stock supprimés | 400 `Cannot delete a step holding purchases, stock or a purchased mark` |
| DELETE d'un job d'un groupe de deux | 204, l'autre job reste découpé, enfants non recalculés | 204, l'autre job est dissous, données reprises, cascade |
| DELETE d'un job d'un groupe de trois ou plus | 204, trou dans `splitIndex`, `totalGroupRuns` inchangé | 204, renumérotés, `totalGroupRuns` réécrit, cascade |
| GET d'un projet, réponses portant une étape | — | champ `deletable` en plus |

Pas de consommateur externe connu de l'API.

## Exemples chiffrés

Ces exemples serviront de tests rouges. SDE golden (`tests/Fixtures/Golden/sde-blueprint-recipes.json`), sans structure, blueprints synthétiques `produit + SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET` comme dans `CreateStepProcessorAddedJobTest` : Fernite Carbide (réaction, 10 000 unités par run ; 100 Fernite Alloy et 5 Hydrogen Fuel Block par run), Fernite Alloy (réaction, 200 par run ; 5 Hydrogen Fuel Block par run), Hydrogen Fuel Block (fabrication, 40 par run, ME 10). Produit du projet : Fernite Carbide (`setProductTypeId()`, absent des fixtures actuelles). Colonnes : `[runs, quantity, runsFixed, splitIndex, totalGroupRuns]` pour les jobs, `[runs, quantity]` pour une étape seule. « Dissous » = `splitGroupId` null.

Projets (tous stables : un recalcul ne change rien) :

- **FC4** (fixture `ferniteCarbideProjectOfFourRuns()`) : Fernite Carbide racine `[4, 40 000]` ; Fernite Alloy `[2, 400]` profondeur 1 ; Hydrogen Fuel Block `[1, 30]` (20 + 10) profondeur 2.
- **FC4-R** : FC4 dont la racine est un groupe `fernite-carbide` `[2, 20 000, false, 0, 4]`, `[2, 20 000, false, 1, 4]`.
- **FC10** (fixture `ferniteCarbideProjectOfTenRuns()`) : Fernite Carbide racine `[10, 100 000]` ; Fernite Alloy groupe `fernite-alloy` j0 `[3, 600, false, 0, 5]`, j1 `[2, 400, false, 1, 5]` ; Hydrogen Fuel Block `[2, 75]` (50 + 15 + 10).
- **FC10-F** : FC10 avec j0 et j1 fixés (`runsFixed` vrai).
- **FC10-A** : état de A-E3 de #191 (FC10 + job ajouté de 4 runs) : j0 `[3, 600, true, 0, 9]`, j1 `[2, 400, true, 1, 9]`, j2 `[4, 800, true, 2, 9]` ; Hydrogen Fuel Block `[3, 95]`.

### Suppression acceptée — `DeleteStepProcessorTest` (nouveau), SDE golden

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| D-E1 | Cas de l'issue, jobs non fixés. FC10, DELETE j1 | j0 `[3, 600, false, 0, 5]` toujours dans `fernite-alloy` ; Hydrogen Fuel Block `[2, 75]` | j0 `[5, 1 000, false, 0, null]` dissous ; Hydrogen Fuel Block `[2, 75]` (50 + 25) ; 4 → 3 étapes |
| D-E2 | Cas de l'issue, jobs fixés (Q2 option A). FC10-F, DELETE j0 | j1 `[2, 400, true, 1, 5]` dans le groupe ; Hydrogen Fuel Block `[2, 75]` | j1 `[2, 400, true, 0, null]` dissous ; Hydrogen Fuel Block `[2, 60]` (50 + 10). Option B : j1 `[5, 1 000, false, 0, null]`, Hydrogen Fuel Block `[2, 75]` |
| D-E3 | Retrait du job ajouté. FC10-A, DELETE j2 | j0 `[3, 600, true, 0, 9]`, j1 `[2, 400, true, 1, 9]` ; Hydrogen Fuel Block `[3, 95]` | j0 `[3, 600, true, 0, 5]`, j1 `[2, 400, true, 1, 5]` ; Hydrogen Fuel Block `[2, 75]`. Option B : mêmes valeurs, `runsFixed` faux |
| D-E4 | Renumérotation (Q2 option A). FC10-A, DELETE j0 | `[2, 400, true, 1, 9]`, `[4, 800, true, 2, 9]` ; Hydrogen Fuel Block `[3, 95]` | ancien j1 `[2, 400, true, 0, 6]`, ancien j2 `[4, 800, true, 1, 6]` ; Hydrogen Fuel Block `[2, 80]` (50 + 10 + 20). Option B : `[3, 600, false, 0, 5]`, `[2, 400, false, 1, 5]` (répartition égale de R13 de #131), Hydrogen Fuel Block `[2, 75]` |
| D-E5 | Job racine. FC4-R, DELETE le job racine 1 | job racine 0 `[2, 20 000, false, 0, 4]` dans le groupe ; Fernite Alloy `[2, 400]` ; Hydrogen Fuel Block `[1, 30]` | job racine 0 `[2, 20 000, false, 0, null]` dissous ; Fernite Alloy `[1, 200]` ; Hydrogen Fuel Block `[1, 15]` (10 + 5) |
| D-E6 | Racine de trois jobs. FC4 dont la racine est `[2, 20 000, false, 0, 4]`, `[1, 10 000, false, 1, 4]`, `[1, 10 000, false, 2, 4]`, DELETE le job 0 | `[1, 10 000, false, 1, 4]`, `[1, 10 000, false, 2, 4]` ; Alloy `[2, 400]` ; Hydrogen Fuel Block `[1, 30]` | `[1, 10 000, false, 0, 2]`, `[1, 10 000, false, 1, 2]` ; Alloy `[1, 200]` ; Hydrogen Fuel Block `[1, 15]` |
| D-E7 | Reprise (R6). FC10, j1 avec 150 en stock, marque « acheté » et un achat de 150 Fernite Alloy ; DELETE j1 | j0 `[3, 600, false, 0, 5]`, stock 0, non acheté, aucun achat ; l'achat est supprimé | j0 `[5, 1 000, false, 0, null]` dissous, stock 150, acheté, l'achat rattaché à j0 ; Hydrogen Fuel Block `[2, 75]` |
| D-E8 | Job lié gardé. FC10, j0 porte un job matché `active` ; DELETE j1 | j0 `[3, 600, false, 0, 5]` dans le groupe | j0 `[5, 1 000, false, 0, null]` dissous, son job matché gardé |
| D-E9 | Étape dont un consommateur dépend. FC4, DELETE Fernite Alloy (seule étape de son produit, non engagée) | Alloy supprimé ; Hydrogen Fuel Block `[1, 30]` | Alloy supprimé ; Fernite Carbide `[4, 40 000]` ; Hydrogen Fuel Block `[1, 20]` (Carbide seul) |
| D-E10 | Garde-fou, produit ajouté depuis un type. FC4 + Phenolic Composites (réaction, profondeur 0, `[4, 8 800]`, consomme de l'Oxygen Fuel Block, aucune étape) ; DELETE Phenolic Composites | supprimé, FC4 inchangé | inchangé |
| D-E11 | Garde-fou, copie. FC4 + copie de Hydrogen Fuel Block (`copy`, `[1, 1]`, profondeur 2) ; DELETE la copie | supprimée, FC4 inchangé | inchangé (hors cascade) |
| D-E12 | Orphelin (Q1, comportement actuel gardé). Projet Quantum Microprocessor (produit du projet) : racine `[32, 32]` ME 10 ; Titanium Carbide (réaction) `[1, 490]` ; Oxygen Fuel Block `[1, 5]`. DELETE Titanium Carbide | Titanium Carbide supprimé ; Oxygen Fuel Block `[1, 5]` | inchangé dans ce lot (Oxygen Fuel Block orphelin). Q1 option A : 400 ; B : Oxygen Fuel Block `[0, 0]` ; C : Oxygen Fuel Block supprimé |

### Refus — `DeleteStepProcessorTest`

`flush` compté par un `EntityManagerInterface` doublé (précédent `entityManagerCountingTheFlushes()`).

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| D-R1 | FC10, j0 porte un job matché `active` ; DELETE j0 | 204, j0 et son lien supprimés | 400 `Cannot delete a step linked to an ESI job` ; 0 `flush` ; 4 étapes, j0 `[3, 600, false, 0, 5]` dans le groupe |
| D-R2 | D-R1 avec un job matché `delivered` | 204 | même refus |
| D-R3 | FC4, DELETE Fernite Carbide | 204, projet sans racine | 400 `Cannot delete the last step of the project product` ; 0 `flush` ; 3 étapes |
| D-R4 | FC4, Fernite Alloy avec 100 en stock ; DELETE Alloy. Variantes : Alloy marquée « acheté » ; Alloy avec un achat | 204, stock, marque ou achat perdus | 400 `Cannot delete a step holding purchases, stock or a purchased mark` ; 0 `flush` ; Alloy inchangée |
| D-R5 | Ordre (R4). FC4, Fernite Carbide porte un job matché ; DELETE Carbide. Et : Alloy porte un job matché et 100 en stock | 204 | 400 `Cannot delete a step linked to an ESI job` dans les deux cas |
| D-R6 | Cascade en erreur (R11). FC10 dont Hydrogen Fuel Block a le blueprint `BLUEPRINT_MISSING_FROM_THE_SDE` ; DELETE j1 | 204 | 400 `Product of blueprint 999999999 missing from the SDE` ; 0 `flush` |
| D-R7 | Garde-fous : projet d'un autre pilote ; étape d'un autre projet ; identifiants non UUID | 404 | inchangé (`Project not found`, `Step not found`, sans lecture pour un non-UUID) |

### `deletable` — `IndustryResourceMapperTest` (ou test de la règle du domaine)

| # | Cas | Attendu |
|---|---|---|
| M-1 | FC10 : j0 avec un job matché, j1, Fernite Carbide, Hydrogen Fuel Block | `false`, `true`, `false`, `true` |
| M-2 | FC4 : Fernite Alloy avec 100 en stock ; sans stock | `false` ; `true` |
| M-3 | FC4-R : les deux jobs racine | `true`, `true` |
| M-4 | D-E10 et D-E11 : Phenolic Composites, copie | `true`, `true` |

### Front (Vitest)

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| F1 | `deleteStep(projectId, j1)` sur le projet courant | DELETE, j1 retiré du store par filtre, aucun GET | DELETE puis `GET /industry/projects/{id}` ; le store reflète la réponse du GET (j0 dissous) |
| F2 | `deleteStep` sur un projet qui n'est pas le courant | aucun GET | aucun GET (garde-fou) |
| F3 | DELETE rejeté par `ApiError('Cannot delete a step linked to an ESI job', 400)` | `projectsStore.error` = message, rejet | inchangé, et aucun GET ; étapes du store inchangées |
| F4 | `StepTree`, non lecture seule : étape `deletable: false` sans job matché (stock) ; étape `deletable: true` | deux boutons supprimer (`aria-label` « Delete step ») | un seul bouton (l'étape supprimable) |
| F5 | `StepTree` : étape `deletable: true` en lecture seule ; étape liée à l'ESI (`deletable: false`) | aucun bouton pour les deux | inchangé (garde-fou) |

## Cas limites

- **Statut du job matché** : `delivered`, `ready`, `paused` ou `active`, même refus (R1) ; une étape en mode `manual` sans job matché est supprimable.
- **Achats de matériaux** rattachés à l'étape (`typeId` différent du produit) : mêmes règles que les autres achats (R3, R6).
- **Job matché arrivé entre l'affichage et le clic** (sync des jobs toutes les 30 min, matcher) : 400 R1, message dans le bandeau, le projet n'est pas rechargé ; le bouton reste affiché jusqu'au prochain chargement.
- **Runs du projet** : supprimer un job racine ne change pas `IndustryProject::$runs` ; l'écart entre runs du projet et runs des étapes reste visible jusqu'à la régénération, comme une saisie de runs sur la racine.
- **Production sous le besoin** : un job fixé supprimé peut laisser un produit sous son besoin sans qu'aucune liste ne le montre (D-E2) ; le bouton « Calcul automatique » existant (G5 de #185) rend le produit à son besoin (Q2).
- **Groupe enfant** : une suppression qui baisse un besoin peut supprimer des jobs en trop d'un groupe enfant et le dissoudre (#131) ; les données de ces jobs sont reprises.
- **Copie** : supprimable ; hors cascade ; la fabrication de son blueprint ne change pas ; le coût de copie du projet baisse. Une copie dont la fabrication est supprimée garde ses runs jusqu'à la régénération (#235).
- **Groupe d'un seul job déjà en base** (antérieur à #111) : c'est la dernière étape de son produit ; R2 ou R3 peuvent la refuser, sinon elle est supprimée.
- **Produit coupé en plusieurs `splitGroupId`** (ancien chemin de l'interface, #191) : R6, R8 et R9 ne regardent que le groupe de l'étape ; la cascade traite toutes les étapes du produit ensemble ; R2 compte toutes les étapes du produit.
- **Projet terminé ou archivé** : l'API ne vérifie pas le statut (comme le PATCH) ; le front masque le bouton en lecture seule.

## Hors périmètre

- Étapes orphelines (Q1).
- Durabilité de la suppression : une étape du plan supprimée revient à la prochaine régénération (`applyPlanToExistingSteps()`). Pour ne plus fabriquer un produit : marque « acheté », ou types exclus du projet. À ouvrir en issue si la suppression doit survivre à la régénération.
- Contrôle du statut du projet par les processors d'étape.
- Fusion qui ne reporte ni le stock ni la marque « acheté » (noté par #131).
- Traduction des messages de refus : le bandeau affiche le message anglais du serveur, comme les refus voisins.
- Réparation des groupes laissés incohérents par des suppressions passées (voir Impact prod).
- Projets de Group Industry.

## Tests existants appelés à casser

- **Backend** : aucun. Aucun test ne construit `DeleteStepProcessor`. Les tests qui appellent le vrai `IndustryResourceMapper::stepToResource()` (`IndustryResourceMapperTest`, `FavoriteSystemStructureChoiceTest`, `IndustryProjectFactoryTest::displayedJobsOf()`) construisent un projet avec `setProductTypeId()` : R13 s'y calcule sans erreur. Les processors qui doublent le mapper ne lisent pas `deletable`.
- **Front, vérification des types** (`vue-tsc -b` dans `npm run build`, `tsconfig.json` inclut `tests/**/*.ts`) : `deletable` devient un champ requis de `IndustryProjectStep` (`stores/industry/types.ts`). Les fixtures qui construisent une étape complète doivent le recevoir : `projectDetailUnknownEstimatedProjectTime`, `shoppingTabPurchaseLinkError`, `shoppingTabStockDeductedOnce`, `stepsTabFailedProducts`, `stepsTabFixedRuns`, `stepsTabJobsSync`, `stepsTabSyncFailures`, `stepTreeReactionMeLevel`, `stepTreeUnknownTimePerRun`, `timeEfficiencyInputStep` (`frontend/tests/components/industry/`), `useProjectTime`, `useShoppingStockAnalysis` (`frontend/tests/composables/`), `projectsMatchJobsFailedCharacters`, `stepsAddChildJob`, `stepsFixedRuns`, `stepsSurplusJobs` (`frontend/tests/stores/`). Aucun résultat de test ne change.

Restent verts (garde-fous) : `CreateStepProcessorAddedJobTest`, `UpdateStepFixedRunsTest`, `SplitGroupQuantitiesTest`, `IndustryStepCalculatorTest` (cascade inchangée), `IndustryUriIdentifiersTest`, les specs Vitest de `StepTree` (aucune ne cherche le bouton supprimer).

## Notes de mise en œuvre (non normatives)

- `DeleteStepProcessor` reçoit `IndustryStepCalculator`. Ordre : refus R1-R3 (règle du domaine), puis R6, R7, R8 ou R9, puis la cascade dans un `try` qui traduit `BlueprintProductMissingFromSdeException` en 400 (précédent `UpdateStepProcessor::process()`), puis `flush` (la cascade en fait un quand quelque chose change ; le second est sans effet).
- La dissolution doit précéder la cascade : un groupe d'un job passé à `applyNeedToSteps()` prend la branche d'une étape seule (l. 57-58) qui garde `splitGroupId` et écrit `totalGroupRuns` = runs (l. 111). Pour une racine, la cascade n'écrit rien : R8 et R9 sont les seules écritures.
- R6 et R8 réutilisent la logique de `SplitGroupQuantities` (absorption par le job de plus grand `splitIndex`, renumérotation à partir de 0) ; `absorbSurplusJobs()` et `splitIndexesFromZero()` sont privées aujourd'hui.
- R13 : `stepToResource()` est appelé une fois par étape ; la règle parcourt les étapes du projet (au plus quelques centaines), sans requête.
- `ProjectStepResource::$deletable` sans valeur par défaut, comme `favoriteSystemWithoutSuitableStructure`.
- Carte du module (`docs/modules/industry.md`) : ajouter `DeleteStepProcessor` aux appelants d'`IndustryStepCalculator` (lignes 43 et 90) et une ligne « Suppression d'une étape ou d'un job » à côté de « Ajout d'une étape ou d'un job » (ligne 82).

## Questions ouvertes

### Q1 — Étapes orphelines après une suppression

Supprimer la seule étape d'un produit laisse sans consommateur les étapes de ses matériaux qui n'en avaient pas d'autre (D-E12). La cascade ne les écrit pas : elles gardent runs et quantité, la liste d'achats achète leurs matériaux et l'arbre les montre comme des racines. Aujourd'hui c'est déjà le cas ; l'issue demande « les besoins des enfants recalculés » sans trancher ce cas. Chaque correction change un montant visible ou supprime des données :

- **Option A (recommandée)** : refuser la suppression qui laisserait une étape de production sans consommateur, 400 `Cannot delete a step whose materials are made only for it; mark it as purchased instead`, rangé après R3. Aucune donnée supprimée, aucun montant changé en silence ; la marque « acheté » fait déjà ce que l'utilisateur cherche (le produit est acheté, ses matériaux ne sont plus fabriqués). Coût : la règle lit les matériaux du SDE (`findMaterialEntitiesForBlueprints`), donc `deletable` ne peut plus se calculer sans requête dans `stepToResource()` ; il faut un préchargement par projet (comme `preloadSimilarJobs()`) ou accepter que le bouton reste visible et que le refus s'affiche dans le bandeau.
- **Option B** : besoin 0 pour les orphelines (runs 0, quantité 0, propagé à leurs matériaux). Pas de refus, mais des étapes à 0 run restent affichées, « en stock » au sens de `isInStock()` (#182).
- **Option C** : supprimer les orphelines en cascade (refus si l'une est liée à un job ESI). Supprime des étapes, leurs achats et leur stock.

Dans ce lot : comportement actuel (R10 f), D-E12 garde-fou.

### Q2 — Job fixé supprimé

Un job fixé (saisie de runs, surproduction, job ajouté) supprimé emporte ses runs ; les jobs fixés restants gardent les leurs (R10 c).

- **Option A (recommandée, suivie par la spec)** : aucune règle nouvelle, la cascade garde les runs fixés (G6 de #185). Retirer un job ajouté rend exactement l'état d'avant l'ajout (D-E3). Risque : un produit peut passer sous son besoin sans signal (D-E2 : 400 Fernite Alloy pour un besoin de 1 000) ; le bouton « Calcul automatique » le corrige.
- **Option B** : supprimer un job d'un groupe qui reçoit un besoin libère les jobs restants (règle R13 de #131) : la production revient au besoin, la surproduction est perdue (D-E2 à D-E4, colonnes « Option B »).

## Décisions prises en autonomie

- **R1 — Refus sur le champ `jobMatches`**, tout statut compris : c'est exactement la règle de l'interface (`esiJobId` dérivé de `jobMatches`), donc aucun bouton ne disparaît pour cette raison. Alternative écartée : transférer le job matché à un autre job du groupe (comme #131 R4) ; l'issue demande un refus, et un job ESI réel lié à une étape que l'utilisateur supprime mérite un geste explicite (délier).
- **400 plutôt que 409** : style des refus d'étape voisins (`Cannot mark root products as purchased`, `Step is already split…`) ; 409 n'est utilisé dans le module que pour un doublon d'import.
- **R2 — Dernière étape du produit du projet refusée** : un projet sans son produit est incohérent (nom affiché, produits racine) et la régénération la recréerait. Les produits ajoutés depuis un type restent supprimables. Ne supprime rien et ne change aucun montant.
- **R3 et R6 — `isCommitted()` réutilisé, mais pas tel quel** : le « job engagé » de #131 mélange le job matché (jamais transféré par une suppression, R1) et des données transférables (achats, stock, « acheté »). Dans un groupe, ces données passent à un autre job (`absorb()`, comme #131 R4) ; sur une étape seule, elles n'ont pas de repreneur et la suppression est refusée plutôt que de les perdre. Alternative écartée : garder la perte actuelle (achats rattachés à des transactions du wallet supprimés sans signal).
- **Job repreneur = autre job de plus grand `splitIndex`** : même choix que #131 R4, une seule règle d'absorption.
- **R8 — `totalGroupRuns` = somme des runs restants** pour un groupe sans besoin (racine, copie), symétrique de l'ajout d'un job (A5 de #191) ; la cascade réécrit celui des groupes qui reçoivent un besoin.
- **R10 — Une seule règle de recalcul, la cascade existante**, plutôt qu'une redistribution propre à la suppression : jobs non fixés suivent le besoin, jobs fixés et racines gardent leurs runs (Q2 pour les jobs fixés).
- **R10 e — Supprimer la seule étape d'un produit consommé reste permis** : le produit devient un matériau acheté, comme aujourd'hui ; c'est l'effet attendu d'une suppression.
- **Copie supprimable** : hors cascade, aucune étape n'en dépend dans le calcul des besoins ; la régénération la recrée si la fabrication reste.
- **R12 et R15 — Réponse 204 inchangée, rechargement du projet** : même choix que #131 R16, A10 de #191, la découpe et la fusion ; coût, un GET de plus par suppression.
- **R13 — Champ `deletable` calculé par le serveur** plutôt que de recopier R1 à R3 dans le front : une seule source, sans requête ; coût, un champ requis de plus dans 16 fixtures TypeScript.
- **R14 — Aucune maquette** : le bouton existant est masqué dans plus de cas, avec le placeholder existant ; aucun élément visuel nouveau.
- **Q1 laissé au comportement actuel dans ce lot** : chaque correction change un montant visible, supprime des données ou demande un préchargement SDE pour le bouton.
- **Glossaire non modifié** : termes reportés à l'implémentation (section Glossaire).

## Impact prod

- **Aucune migration, aucune commande** ; aucune donnée modifiée au déploiement.
- **Refus nouveaux côté API** : étape liée à un job ESI (déjà masquée par l'interface), dernière étape du produit du projet et étape seule avec achat, stock ou marque « acheté » (le bouton était affiché, il disparaît).
- **Montants visibles changés à chaque suppression acceptée** : la cascade recalcule les étapes enfants, donc liste d'achats, coût estimé et marges du projet (D-E2 : Hydrogen Fuel Block 75 → 60 ; D-E5 : Fernite Alloy 400 → 200). La cascade peut supprimer des jobs en trop d'un groupe enfant (#131), en reprenant leurs données.
- **Données gardées** : achats, stock et marque « acheté » d'un job supprimé passent à un autre job du groupe au lieu d'être supprimés.
- **États laissés par des suppressions passées, non réparés** : groupes d'un seul job et trous dans `splitIndex`, étapes orphelines. Comptage en lecture seule avant le déploiement ; si non vide, ouvrir une issue (un PATCH « Calcul automatique » ou une fusion corrige un groupe à la main).

```sql
SELECT s.project_id, p.status, s.split_group_id, COUNT(*) AS jobs, MIN(s.split_index) AS min_index, MAX(s.split_index) AS max_index
FROM industry_project_steps s
JOIN industry_projects p ON p.id = s.project_id
WHERE s.split_group_id IS NOT NULL
GROUP BY s.project_id, p.status, s.split_group_id
HAVING COUNT(*) = 1 OR MIN(s.split_index) <> 0 OR MAX(s.split_index) <> COUNT(*) - 1;
```

```sql
SELECT s.project_id, p.status, s.product_type_id, s.depth, s.runs, s.quantity
FROM industry_project_steps s
JOIN industry_projects p ON p.id = s.project_id
WHERE s.depth > 0
  AND s.activity_type <> 'copy'
  AND NOT EXISTS (
    SELECT 1
    FROM industry_project_steps c
    JOIN sde_industry_activity_materials m
      ON m.type_id = c.blueprint_type_id
     AND m.activity_id = CASE c.activity_type WHEN 'reaction' THEN 11 ELSE 1 END
    WHERE c.project_id = s.project_id
      AND c.activity_type <> 'copy'
      AND m.material_type_id = s.product_type_id
  );
```

## Glossaire

À reporter dans [`docs/glossary.md`](../glossary.md) à l'implémentation :

- **Étape supprimable** (Deletable step), nouveau : étape que l'API accepte de supprimer : sans job matché, qui n'est pas la dernière étape du produit du projet, et dont les achats, le stock et la marque « acheté » peuvent passer à un autre job de son groupe (spec #202). Symboles : `ProjectStepResource::$deletable`, `DeleteStepProcessor`.
- **Job matché**, précision : « Une étape qui en porte un n'est pas supprimable (400) ; délier d'abord (spec #202). »
- **Groupe découpé**, précision : « La suppression d'un job renumérote les jobs restants à partir de 0 et dissout un groupe réduit à un job (spec #202). »
- **Dissolution**, précision : « aussi après la suppression d'un job (spec #202) » ; symbole `DeleteStepProcessor`.
- **Job engagé**, précision : « Un job engagé supprimé passe ses achats, son stock et sa marque « acheté » à un autre job de son groupe ; une étape seule engagée n'est pas supprimable (spec #202). »
- **Étape racine**, précision : « La dernière étape du produit du projet n'est pas supprimable (spec #202). »
- **Étape orpheline** (Orphan step), nouveau si Q1 est tranchée : étape de production dont le produit n'est plus un matériau d'aucune étape du projet.
