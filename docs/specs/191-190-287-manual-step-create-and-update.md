# Spec #191 + #190 + #287 — Ajout et édition d'une étape : job ajouté à un groupe, ME d'une réaction, structure et bornes de l'entrée

**Statut : rédigée le 2026-10-31 en mode nuit (circuit m), à valider par Sylvain.** Aucune question n'a pu être posée : les choix sont listés dans « Décisions prises en autonomie » ; ce qui change un montant visible ou réécrit des données de production est dans « Impact prod ».

Circuit : `circuit:m` (pas de note de câblage). Harnais disponibles : tests unitaires des processors sur le SDE golden (trait `BuildsProjectOnGoldenSde`, `IndustryStepCalculator` réel, précédent `UpdateStepProcessorTest::patch()`), tests de contraintes des DTO (`Validation::createValidatorBuilder()->enableAttributeMapping()`, précédent `UpdateStepInputValidationTest`), tests de commande (`tests/Unit/Command/`, `CommandTester`), tests d'intégration sur les fixtures de `UpdateStepFixedRunsTest`, Vitest pour le store et `StepTree.vue`.

## Contexte

Trois issues sur les mêmes processors d'étape :

- **#191** (audit du 2026-10-22) : l'ajout d'une étape enregistre les runs comme quantité ; un job ajouté à un groupe découpé ne met à jour ni `totalGroupRuns` ni les matériaux job par job ; un type sans blueprint prend son typeId comme blueprint.
- **#190** (audit du 2026-10-22) : un ME saisi sur une étape de réaction est appliqué à ses matériaux.
- **#287** (revue de sécurité du lot #279/#280/#281) : la configuration de structure d'un autre utilisateur est rattachable à une étape, un UUID inconnu retire la structure en silence, `runs` n'a pas de borne haute, des UUID invalides donnent 500.

État vérifié sur `main` 8132c650 (#280, #200 et #131 fusionnés) :

| Site | Aujourd'hui | Appelants |
|---|---|---|
| `CreateStepProcessor::process()`, branche `stepId` (l. 74-110) | l'étape désignée reçoit un **nouveau** `splitGroupId` (l. 81-86), `splitIndex` 0, `totalGroupRuns` = ses runs + runs saisis ; le job ajouté : `setQuantity($runs)` (l. 91), ME/TE/structure copiés (l. 99-101), `runsFixed` faux ; aucun recalcul ; un seul `flush` ; réponse `{newStep, updatedStep}` | `stores/industry/steps.ts::addChildJob()` (l. 140-179), seul chemin de l'interface (voir ligne suivante) |
| `StepTree.vue::confirmAddChild()` (l. 435-444) | envoie **toujours** `stepId` = `splitGroup.children[0].id` et jamais `splitGroupId` ; la carte d'un produit regroupe ses étapes par blueprint et activité (l. 189-191) et trie ses jobs par runs décroissants (l. 231). Sur un groupe déjà découpé, le job de plus de runs est sorti de son groupe vers un groupe neuf avec le job ajouté ; les autres jobs gardent l'ancien `splitGroupId` (deux groupes pour un produit, `splitIndex` 1 en double) | bouton « Add job » de chaque carte |
| `CreateStepProcessor::process()`, branche `splitGroupId` (l. 113-150) | job ajouté : `setQuantity($runs)` (l. 134), `totalGroupRuns` copié du premier job trouvé sans y ajouter les runs (l. 141), ME copié (l. 142) ; les autres jobs ne changent pas ; aucun recalcul | API seule (le front ne l'envoie pas) |
| `CreateStepProcessor::process()`, branche `typeId` (l. 152-194) | #280 : producteur par `SplitGroupMaterials::producerOf()`, 400 avec le message de `ProducerMissingFromSdeException` sans producteur (l. 162-166) ; quantité `runs × unités par run` (l. 183) ; `meLevel ?? 0` enregistré tel quel, réaction comprise (l. 188) ; aucun recalcul | `Industry.vue` (produits ajoutés après le premier à la création d'un projet, l. 269-272, ME saisi dans la modale sans savoir si le produit est une réaction) |
| `UpdateStepProcessor::update()` | `meLevel` accepté quelle que soit l'activité, cascade si changé (l. 97-100) ; `structureConfigId` : `find(Uuid::fromString(…))` sans contrôle du propriétaire, un UUID inconnu donne `null` et retire la structure (l. 106-112) ; les champs sont écrits en mémoire au fil de l'eau, un refus levé ensuite empêche seulement le `flush` | `steps.ts::updateStep()` ; le front n'envoie jamais `structureConfigId` (`grep structureConfigId frontend/src` : `types.ts` seul) |
| Lecteurs du ME d'une étape pour ses matériaux | `IndustryStepCalculator::needOfConsumer()` (l. 103-114, l'issue citait l. 147-157 avant #131), `SplitGroupMaterialNeeds::applyChangeOfJobs()` (l. 43-44), `ProductionStepNeedsResolver::materialQuantityOf()` (l. 104-107, liste d'achats et arbre depuis les étapes) : tous lisent `getMeLevel()` sans regarder l'activité | cascade, découpage, fusion, liste d'achats |
| `IndustryProjectFactory` | réaction : ME 0 / TE 0 à la génération (`collectStepsFromTree()`, l. 406-409) ; la régénération ne reprend ME/TE de l'étape existante que pour une fabrication hors racine (`withSettingsOfExistingStep()`, l. 223-226) : une réaction du plan revient à ME 0 ; une étape hors plan (ajoutée à la main) garde son ME (`applyPlanToExistingSteps()`, l. 312-342) | création, régénération, `app:industry:regenerate-steps`, `app:industry:regenerate-multi-unit-steps` (qui saute un projet dont la régénération changerait un ME/TE, G4 de la spec #278-284) |
| `StepTree.vue` | champ ME (l. 705-716) et libellé `ME{{ step.meLevel }}` (l. 733) affichés pour toute étape sauf copie (l. 702), réaction comprise ; `onMeChange()` borne 0-10 (l. 451-454) | onglet Étapes |
| `CreateStepInput::$runs` (l. 14-15), `UpdateStepInput::$runs` (l. 42-43) | `Positive` seul ; `quantity` et `runs` sont des colonnes `integer` (`IndustryProjectStep.php:36-40`, PostgreSQL int4, 2 147 483 647) : `runs × unités par run` au-delà donne une erreur SQL, donc 500 | POST et PATCH d'une étape |
| Bornes existantes | `CreateProjectInput::MAXIMUM_PROJECT_RUNS = 100000` (`Range(1, …)`), même valeur privée dans `CreateGroupProjectInput::MAXIMUM_RUNS` ; modale de création `max="100000"` (`Industry.vue`) | création de projet |
| Propriété d'une structure | `IndustryStructureConfig::$user` ; `StructureConfigProvider`, `UpdateStructureProcessor`, `DeleteStructureProcessor` : `getUser() !== $user` → 404 `Structure not found`. Le matcher ne cherche que les structures du propriétaire du projet (`findByUserAndLocationId`) ; les structures corpo partagées (`findCorporationSharedStructures`) ne sont lues que pour l'affichage | — |

Le test `CreateStepProcessorTest::testStepAddedToASingleStepCurrentlyCountsItsRunsAsQuantityIssue191` (l. 181-189) fige la quantité 3 pour 3 runs de Helium Fuel Block ; `testStepAddedToASplitGroupCurrentlyCountsItsRunsAsQuantityAndKeepsTheTotalGroupRunsIssue191` (l. 239-250) fige `[[6, 60 000, 0, 12], [6, 60 000, 1, 12], [4, 4, 2, 12]]` (groupe `issue-191`).

### Points caducs

| Point | Preuve |
|---|---|
| #191 : quantité = runs dans la branche `typeId` | #280 : `CreateStepProcessor.php:183`, `testStepAddedForAManufacturedTypeIsMadeByItsBlueprintAndCountsItsUnitsPerRun` (5 runs → 200) |
| #191 : `?? $typeId` comme blueprint d'un type sans producteur | #280 : `producerOf()` puis 400 (l. 162-166), `testStepAddedForATypeWithoutProducerIsRefusedWithoutAddingAnyStep`. Les branches `stepId` et `splitGroupId` copient le blueprint d'une étape existante : aucun repli possible |
| #287 : identifiants d'URI non UUID en 500 | #200 (07ba4d8c) : `UuidOrNotFound::of()` dans les six processors d'étape (`CreateStep`, `UpdateStep`, `DeleteStep`, `SplitStep`, `MergeSteps`, `LinkJob`) et `UnlinkJob` → 404 ; `IndustryUriIdentifiersTest` interdit `Uuid::fromString($uriVariables` dans le module |
| #287 : `stepId` du corps non UUID | #200 : `CreateStepProcessor.php:75`, 404 `Step not found` sans lire les étapes, `testStepIdOfTheBodyThatIsNotAUuidIsNotFoundWithoutReadingTheSteps` |
| #287 : `structureConfigId` non UUID | #200 : `#[Assert\Uuid]` (`UpdateStepInput.php:56`) → 422 avant le processor, `UpdateStepInputValidationTest::testStructureConfigIdThatIsNotAUuidIsRejected` |
| #287 : `splitGroupId` non UUID | jamais converti : comparé en texte aux étapes du projet (l. 117), un texte quelconque donne 404 `Split group not found` comme un UUID inconnu. Pas de 500 ; un test garde-fou est ajouté (V4) |

Restent vrais : #191 branches `stepId` et `splitGroupId` (quantité, total, matériaux) et le chemin de l'interface ; #190 en entier ; #287 propriétaire et UUID inconnu de `structureConfigId`, borne haute de `runs`.

## Règles métier

Notations : **job ajouté** = étape créée par `POST /industry/projects/{id}/steps` avec `stepId` ou `splitGroupId` ; **étape de référence** = l'étape désignée par `stepId`, ou le job de plus petit `splitIndex` du groupe désigné par `splitGroupId` ; **groupe cible** = le groupe découpé de l'étape de référence, ou l'étape de référence seule si elle n'est pas découpée ; **runs du besoin** = `IndustryStepCalculator::needRunsOf()`, `null` pour une étape sans consommateur (racine, produit ajouté depuis un type) et pour une copie.

### #191 — Job ajouté

- **A1 — Groupe cible.** Avec `stepId` d'une étape non découpée, l'étape et le job ajouté forment un groupe découpé neuf (comportement actuel). Avec `stepId` d'un job d'un groupe découpé, le job ajouté rejoint **ce** groupe, comme avec `splitGroupId` ; aucun job ne change de `splitGroupId`. Priorité inchangée : `stepId`, puis `splitGroupId`, puis `typeId`.
- **A2 — Position.** Le job ajouté prend `splitIndex` = plus grand `splitIndex` du groupe cible + 1 (1 pour un groupe neuf). Les autres jobs gardent leur `splitIndex`.
- **A3 — Champs copiés.** Blueprint, produit, activité, profondeur, ordre d'affichage, TE et structure viennent de l'étape de référence ; ME aussi, sauf pour une réaction (M3). Le job ajouté n'a ni job matché, ni achat, ni stock, ni marque « acheté », mode de matching `auto`.
- **A4 — Quantité.** Quantité du job ajouté = runs × **unités par run** de son blueprint et de son activité (`SplitGroupMaterials::outputPerRunOf()` : copie → 1, sans lecture du SDE). Produit du blueprint absent du SDE : **400** avec le message de `BlueprintProductMissingFromSdeException` (`Product of blueprint N missing from the SDE`), rien n'est ajouté ni écrit.
- **A5 — Les runs s'ajoutent.** Les runs saisis s'ajoutent à ceux du groupe cible : c'est une **surproduction** confirmée par le geste. `totalGroupRuns` = somme des runs de tous les jobs du groupe, écrite sur chacun.
- **A6 — Runs fixés.** Si l'étape de référence a des runs du besoin (non `null`), le job ajouté et **tous** les autres jobs du groupe cible sont fixés (`runsFixed` vrai) à leurs runs, comme une saisie avec `overproduce` (G4 de #185). Étape racine, produit ajouté depuis un type, copie : aucun job n'est fixé (même règle que la saisie de runs sur la racine, `UpdateStepFixedRunsTest::testRunsEnteredOnTheRootStepAreAppliedWithoutBeingFixed`) ; la régénération ramène ces runs au plan.
- **A7 — Cascade.** Après l'ajout, `IndustryStepCalculator::recalculateStepQuantities($project)` : les besoins des matériaux sont recalculés **job par job** (#73) sur tous les jobs du groupe, job ajouté compris, et les étapes et groupes enfants suivent toutes les règles du recalcul (#16, #185, #252, #131 : jobs en trop supprimés, dissolution). Le groupe cible lui-même : jobs fixés gardés à leurs runs, quantité = runs × unités par run (A6) ; racine et copie ne reçoivent aucun besoin. Le job ajouté n'est donc jamais supprimé par cette cascade ; aux recalculs suivants il suit les règles de tout job (#131 R2 : un job fixé est gardé ; après « Calcul automatique », R13 de #131 peut le supprimer).
- **A8 — Atomicité.** Tout refus (401, 404, 422, 400 de A4 ou de la cascade, `BlueprintProductMissingFromSdeException` d'un enfant comme dans `UpdateStepProcessor`) laisse la base inchangée : aucun `flush`. Un ajout accepté est enregistré en une fois avec les changements de la cascade.
- **A9 — Réponse inchangée.** `stepId` : `{newStep, updatedStep}` (`updatedStep` = étape de référence) ; `splitGroupId` : le `ProjectStepResource` du job ajouté ; `typeId` : la ressource de l'étape. Les étapes changées par la cascade ne sont pas dans la réponse.
- **A10 — Store.** `stores/industry/steps.ts::addChildJob()`, après une réponse réussie et quand le projet est le projet courant, recharge le projet (`projectsStore.fetchProject(projectId)`, comme après un découpage ou une fusion) au lieu de remplacer et pousser les étapes à la main. Valeur rendue inchangée (`newStep`, ou le job ajouté). Projet non courant : aucun rechargement. Erreur : `projectsStore.error` comme aujourd'hui.
- **A11 — Branche `typeId` inchangée** (#280) : aucune cascade (voir Hors périmètre), sauf M2.

### #190 — ME d'une réaction

- **M1 — PATCH.** Sur une étape de **réaction**, `meLevel` différent de 0 est refusé : **400** `Cannot set a material efficiency on a reaction step`, rien n'est écrit (pas de `flush`), aucun autre champ de la requête n'est appliqué. `meLevel: 0` est accepté : il ramène à 0 un ME enregistré différent et déclenche la cascade (règle actuelle d'un ME changé). Fabrication : inchangé. Copie : inchangé (champ masqué par le front).
- **M2 — POST depuis un type.** Un produit dont le producteur est une réaction reçoit **ME 0**, quel que soit `meLevel` envoyé. Même règle qu'à la création d'un projet dont le produit est une réaction (le ME du projet est ignoré pour l'étape racine, `collectStepsFromTree()`), et la modale de création ne sait pas si le produit est une réaction. TE inchangé.
- **M3 — Job ajouté.** Un job ajouté à une réaction (A3) reçoit ME 0, même si l'étape de référence a un ME différent (donnée ancienne). Les autres jobs du groupe ne sont pas modifiés par l'ajout (M5 s'en charge).
- **M4 — Front.** Dans `StepTree.vue`, une étape de réaction n'affiche ni le champ ME ni le libellé `ME n` ; le TE reste affiché et modifiable. Fabrication et copie : inchangé. Aucun autre élément visuel.
- **M5 — Hors lot, en attente de décision de Sylvain.** La commande ponctuelle `app:industry:reset-reaction-me-levels` qui normaliserait les ME de réaction existants (projets actifs) et recalculerait leurs étapes n'est ni implémentée ni testée dans ce lot : elle réécrit des données de production et change des montants visibles. Option A (recommandée) : la commande ci-dessous, à lancer avant `regenerate-multi-unit-steps`. Option B : rien, un `PATCH meLevel: 0` sur chaque réaction corrige à la main. Texte de l'option A conservé :
  - **M5 — Données existantes.** Une commande ponctuelle `app:industry:reset-reaction-me-levels` (nom indicatif du service : `ReactionStepMeLevelReset`) remet à 0 le ME des étapes de réaction des projets **actifs** et recalcule leurs étapes. Conventions C1 à C7 de la spec #278-284 (logique dans un service de `src/Service/Industry/`, un projet à la fois avec `clear()`, échec d'un projet `failed` sans écriture et code de sortie `FAILURE`, `--dry-run` sans écriture).
  - Sélection : projet au statut `active` qui a au moins une étape `reaction` de ME différent de 0, ordre `createdAt` puis `id`.
  - Correction : ME 0 sur chacune de ces étapes, puis `IndustryStepCalculator::recalculateStepQuantities($project)` ; projet `changed`.
  - Ligne : `<uuid> <libellé> (<runs> runs): <k> reaction steps ME -> 0, <n> steps recalculated` (`<n>` = nombre d'étapes rendues par le recalcul) ; `failed: <message>` en cas d'exception ; puis le tableau `['Status', 'Count']` (`Changed`, `Failed`, `Total`). Aucun candidat : `No project to reset`, code `SUCCESS`.
  - Projets terminés et archivés : non sélectionnés (même choix que #278 et #284).
- **M6 — Le calcul ne change pas.** Les lecteurs du ME (`needOfConsumer()`, `applyChangeOfJobs()`, `materialQuantityOf()`) continuent de lire le ME enregistré : M1 à M3 empêchent un ME de réaction non nul d'entrer, M5 retire ceux qui existent.

### #287 — Structure et bornes de l'entrée

- **S1 — Structure de l'utilisateur.** `structureConfigId` d'un PATCH est rattaché seulement si la configuration existe et appartient à l'utilisateur courant (`getUser() === $user`, règle de `StructureConfigProvider`). Inconnue ou à un autre : **404** `Structure not found` (même message pour les deux, pour ne pas révéler l'existence d'une configuration d'un autre). L'étape garde sa structure ; rien n'est écrit, aucun autre champ de la requête n'est appliqué, aucune cascade.
- **S2 — Ordre des refus du PATCH.** 401, 404 projet, 404 étape (inchangés), puis refus de la racine (`purchased`, `inStockQuantity`, inchangés), puis M1 (400), puis S1 (404). Les contraintes du corps (422) passent avant le processor (spec #200 R4).
- **S3 — Borne haute des runs.** `CreateStepInput::$runs` et `UpdateStepInput::$runs` acceptent un entier de **1 à 100 000** (`CreateProjectInput::MAXIMUM_PROJECT_RUNS`, la borne des runs d'un projet). Au-delà : **422** sur `runs` (message de `Range`), rien n'est écrit. `UpdateStepInput::$runs` absent (`null`) reste accepté. Justification : 100 000 × 10 000 (Fernite Carbide, Titanium Carbide, plus grandes unités par run du SDE golden) = 1 000 000 000 ≤ 2 147 483 647, maximum d'une colonne `integer` ; le plus grand `quantity` du SDE de production reste à vérifier (requête dans Impact prod).
- **S4 — UUID** : rien à changer (points caducs), sauf le test garde-fou de `splitGroupId` (V4).

### Contrat de l'API

| Cas | Avant | Après |
|---|---|---|
| POST `stepId` d'une étape non découpée, runs N | 201, job à quantité N, rien recalculé | 201, quantité N × unités par run, total = somme, jobs fixés hors racine/copie, cascade |
| POST `stepId` d'un job d'un groupe découpé | 201, le groupe est coupé en deux | 201, le job rejoint le groupe (A1) |
| POST `splitGroupId`, runs N | 201, quantité N, total inchangé | comme `stepId` d'un job du groupe |
| POST `typeId` d'une réaction avec `meLevel` 10 | 201, ME 10 | 201, ME 0 |
| PATCH `meLevel` ≠ 0 sur une réaction | 200, matériaux réduits | 400 |
| PATCH `structureConfigId` d'un autre utilisateur | 200, rattachée | 404 `Structure not found` |
| PATCH `structureConfigId` UUID inconnu | 200, structure retirée | 404 `Structure not found` |
| POST ou PATCH `runs` > 100 000 | 201/200, ou 500 au-delà de la colonne | 422 `runs` |

Forme des ressources inchangée. Pas de consommateur externe connu.

## Exemples chiffrés

Ces exemples serviront de tests rouges. SDE golden (`tests/Fixtures/Golden/sde-blueprint-recipes.json`), sans structure : Fernite Carbide (réaction, 10 000 unités par run ; 100 Fernite Alloy et 5 Hydrogen Fuel Block par run), Fernite Alloy (réaction, 200 par run ; 5 Hydrogen Fuel Block par run), Hydrogen Fuel Block (fabrication, 40 par run, ME 10). Blueprints synthétiques `produit + SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET` comme dans `UpdateStepProcessorTest`. Colonnes : `[runs, quantity, runsFixed, splitIndex, totalGroupRuns]` pour les jobs, `[runs, quantity]` pour une étape seule. Fernite Alloy et Hydrogen Fuel Block : ME 0 et ME 10 enregistrés.

### Job ajouté (#191) — `CreateStepProcessorTest`, SDE golden

Projet **FC4** : Fernite Carbide racine 4 runs (40 000, profondeur 0, ME 0) ; Fernite Alloy 2 runs (400, profondeur 1) ; Hydrogen Fuel Block 1 run (30 = 20 + 10, profondeur 2). Projet **FC10** : Fernite Carbide racine 10 runs (100 000) ; Fernite Alloy en groupe `fernite-alloy` 3 + 2 runs (600, 400, total 5) ; Hydrogen Fuel Block 2 runs (75 = 50 + 15 + 10). Les deux projets sont stables (un recalcul ne change rien).

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| A-E1 | FC4, `stepId` = Fernite Alloy, 3 runs | Alloy `[2, 400, false, 0, 5]`, ajouté `[3, 3, false, 1, 5]` ; Hydrogen Fuel Block `[1, 30]` | Alloy `[2, 400, true, 0, 5]`, ajouté `[3, 600, true, 1, 5]` ; Hydrogen Fuel Block `[2, 45]` (20 + 10 + 15) ; réponse `newStep` runs 3 index 1, `updatedStep` runs 2 index 0 |
| A-E2 | FC4, `stepId` = Fernite Carbide (racine), 2 runs | Fernite Carbide `[4, 40 000, false, 0, 6]`, ajouté `[2, 2, false, 1, 6]` ; Alloy `[2, 400]` ; Hydrogen Fuel Block `[1, 30]` | Fernite Carbide `[4, 40 000, false, 0, 6]`, ajouté `[2, 20 000, false, 1, 6]` (racine, A6) ; Alloy `[3, 600]` (400 + 200) ; Hydrogen Fuel Block `[2, 45]` (20 + 10 + 15) |
| A-E3 | FC10, `splitGroupId` `fernite-alloy`, 4 runs | `[3, 600, false, 0, 5]`, `[2, 400, false, 1, 5]`, ajouté `[4, 4, false, 2, 5]` ; Hydrogen Fuel Block `[2, 75]` | `[3, 600, true, 0, 9]`, `[2, 400, true, 1, 9]`, ajouté `[4, 800, true, 2, 9]` ; Hydrogen Fuel Block `[3, 95]` (50 + 15 + 10 + 20) |
| A-E4 | Chemin de l'interface : FC10, `stepId` = job 0 d'Alloy (3 runs), 4 runs | job 0 dans un groupe neuf `[3, 600, false, 0, 7]` avec l'ajouté `[4, 4, false, 1, 7]` ; job 1 resté dans `fernite-alloy` `[2, 400, false, 1, 5]` | comme A-E3, les trois jobs dans `fernite-alloy` ; réponse `newStep` runs 4 index 2, `updatedStep` runs 3 index 0 |
| A-E5 | A-E3 puis PATCH `runsFixed: false` sur un job (`UpdateStepProcessor`, R13 de #131) | — | `[2, 400, false, 0, 5]`, `[2, 400, false, 1, 5]`, `[1, 200, false, 2, 5]` ; Hydrogen Fuel Block `[2, 75]` (50 + 10 + 10 + 5) |
| A-E6 | FC10, `stepId` = job 0 d'Alloy, 4 runs, l'enfant Hydrogen Fuel Block a un blueprint sans produit dans le SDE (`BLUEPRINT_MISSING_FROM_THE_SDE`) | 201, rien recalculé | 400 `Product of blueprint 999999999 missing from the SDE` ; aucun `flush` (base inchangée : deux jobs d'Alloy non fixés, aucun job ajouté) |
| A-E7 | `stepId` d'une étape dont le blueprint n'a pas de produit pour son activité | 201, quantité = runs | 400 `Product of blueprint N missing from the SDE` ; aucun job ajouté, aucun `flush` |

### Job ajouté (#191) — fixtures actuelles de `CreateStepProcessorTest` (dépôts doublés, aucun consommateur)

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| A-U1 | `heliumFuelBlockStep('manufacturing', runs: 7, quantity: 280)`, `stepEntered(runs: 3)` (test `…CurrentlyCountsItsRunsAsQuantityIssue191`) | ajouté `[3, 3]` | ajouté runs 3, quantité 120 (40 par run), non fixé (aucun besoin) ; étape existante `[7, 280, 0, 10]` |
| A-U2 | `ferniteCarbideSplitGroup()`, `splitGroupEntered(…, runs: 4)` (test `…KeepsTheTotalGroupRunsIssue191`) | `[[6, 60 000, 0, 12], [6, 60 000, 1, 12], [4, 4, 2, 12]]` (`[runs, quantity, splitIndex, totalGroupRuns]`) | `[[6, 60 000, 0, 16], [6, 60 000, 1, 16], [4, 40 000, 2, 16]]` |
| A-U3 | `heliumFuelBlockStep('copy', runs: 7, quantity: 7)`, 3 runs | `[3, 3]` | inchangé (une copie par run) ; garde-fou |
| A-U4 | `ferniteCarbideSplitGroup()` (réactions ME 4), ajout de 4 runs : champs copiés | `[…, 'reaction', 1, 4, 4, 12, 'Tatara']` | `[…, 'reaction', 1, 4, 0, 12, 'Tatara']` (ME 0, M3) |

### ME d'une réaction (#190) — `UpdateStepProcessorTest` et `CreateStepProcessorTest`, SDE golden

Projet **FC20** : Fernite Carbide racine 20 runs (200 000), Fernite Alloy 10 runs (2 000), Hydrogen Fuel Block 4 runs (150 = 100 + 50). **FC20-ME10** : même projet calculé avec ME 10 enregistré sur Fernite Carbide (donnée ancienne) : Alloy 9 runs (1 800 = 100 × 20 × 0,9), Hydrogen Fuel Block 4 runs (135 = 90 + 45).

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| M-E1 | FC20, PATCH Fernite Carbide `meLevel: 10` | 200, liste : Fernite Carbide ME 10, Alloy `[9, 1 800]`, Hydrogen Fuel Block `[4, 135]` | 400 `Cannot set a material efficiency on a reaction step` ; ME 0, Alloy `[10, 2 000]`, Hydrogen Fuel Block `[4, 150]` ; aucun `flush` |
| M-E2 | FC20, PATCH Fernite Carbide `{meLevel: 10, runs: 25}` | runs 25 et ME 10 appliqués | 400 ; runs 20, ME 0 ; aucun `flush` |
| M-E3 | FC20-ME10, PATCH Fernite Carbide `meLevel: 0` | 200, ME 0, Alloy `[10, 2 000]`, Hydrogen Fuel Block `[4, 150]` | inchangé (garde-fou) |
| M-E4 | FC20, PATCH Fernite Alloy `teLevel: 10` | 200, TE 10 | inchangé (TE hors périmètre) |
| M-E5 | Fabrication : `testMeLevelEnteredOnTheRootStepRecalculatesTheQuantityOfItsMaterialStep` (Quantum Microprocessor ME 8) | Titanium Carbide 501 | inchangé (garde-fou) |
| M-E6 | POST `typeEntered(FERNITE_CARBIDE, runs: 3)`, `meLevel` 10, `teLevel` 4 | ME 10, TE 4, quantité 30 000 | ME 0, TE 4, quantité 30 000 |
| M-E7 | POST Helium Fuel Block, `meLevel` 10, `teLevel` 20 (`testStepAddedWithMeAndTeLevelsKeepsThem…`) | ME 10 | inchangé (garde-fou) |

### Commande `app:industry:reset-reaction-me-levels` (#190, M5) — test du service sur le SDE golden et `CommandTester`

| # | Cas | Attendu |
|---|---|---|
| K1 | Un projet actif FC20-ME10 | ME 0 sur Fernite Carbide ; Alloy `[10, 2 000]`, Hydrogen Fuel Block `[4, 150]` ; ligne `… (20 runs): 1 reaction steps ME -> 0, 2 steps recalculated` ; tableau `Changed 1`, `Failed 0`, `Total 1` ; code `SUCCESS` |
| K2 | K1 avec `--dry-run` | même ligne, rien d'écrit (ME 10 et quantités en base inchangés), `Dry run - no changes were saved`, code `SUCCESS` |
| K3 | Le même projet au statut `completed` ou `archived`, et un projet actif dont toutes les réactions sont à ME 0 | aucun sélectionné : `No project to reset`, code `SUCCESS` |
| K4 | Deux projets actifs à réaction ME 10, le premier a un enfant dont le blueprint n'a pas de produit dans le SDE | premier `failed: Product of blueprint 999999999 missing from the SDE`, rien d'écrit pour lui ; second `changed` ; code `FAILURE` |

### Structure et bornes (#287)

Utilisateur U1 ; projet J1 de U1 ; étape S1 de J1 (fabrication, structure C1) ; C1 et C3 configurations de U1 ; C2 configuration de U2. `UpdateStepProcessorTest` (dépôt de structures doublé) et `UpdateStepInputValidationTest` / `CreateStepInputValidationTest`.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| S-E1 | PATCH S1 `structureConfigId` = C3 | 200, C3 rattachée, cascade | inchangé (garde-fou) |
| S-E2 | PATCH S1 `structureConfigId` = C2 (U2) | 200, C2 rattachée | 404 `Structure not found` ; S1 garde C1 ; aucun `flush` |
| S-E3 | PATCH S1 `structureConfigId` = UUID valide inconnu | 200, structure retirée (null) | 404 `Structure not found` ; S1 garde C1 ; aucun `flush` |
| S-E4 | PATCH S1 `{meLevel: 8, structureConfigId: C2}` | ME 8 et C2 | 404 ; ME inchangé ; aucun `flush` |
| S-E5 | PATCH S1 `structureConfigId` = C1 (déjà rattachée) | 200, aucune cascade | inchangé |
| V1 | `CreateStepInput::$runs` = 100 000 | aucune violation | inchangé |
| V2 | `CreateStepInput::$runs` = 100 001 | aucune violation | une violation sur `runs` |
| V3 | `UpdateStepInput::$runs` = 100 001 ; = 100 000 ; `null` | aucune violation | violation sur `runs` ; aucune ; aucune |
| V4 | POST `splitGroupId` = `g-1` (pas un UUID) | 404 `Split group not found`, aucun `flush` | inchangé (garde-fou, caduc) |
| V5 | `CreateStepInput::$runs` = 0 | violation (`Positive`) | violation sur `runs` (message de `Range`) |

### Front (Vitest)

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| F1 | `addChildJob(projectId, null, stepId, 4)` sur le projet courant | store patché à la main (`updatedStep` remplacé, `newStep` poussé), aucun GET | un POST puis un `GET /industry/projects/{id}` ; le store reflète la réponse du GET ; valeur rendue = `newStep` |
| F2 | `addChildJob(projectId, groupId, null, 4)` sur le projet courant | `step` poussé | POST puis GET ; valeur rendue = le job ajouté |
| F3 | `addChildJob` sur un projet qui n'est pas le courant | aucun GET | aucun GET (garde-fou) |
| F4 | `StepTree` avec une étape de réaction et une étape de fabrication, non lecture seule | deux champs ME (`aria-label` ME) | un seul champ ME (fabrication) ; deux champs TE |
| F5 | `StepTree` en lecture seule ou étape liée à l'ESI, réaction | libellé `ME4` | aucun libellé ME ; `TE…` affiché |

## Cas limites

- **Ajout sur un groupe à jobs fixés et non fixés** : tous deviennent fixés (A6), total = somme. Un besoin qui monte ensuite n'est plus suivi (G6 de #185) ; le bouton « Calcul automatique » existant (G5, R13 de #131) rend le groupe au besoin et peut supprimer le job ajouté s'il est en trop et non engagé (A-E5 n'en supprime aucun : 5 runs pour 3 jobs).
- **Besoin qui baisse après un ajout** : jobs fixés gardés (R2 de #131), aucun job supprimé.
- **Racine** : runs ajoutés gardés jusqu'à la régénération, qui absorbe le job ajouté dans le job du plan (`applyPlanToExistingSteps()`, `absorb()`) : comportement actuel d'une saisie de runs sur la racine.
- **Copie** : quantité = runs, hors cascade ; ses runs suivent la fabrication à la régénération (#235).
- **Étape de référence liée à un job ESI, achetée, en stock** : rien n'est déplacé ; le job ajouté part vide (A3).
- **Plusieurs étapes d'un même produit sans groupe commun** (groupes coupés en deux par l'ancien chemin de l'interface, étapes hors plan) : `stepId` vise le groupe de l'étape désignée seulement ; la cascade traite toutes les étapes du produit ensemble (`productionStepsByProduct()`), comme aujourd'hui. Réparation des groupes déjà coupés : hors périmètre (Impact prod).
- **Réaction à ME enregistré non nul hors projet actif** : gardé (M5 ne les sélectionne pas) ; un PATCH `meLevel: 0` le corrige.
- **ME 0 envoyé sur une réaction à ME 0** : aucune écriture, aucune cascade (règle actuelle : ME inchangé).
- **Structure supprimée** (`isDeleted`) de l'utilisateur : rattachable (même règle que `StructureConfigProvider`, qui ne filtre que le propriétaire).
- **Intermédiaire dont le besoin dépasse 100 000 runs** (cascade d'un gros projet) : la cascade l'écrit sans borne ; seule une saisie au-delà est refusée (S3).

## Hors périmètre

- Cascade après un ajout depuis un `typeId` (A11). Écart relevé : un produit ajouté depuis un type qui est aussi un intermédiaire du projet est mêlé par la cascade aux étapes de cet intermédiaire (`productionStepsByProduct()` regroupe par produit) : ses runs sont repris au prorata du besoin, voire supprimés comme job en trop (#131). À ouvrir en issue.
- TE d'une étape de réaction (accepté par le PATCH, sans effet sur les matériaux ; forcé à 0 à la génération). À ouvrir en issue si voulu.
- ME d'un projet dont le produit est une réaction (enregistré tel que saisi, ignoré par le plan), et ME affiché dans la liste de produits de la modale de création (`Industry.vue`, `ME{{ product.meLevel }}`).
- Retrait explicite de la structure d'une étape (aucun consommateur ; un UUID inconnu ne la retire plus).
- Réparation des produits coupés en plusieurs `splitGroupId` par l'ancien chemin de l'interface, et des jobs racine ajoutés avec une quantité égale aux runs (voir Impact prod).
- Suppression d'une étape ou d'un job (`DeleteStepProcessor`) : #202. Fusion qui ne reporte pas le stock : noté par #131.
- Attribut `max` du champ de runs « Add job » (`StepTree.vue`) : le 422 s'affiche dans le bandeau existant.

## Tests existants appelés à casser

- `CreateStepProcessorTest::testStepAddedToASingleStepCurrentlyCountsItsRunsAsQuantityIssue191` (groupe `issue-191`) : devient A-U1, renommé sans `Currently…Issue191` (par exemple `testJobAddedToASingleStepCountsTheUnitsPerRunOfItsBlueprint`), groupe `issue-191` gardé.
- `CreateStepProcessorTest::testStepAddedToASplitGroupCurrentlyCountsItsRunsAsQuantityAndKeepsTheTotalGroupRunsIssue191` (groupe `issue-191`) : devient A-U2, renommé.
- `CreateStepProcessorTest::testStepAddedToASplitGroupComesAfterItsLastJobAndCopiesItsFirstJob` : ME copié 4 → 0 (A-U4) ; le reste inchangé.
- Tous les tests de `CreateStepProcessorTest` qui construisent le processor (`processor()`, l. 481-507), dont les neuf tests `issue-280` : le constructeur reçoit `IndustryStepCalculator` (A6, A7) ; valeurs inchangées hors des trois tests ci-dessus. Les tests des branches `stepId` et `splitGroupId` qui passent `createStub(IndustryActivityProductRepository::class)` (`testStepAddedToASingleStepFormsWithItASplitGroupOfTheirTotalRuns`, `testStepAddedToASplitGroupComesAfterItsLastJobAndCopiesItsFirstJob`, `testStepAddedWithAStepAlsoEnteringATypeAndASplitGroupJoinsTheStep`, les deux tests `issue-191`) doivent recevoir un dépôt qui connaît le blueprint (`productRepositoryWith($this->heliumFuelBlockBlueprint())`, `productRepositoryWith($this->ferniteCarbideFormula())`), sinon A4 répond 400 ; la copie n'en a pas besoin. La fixture `ferniteCarbideSplitGroup()` contient une étape sans blueprint ni produit (`OTHER_SPLIT_GROUP`, l. 399) : un calculateur réel ne peut pas la parcourir, la compléter ou doubler le calculateur dans ces tests. Les tests `issue-280` de la branche `typeId` n'appellent pas la cascade (A11).
- `UpdateStepProcessorTest::testEditedJobRemovedAsASurplusJobWhileNoOtherStepChangesIsNotInTheResponse` (#131) : déclenche la cascade par `meLevel: 2` sur un job de Fernite Alloy, réaction → 400 (M1). À réécrire avec un autre déclencheur sans autre changement, par exemple le même job enregistré à ME 2 (donnée ancienne) et `meLevel: 0` envoyé (M-E3) ; attendus inchangés.
- Tests `issue-280` de la branche `typeId` : `testStepAddedForAReactedTypeIsMadeByItsReactionFormulaAndCountsItsUnitsPerRun` et `testStepAddedWithoutMeAndTeLevelsHasLevelsZero` restent verts (ME non envoyé ou non vérifié) ; M-E6 s'ajoute.

Restent verts (garde-fous) : `testStepAddedToASingleStepFormsWithItASplitGroupOfTheirTotalRuns` (runs et totaux 7 + 3 = 10 inchangés), `testStepAddedToASingleCopyStep…` (A-U3), `testStepAddedToAnUnknownSplitGroupIsRefusedAsSplitGroupNotFound`, `testStepAddedWithAStepAlsoEnteringATypeAndASplitGroupJoinsTheStep`, les tests `issue-200` (UUID), `UpdateStepInputValidationTest` (structure non UUID → 422), les tests `UpdateStepProcessorTest` et `UpdateStepFixedRunsTest` qui saisissent un ME sur une fabrication, `IndustryProjectFactoryRegenerationTest::testRegenerationGivesAReactionStepNoMeNorTeAndKeepsItsStructure`, `frontend/tests/components/industry/timeEfficiencyInputStep.spec.ts` (fabrication), `frontend/tests/stores/stepsSurplusJobs.spec.ts`.

## Notes de mise en œuvre (non normatives)

- `CreateStepProcessor` reçoit `IndustryStepCalculator` ; `needRunsOf()` est appelé sur l'étape de référence avant l'ajout (A6), puis `recalculateStepQuantities()` après. Le calculateur fait son propre `flush` quand des étapes changent : le processor attrape `BlueprintProductMissingFromSdeException` autour de `outputPerRunOf()` et de la cascade (précédent `UpdateStepProcessor::process()`), et ne `flush` qu'après.
- A1 réutilise la branche `splitGroupId` : avec `stepId` d'un job découpé, le groupe est celui de `getSplitGroupId()` ; le « premier job trouvé » de la branche actuelle (l. 116-125) devient le job de plus petit `splitIndex` quand l'étape de référence vient de `splitGroupId`.
- M1 et S1 doivent être vérifiés avant les écritures en mémoire de `update()` (l. 83-125) ou, à défaut, garantir qu'aucun `flush` n'a lieu : un refus levé après `setPurchased()` reste sans effet en base, mais la règle demandée est « aucun autre champ appliqué ».
- Activité d'une étape : `getActivityType() === 'reaction'` ; activité du producteur d'un type : `IndustryActivityType::Reaction->value` (déjà lu l. 168-171).
- S3 : `#[Assert\Range(min: 1, max: CreateProjectInput::MAXIMUM_PROJECT_RUNS)]` remplace `#[Assert\Positive]` sur les deux champs (`UpdateStepInput::$runs` reste nullable).
- Carte du module (`docs/modules/industry.md`) : ajouter `CreateStepProcessor` aux appelants d'`IndustryStepCalculator` et la commande M5 à la liste des commandes.

## Décisions prises en autonomie

- **A5 — Les runs ajoutés s'ajoutent au groupe (surproduction)**, comme le prévoyaient le code d'origine (`totalGroupRuns = runs existants + runs saisis`) et le test de l'issue (« groupe de 20 runs + 5 → `totalGroupRuns` 25 »). Alternative écartée : prendre les runs du job ajouté sur le besoin (total inchangé, les autres jobs réduits), qui ferait de « Add job » un second découpage et contredirait le total de l'issue.
- **A6 — Tous les jobs du groupe fixés** : sans cela, la cascade de A7 (ou la suivante) répartirait aussitôt le besoin sur tous les jobs non fixés et effacerait l'ajout ; c'est la règle déjà choisie pour la surproduction d'une saisie (G4 de #185), avec son bouton de retour au calcul automatique. Racine et copie suivent leur règle actuelle (non fixées).
- **A1 — `stepId` d'un job découpé rejoint son groupe** : c'est le seul chemin de l'interface, qui coupe aujourd'hui le groupe en deux. Corrigé côté API pour garder le front inchangé (il continue d'envoyer `children[0].id`).
- **A7 — Cascade complète plutôt que `SplitGroupMaterialNeeds::applyChangeOfJobs()`** : l'ajout change le besoin des enfants de plusieurs runs, qui doit descendre aux petits-enfants ; `applyChangeOfJobs()` n'applique le changement qu'aux enfants directs (il sert au découpage et à la fusion, où seul l'arrondi change). La cascade est celle du PATCH et applique déjà #73, #252 et #131.
- **A9/A10 — Réponse inchangée, rechargement du projet** : même choix que #131 R16 et que le découpage et la fusion ; coût, un GET de plus par ajout.
- **M1 — 400 sur le PATCH** plutôt qu'une normalisation silencieuse : l'étape et son activité sont connues, le front n'enverra plus ce champ (M4), un client qui l'envoie apprend que la valeur n'a pas de sens ; message dans le style des refus voisins (`Cannot mark root products as purchased`).
- **M2 — ME 0 sans refus sur le POST depuis un type** : même règle que la création d'un projet sur une réaction, et un refus casserait la création d'un projet multi-produits à mi-chemin (projet déjà créé, `Industry.vue` l. 269-272) pour un ME saisi sans savoir que le produit est une réaction.
- **M5 — Normaliser les données existantes par une commande qui recalcule** plutôt qu'une migration SQL : un `UPDATE` du ME laisserait les quantités des enfants calculées avec l'ancien ME jusqu'au prochain recalcul (liste d'achats trop courte). Projets actifs seulement, comme #278 et #284.
- **M6 — Pas de règle « réaction = ME 0 » dans les calculateurs** : une seule source (le ME enregistré), gardée vraie à l'entrée et par M5 ; sinon l'affichage dirait ME 10 et le calcul ME 0.
- **M4 — Aucune maquette** : le changement retire un champ et un libellé sur les lignes de réaction, sans élément nouveau.
- **S1 — 404 identique pour inconnue et étrangère**, règle et message de `StructureConfigProvider` ; contrôle avant toute écriture.
- **S3 — Borne 100 000 = runs d'un projet** (`CreateProjectInput::MAXIMUM_PROJECT_RUNS`, même valeur que Group Industry), appliquée aussi au PATCH : même débordement de `runs × unités par run`. Alternative écartée : contrôler `runs × unités par run ≤ 2 147 483 647` produit par produit, plus juste mais plus de code pour un cas que la borne couvre.
- **Glossaire non modifié dans ce lot de spec** : comme pour #131, les termes sont reportés à l'implémentation (section Glossaire), pour que le glossaire ne décrive pas un comportement pas encore livré.

## Impact prod

À faire sur la base de prod en lecture seule avant le déploiement, puis rappeler les commandes après.

- **ME des réactions remis à 0 et étapes recalculées (M5)** : montants visibles changés sur les projets actifs concernés (plus de matériaux pour les enfants d'une réaction : liste d'achats, coût estimé, marges ; M-E1 : 200 Fernite Alloy de plus pour 20 runs). Lancer `docker compose exec app php bin/console app:industry:reset-reaction-me-levels --dry-run`, puis sans `--dry-run`, **avant** `app:industry:regenerate-multi-unit-steps` (#284), qui saute un projet dont la régénération changerait un ME de réaction (G4). La cascade peut aussi supprimer des jobs en trop restés d'avant #131 (même effet qu'un PATCH).

```sql
SELECT s.project_id, p.status, COUNT(*) AS reaction_steps, MAX(s.me_level) AS max_me
FROM industry_project_steps s
JOIN industry_projects p ON p.id = s.project_id
WHERE s.activity_type = 'reaction' AND s.me_level <> 0
GROUP BY s.project_id, p.status;
```

- **Jobs ajoutés existants (#191)** : aucune migration. Un job ajouté hors racine avec une quantité égale à ses runs est corrigé au prochain recalcul de son projet (la cascade réécrit les quantités des groupes non racine ; ses runs y sont répartis au prorata, il n'est pas fixé) ; un job ajouté à une racine garde sa quantité fausse jusqu'à la régénération, qui l'absorbe. Comptage :

```sql
SELECT s.project_id, s.product_type_id, s.depth, s.split_group_id, s.split_index, s.runs, s.quantity, a.quantity AS units_per_run
FROM industry_project_steps s
JOIN sde_industry_activity_products a
  ON a.type_id = s.blueprint_type_id
 AND a.activity_id = CASE s.activity_type WHEN 'reaction' THEN 11 ELSE 1 END
WHERE s.activity_type <> 'copy' AND s.split_group_id IS NOT NULL AND a.quantity > 1 AND s.quantity = s.runs;
```

- **Produits coupés en plusieurs groupes par l'ancien chemin de l'interface** : non réparés par ce lot. Si le comptage n'est pas vide, ouvrir une issue (fusion manuelle possible par groupe) :

```sql
SELECT project_id, product_type_id, activity_type, COUNT(DISTINCT split_group_id) AS split_groups
FROM industry_project_steps
WHERE split_group_id IS NOT NULL
GROUP BY project_id, product_type_id, activity_type
HAVING COUNT(DISTINCT split_group_id) > 1;
```

- **Structures d'un autre utilisateur déjà rattachées (#287)** : aucune donnée modifiée ; attendu 0 (le front n'envoie jamais ce champ). Sinon, ouvrir une issue :

```sql
SELECT COUNT(*)
FROM industry_project_steps s
JOIN industry_projects p ON p.id = s.project_id
JOIN industry_structure_configs c ON c.id = s.structure_config_id
WHERE c.user_id <> p.user_id;
```

- **Borne des runs (S3)** : aucune donnée modifiée ; seules les saisies au-delà de 100 000 sont refusées. Vérification de la justification de la borne et des étapes déjà au-delà :

```sql
SELECT MAX(quantity) AS max_units_per_run FROM sde_industry_activity_products WHERE activity_id IN (1, 11);
SELECT COUNT(*) FROM industry_project_steps WHERE runs > 100000;
```

  Si `max_units_per_run` dépasse 21 474, 100 000 runs peuvent encore déborder la colonne `quantity` : [TO CONFIRM: valeur en prod].

## Glossaire

À reporter dans [`docs/glossary.md`](../glossary.md) à l'implémentation :

- **Job ajouté** (Added job), nouveau : job ajouté à la main à une étape ou à un groupe découpé (`stepId` ou `splitGroupId` de l'ajout d'étape) ; ses runs s'ajoutent à ceux du groupe (surproduction), quantité = runs × unités par run ; hors racine et copie, tous les jobs du groupe sont fixés ; matériaux recalculés job par job (spec #191-190-287). Symboles : `CreateStepProcessor`, `IndustryStepCalculator::recalculateStepQuantities()`.
- **Surproduction**, précision : « ou ajout d'un job à un groupe (spec #191-190-287) ».
- **ME**, précision : « Une étape de réaction a toujours ME 0 : un ME non nul est refusé (400) sur l'étape, ignoré à l'ajout d'une étape depuis un type (spec #191-190-287). »
- **Réaction**, symbole : ajouter la règle des étapes (`UpdateStepProcessor`, `CreateStepProcessor`, commande `app:industry:reset-reaction-me-levels`).
- **Producteur**, précision : « Un job ajouté à une étape ou à un groupe prend les unités par run du blueprint de l'étape (`SplitGroupMaterials::outputPerRunOf()`) (spec #191-190-287). »
