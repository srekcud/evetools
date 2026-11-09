# Todo — setup agentique

Brief : `~/Downloads/evetools-agentic-setup.md`. Plan Phase 0 : `~/.claude/plans/parsed-pondering-moth.md`.

## Phase 0 — Fondations

- [x] README aligné (Symfony 7.4 LTS, API Platform 4.x, PHP 8.5)
- [x] CLAUDE.md : roadmap Symfony 8.4 LTS, liens docs, règles glossaire + pas de défaut plausible
- [x] ADR 0001 à 0007 (`docs/adr/`)
- [x] Glossaire (`docs/glossary.md`)
- [x] `.claude/settings.json` + hook `tdd-guard` (testé)
- [x] Skills Phase 1 (audit, characterize, mutation, triage, spec, tdd-red, tdd-green, review) + esi-api au format SKILL.md
- [x] Agents Phase 1 (auditor, test-skeptic, test-writer, implementer, 4 reviewers, triager)
- [x] Infection + deptrac en mode rapport, pcov via `Dockerfile.dev`
- [x] Baseline (`docs/quality/baseline.md`), MSI global 13 % / covered 57 %
- [ ] 🚦 Gate : validation glossaire, ADR, baseline par Sylvain

## Actions Sylvain

- [x] Bilan de nuit relu (2026-10-07). Décisions :
  - `main` poussé (déjà à jour sur origin) ; issues #5, #11, #14, #29, #35, #61 fermées automatiquement
  - PR #62 (ESI retries) ouverte
  - PR Group Industry : **un admin peut retirer un membre sans supprimer ses contributions validées ou en cours** → retrait doux (statut `Removed`), rework en TDD avant ouverture
  - #36 : la structure supprimée disparaît de la liste corpo de son propriétaire ; réimport par action explicite (`unhideForUser`) ; les autres membres la voient toujours
  - `getDivisions()` : propager l'erreur ESI

- [ ] **Prod : vérifier que `MERCURE_JWT_SECRET` est défini et différent du défaut versionné** (cf. `security-private.md` S3). Sur le serveur, sans afficher la valeur :
  ```
  docker compose -f docker-compose.yaml -f docker-compose.prod.yml exec app printenv MERCURE_JWT_SECRET | sha256sum
  ```
  À comparer avec le hash de la valeur par défaut de `docker-compose.yaml:38`. Si c'est la même valeur ou si elle est vide : définir un secret fort dans le `.env` de prod, redéployer, et considérer tous les JWT Mercure émis jusque-là comme compromis.

## Suivi des correctifs (2026-10-07, journée)

- Fusionnés et poussés : #5, #11, #14 (+ `getDivisions` `85b454a`), #29, #35, #36 (`249346f`), #61 ; infra d'intégration `21a9fea`
- PR ouvertes : #62 (ESI retries, #24), #63 (Group Industry : retrait doux #10 + marge #9 + `myStatus`)
- Fusionnés aussi : #4 + #58 matcher (`80afced`, MSI 92→95 %) ; #60 fermée (prémisse fausse : activité 9 = réactions ESI)
- PR ouvertes : #64 (logs worker, #56)
- **Règle Sylvain : 3 lots en parallèle en permanence tant qu'il reste des issues.**
- En cours : I #13 (tests anti-mutants), K #40 + #31 (scheduler), L #28 (marché structure)
- File d'attente : S2 IDOR link-job (privé, commit neutre) → #41 ETag → #32 N+1 projets → #55 libellé containers → #12 sync « terminée » malgré l'échec → #34 purges (PR) → #53 RGPD (PR) → #37 wallet inconnu → #38 N+1 Group Industry
- Exclus pour l'instant : noyau de calcul (réécriture prévue : #2, #6-#8, #15-#22), décisions en attente (#3, #59), features (#47-#52), doc (agent doc à créer)
- Constats en attente de création d'issue : voir `issues-proposals.md` § « Nouveaux constats »

## Phase 1 — Blinder l'existant (ordre du brief, plan : `~/.claude/plans/parsed-pondering-moth.md`)

- [x] Prérequis : `gh` installé + authentifié (Sylvain)
- [x] Étape 1 — Audits vague 1 : ESI, Industry (+ table des appelants B1), duplication prix/coûts, sécurité/scoping, test-skeptic Industry
- [x] Étape 1 — Audits vague 2 : Messenger, Scheduler, Doctrine, code mort
- [x] Consolidation → `.claude/tasks/issues-proposals.md` (+ B1, I2, U3, F4-F7, tests Mercure rouges)
- [x] 🚦 Gate 1 : liste validée, 16 labels et 52 issues créés (#2 à #53 ; B1 = #2)
- [ ] Étape 2 — Caractérisation : JobMatcher, TreeService, ProjectFactory, CalculationService, StepCalculator, BonusService, EsiCostIndex, ProfitMargin, BuyVsBuild
- [x] Caractérisation `IndustryJobMatcher` : 41 tests, 101/102 lignes, MSI 92 % (covered 93 %), 7 mutants restants jugés équivalents. Avec le vrai calculateur : la réduction d'une étape enfant est annulée par le recalcul, et en split la redistribution au prorata déplace des runs vers une sœur sans job (#3)
- [ ] **Nouveau plan validé (2026-10-06)** : réécrire le noyau de calcul Industry (strangler), garder le reste. Ordre :
  1. [x] Code mort (#44, #45, #46 partiel) — `3f441e0`, `412a24f` — en cours (backend + frontend en parallèle). Exclus en attente de décision : endpoints Ansiblex, commandes console, colonnes jamais lues (migration), `hiddenByUsers` (#36), barèmes line rental, `submitAutoDetected`, fichiers racine, `CLAUDE.md` (agent doc).
  2. [x] Infra de tests d'intégration — `21a9fea` (base `eve_app_test`, dama, `make test-db`, `make test-integration`)
  3. [ ] Golden tests Ravworks + formules Fuzzwork
  4. [ ] Benchmark ciblé calculs (Ravworks, EVE Forge, Fuzzwork)
  5. [ ] Spec + ADR du noyau Industry (VO Runs/Quantity/Isk, une formule matériaux, une règle de bonus, un coût d'installation, prix absent explicite) → ADR-0008 limité à Industry + ADR de dérogation au brief
  6. [ ] Noyau en TDD (`src/Industry/Domain/`)
  7. [ ] Migration des appelants un par un ; caractérisation limitée aux sorties API des services de calcul remplacés
- [ ] **Infra de tests d'intégration** (avant `IndustryTreeService` et les golden tests) : `.env.test`, base de test dédiée, isolation (ex. `dama/doctrine-test-bundle`), premiers tests repository : `CachedIndustryJobRepository::findManufacturingJobsByBlueprint` (ordre par startDate, filtre d'activité #60, absence de filtre de statut #4), exclusion des autres projets (DQL du matcher)
- [ ] Étape 3 — Golden tests Ravworks (dont un produit multi-unités par run)
- [ ] Étape 4 — Mutation + cliquet `minMsi` par périmètre
- [ ] 🚦 Gate 2 : couverture + MSI sur les services critiques
- [ ] Étape 5 — failles de sécurité S1-S6 (pas de hotfix, usage confidentiel), puis B1 en TDD (/spec → /tdd-red → /tdd-green), puis autres bugs de justesse

## Agent `doc-writer` (à créer après la caractérisation)

Cadrage validé par Sylvain (2026-10-06) :
- outils : Read, Grep, Glob, Edit, Write ; Bash seulement pour `git log` / `git diff` en lecture ;
- périmètre : doc développeur **et** utilisateur : `docs/`, `README.md`, `CONTRIBUTING.md`, `.claude/skills/*/SKILL.md` (référence), guide utilisateur ;
- `CLAUDE.md` : **proposition de diff uniquement**, jamais d'écriture directe ;
- règles : chaque affirmation est vérifiée dans le code (`fichier:ligne` dans le rapport), termes du glossaire, ADR immuables (une nouvelle ADR remplace l'ancienne), anonymisation, aucune attribution IA ;
- skills associées : `/adr <décision>`, `/doc-sync` (doc vs code) ;
- **templates d'issues GitHub** (`.github/ISSUE_TEMPLATE/`) : bug, investigation, ux, feature, tech-debt, au format Constat / Preuve / Impact / Test qui le prouverait, avec les labels type et module, un rappel d'anonymisation, et un `config.yml` qui renvoie les failles de sécurité vers un canal privé (Security Advisory) ;
- premiers travaux : #57 `CONTRIBUTING.md`, #31 table du scheduler (diff `CLAUDE.md`), #48 guide de configuration des structures (U3), correction de la skill `esi-api` (pagination, #11).

## Glossaire — points à vérifier

- [ ] **EIV** : vérifier dans le code si l'EIV est calculée sur les matériaux de base (avant ME, définition du jeu) ou après ME. Appelants de `EsiCostIndexService::calculateEiv()` / `calculateEivFromPrices()` et `InventionService::calculateBlueprintEiv()`. Corriger le glossaire ou ouvrir une issue selon le résultat.
- [ ] **Payout** : confirmer la formule `coûts apportés × (1 + marge %)` (`GroupIndustryDistributionService`, `totalCosts × marginPercent`) contre `corp-industry-plan.md`. À trancher au plus tard en Phase 4.

## Phase 3 — Pistes ADR-0008 (issues du scan du projet de référence)

Structure :
- [ ] Niveau haut aligné : `src/<Contexte>/`, `src/Shared/` pour le transverse, une entrée `mappings` Doctrine par contexte
- [ ] Niveau interne divergent : Domain / Application / Infrastructure seulement dans Industry, CorpIndustry, Pricing ; contextes ESI CRUD en découpage plat (`ApiResource/State/Entity/Repository/Service`)
- [ ] Migration progressive : anciens dossiers à plat et contextes coexistent pendant l'extraction (strangler)

Garde-fous à conserver (absents du projet de référence, qui montre déjà des dépendances croisées dans les deux sens) :
- [ ] deptrac strict : un contexte n'importe jamais le Domain d'un autre ; les anciens dossiers n'importent pas les nouveaux contextes
- [ ] Repositories du Domain = interfaces (ADR-0003) ; pas d'`EntityManager` ni de repository concret dans les processors
- [ ] Entités du Domain avec invariants protégés (pas de propriétés publiques)
- [ ] Conventions API Platform du CLAUDE.md : `uriVariables` + `Link`, provider sur DELETE, `EmptyInput` (pas `input: false`)
- [ ] Pas de `?? 0` sur donnée métier

À adopter :
- [ ] Rector (sets qualité + `UP_TO_PHP_85`), php-cs-fixer, PHPStan étendu à `tests/`, hook pre-commit (type GrumPHP)
- [ ] DTO de PATCH aux propriétés non initialisées pour distinguer « absent » de « null » (merge-patch RFC 7396)
- [ ] `openapi: new Operation(summary, description)` et `name:` explicites sur chaque opération
- [ ] Tests `tests/Api/<Contexte>/` de bout en bout avec Foundry pour les contextes CRUD
- [ ] Cliquet de couverture de lignes en CI, en complément du cliquet MSI
- [ ] Garder `final` : rendu possible par les interfaces de repository (moins de mocks de classes concrètes)

Divergences assumées (pas d'action) : Symfony 7.4 LTS (ADR-0007) plutôt que les mineures 8.x ; TDD + MSI (ADR-0004/0005) plutôt qu'une couverture seule.

## Notés pour plus tard

- [ ] Phase 1 : `gh` n'est pas installé sur l'hôte (requis par `/triage`, `/spec`)
- [ ] Phase 1 : 4 tests `MercurePublisherServiceTest` en échec sur main (message de log changé) ; retirer l'exclusion dans `infection.json5` une fois corrigés
- [ ] Phase 2 : `frontend/dist/index.html`, `frontend/tsconfig*.tsbuildinfo`, `frontend/vite.config.js` versionnés malgré le `.gitignore`
- [ ] Phase 2 : en dev, `docker compose build` ne reconstruit que `evetools-app-dev` ; rebuild de l'image app via `docker compose -f docker-compose.yaml build app`

## #53 Suppression de compte — EN PAUSE, décisions Sylvain (rouge prêt : worktree `../evetools-wt-AA`, branche feat/account-deletion)
1. Escalations de l'utilisateur : supprimées (même partagées corp) ou anonymisées ?
2. Membre ayant des contributions dans le projet d'un autre : anonymisé (`user_id` nullable) ou réutiliser le statut `Removed` de la PR #63 ?
3. Ventes, visibilité corp, structures corp : passer de CASCADE à SET NULL (migration, colonnes nullables) ?
4. 409 si l'utilisateur possède un projet collaboratif avec d'autres membres acceptés (sortie : supprimer le projet ; transfert de propriété = autre issue) ?
5. Dépend de la PR #63 (memberships) : à reprendre après sa fusion.

## Moteur Industrie — en attente de Sylvain
- [ ] D11a : vérifier en jeu si une copie de blueprint paie la surcharge SCC de 4 % (détail du coût d'installation d'un job de copie)
- [ ] D12 : partager un plan Ravworks (Hail L 10 runs ME 2 Raitaru nullsec, Fernite Carbide 10 runs Tatara nullsec) avec sa config de structures

## Code mort #44/#45/#46 — décisions de Sylvain (audit du 2026-10-08)
1. Ansiblex : supprimer les 4 opérations `/me/ansiblex*` ou les garder pour l'Intel Map ?
2. `EsiClient::getScalar` (aucun appelant prod) : supprimer ou garder comme API publique ?
3. Colonnes écrites jamais lues (`CachedStructure::resolvedAt`, `IndustryStepJobMatch::plannedStructureName/plannedMaterialBonus`, `CachedIndustryJob::completedDate`, `SharedShoppingList::createdBy`, `CachedWalletTransaction::clientId`) : supprimer (migration) ou garder ?
4. `hiddenByUsers` : supprimer la colonne (masquage = `isDeleted`) ou la brancher ?
5. Barèmes de location de ligne : les brancher dans la valeur d'une contribution `line_rental` ou supprimer endpoint + champs ? Et interdire `estimatedValue` absent/nul hors `material` (aujourd'hui `?? 0.0`, payout nul) ?
6. `submitAutoDetected` : supprimer ou garder ?
7. Commandes console : supprimer les 4 de debug et `app:seed-planetary` ? garder les doublons scheduler ? supprimer `app:seed-rig-definitions` + table `industry_rig_definitions` (jamais lue) ?
8. Fichiers racine `design-*.html`, `design-proposals/`, `docs/industry-pipeline-plan.md` : supprimer ? Déplacer `corp-industry-plan.md` dans `docs/` ?
9. Bonus de rig en double (`RigOptionsProvider` / `IndustryBonusService::getRigOptions`) : unifier maintenant ou attendre `SdeRigBonusReader` (nouveau moteur) ?

## État à la pause (2026-10-08) — worktrees non commités à reprendre
- `../evetools-wt-BV` (chore/remove-dead-code) : lots A-E de code mort FAITS, gates vertes (Infection 82→81 % : méta-cache `syncedAt` d'EsiCostIndexService plus lu — décider : retirer `syncedAt` ou accepter). Mort par cascade à trancher : `CorporationService::getCorporationInfo`, `EveToken::isExpired()`. À commiter (Refs #44 #45 #46).
- `../evetools-wt-BW` (feat/industry-engine-adapters) : tranche 8 du noyau, 27 tests ROUGES prêts (JitaMarketPrices, EsiAdjustedPrices, EsiSystemCostIndices, SdeInventionCatalog) ; phase verte à lancer (deptrac : Infrastructure → Service à autoriser ; alias services.yaml ; quantité fractionnaire arrondie au supérieur à valider).
- `../evetools-wt-BP` (test/industry-domain-mutants) : tests tueurs de mutants du noyau, agent stoppé en cours ; état inconnu, à vérifier.
- `../evetools-wt-AA` (feat/account-deletion, #53) et `../evetools-wt-AC` : anciens, à nettoyer ou reprendre.
- Ensuite : migration de ProfitMarginService vers le noyau (spec §7), puis les autres appelants.

## Reprise (2026-10-09)
- Fusionnés dans main (local, non poussé) : 3a92af4 deps sécurité, ab7e84c adaptateurs moteur, 9c3463e mutants noyau, 2288ebc caractérisation BuyVsBuild, 26ad63a caractérisation Stockpile, e591139 caractérisation ProjectProvider, caractérisation liste d'achats. Suite complète verte (2311).
- CA (fix/contracts-comparison-unknown) : ROUGE, attend validation Sylvain (`incomplete`, `unknownContractIds`). Hash tests : scratchpad ca-tests.sha.
- CC (fix/scanner-unknown-sale-price, #22) : ROUGE, attend validation (`unknownReason = missing_sale_price`, prix/marge null, classé en fin, exclu par le filtre de marge min). À trancher : motif prioritaire si prix de vente ET matériau manquent ; vente en structure sans ordre (hors périmètre).
- CD (test/profit-margin-characterization) : relecture test-skeptic = insuffisant (MSI 76 %, B1 mêlé à 11 tests sur 12, prix uniformes). Renforcement en cours (test-writer). Broker fee sur vente à un buy order : déjà dans #18.
- Relectures test-skeptic : BuyVsBuild renforcé et fusionné (eb3a3e4, MSI caractérisation 81 -> 86 %, 28 survivants non tuables sur les goldens). ProfitMargin fusionné (84ff423, MSI caractérisation 71 -> 89 %). Stockpile renforcé et fusionné (MSI 92 %). ProjectProvider renforcé et fusionné (03177ca, snapshot manquant = échec, Nomad 1,1 Mo -> 2,8 Ko). BOM Group Industry renforcée et fusionnée (f2c30cb, MSI 71 -> 88 %). IndustryProjectFactory renforcé et fusionné (3cc1c33, MSI 72 -> 79 %). Les 7 services de la migration sont caractérisés et relus. Liste d'achats renforcée et fusionnée (builder 90 %, provider 70 -> 96 %).
- CP (feat/profit-margin-on-engine) : ROUGE (13 tests, coût marginal, missingData list<{reason,typeId}>), BLOQUÉ : dépendances absentes (rigs, IndustryStructureConfig -> ProductionStructure, décrypteurs) ; 4 tests hors Currently casseront ; questions de spec (mode plan vs marginal, skills d'invention, ME du T2, système favori). Architecte en cours : .claude/tasks/industry-core/profit-margin-wiring.md.
- CE (fix/corp-container-contents, #54) : ROUGE, attend validation. La lecture est déjà corrigée (8a5408d, findContentsOfContainers) ; reste divisionName du container racine propagé au contenu à la sync, sans colonne ni migration. Intégration repository : 9 tests verts (garde). Glossaire : ajouter « division » et « container ».
- CJ (test/group-industry-bom-characterization) : FINI, 14 unitaires + 2 intégration verts ; en attente de relecture test-skeptic avant fusion.
- #30 : reste volontairement reporté (jobs industry, cf. a822a59)
- `gh` : lancer via `zsh -ic` (token dans ~/.zshrc)
- #42 investigué (2026-10-09) : 9 scopes sans appel. Reco : garder `read_notifications` (Notifications Hub) ; retirer les 4 location/fleet (Fleet Tracker, à rajouter avec la feature), `read_corporation_mining`, `read_killmails` ; blueprints perso/corpo selon décision Sylvain (proposition benchmark ME/TE préremplis). Retirer ne casse rien (array_diff dans UserProvider:98) ; ajouter plus tard = alerte « Scopes manquants » pour tous. Commentaire GitHub à poster après accord.
- #47 investigué (2026-10-09) : création toujours possible ; la liste « structures partagées par la corpo » est vide tant qu'aucun autre membre n'a importé de structure corpo (CorporationStructureProvider:46-50), avec l'ancien libellé « vérifiez la sync » → corrigé par 653e617 (#88). Proposition : commenter et fermer #47. Erreurs avalées : recherche ESI (StructureSearchProvider:132-137, lot CQ en phase rouge) et fetchCorporationStructures front (structures.ts:41-43, même message que « aucune structure »).
- CQ (fix/structure-search-esi-error) : ROUGE, attend validation. `StructureSearchListResource::$incomplete` (erreur non-403 sur une structure) ; panne ESI sur /search -> 503 au lieu de 400. 11 rouges, 2 garde-fous verts.
- CS (feat/buy-vs-build-on-engine) : ROUGE (10 rouges, 18 verts ; Morphite pricé, HFB agrégé 100/3 runs, coût NFB 970951.25). Même blocage que CP (constructeur à figer avant verrouillage) ; questions de spec (mode, profondeur du coût build, invention, composants listés, tolérance R11). Mission de l'architecte étendue à BuyVsBuild.
- Frontend : aucun test Vitest existant ; fetchCorporationStructures (structures.ts:41-43) avale son erreur. Premier test frontend = décision d'outillage, à proposer.
- Autocritique agents/skills en cours → `.claude/tasks/agents-retrospective-2026-10-09.md` (propositions de diffs à valider).
- Câblage rangs 1-2 conçu (2026-10-09) : `.claude/tasks/industry-core/profit-margin-wiring.md`, `buy-vs-build-wiring.md`. Préalable : deptrac Service -> IndustryApplication/IndustryDomain. Ports à créer : RigCategories, UserProductionStructures, Decryptors (+ MissingDataPresenter, détail invention, ME du BPC inventé, plan exposé). Forme commune : montant null + missingData list<{reason,typeId}>. Décisions à valider par Sylvain : rang 1 (a) coût marginal (corriger spec §6.8) (b) skills invention 0 (c) ME/TE du BPC inventé (d) solarSystemId favori pour tout (e) taxe d'installation vide en base = 0 % + issue ; rang 2 (a) mode plan (b) build sur un niveau (c) invention incluse (d) profondeur 1 (e) tolérance 1 ISK -> build.
- Sécurité : wtrun.sh copie le .env principal dans chaque worktree (9 copies) -> remplacer par un .env factice (attend accord).

## Décisions Sylvain (2026-10-09, fin de journée) — à exécuter à la reprise
- Moteur : recommandations de l'architecte acceptées (rangs 1 et 2, cf. *-wiring.md). Taxe d'installation vide = 0 % + issue à créer.
- Phases vertes : CA fusionné (730cd35), CE fusionné (28f9220, Fixes #54), CC fusionné (e96241b, Fixes #22 ; ancien test 0f82950 qui figeait le bug supprimé). CQ validé, à lancer (tests rouges intacts).
- Annexes validées : créer les 18 issues de issues-proposals.md (2026-10-09) + l'issue taxe d'installation non saisissable ; commenter et fermer #47 ; appliquer les diffs de agents-retrospective-2026-10-09.md.
- FAIT : .env des worktrees remplacés par un .env factice complet (noms de variables du .env principal, valeurs factices, fichier dummy.env du scratchpad de session) ; wtrun.sh le copie au lieu du .env principal. Le script vit dans un scratchpad /tmp : à versionner (proposition de l'autocritique).
- Reprise moteur : test-writer finalise les harnais CP/CS (constructeur cible, 3 ports, 4 tests à réécrire, suppression de ProfitMarginServiceTest et BuyVsBuildServiceTest) avant verrouillage ; puis implementer (deptrac Service -> IndustryApplication/Domain, ports RigCategories, UserProductionStructures, Decryptors).
- tdd-guard ne protège pas tests/ des worktrees : garde d'empreinte avant chaque commit.

## Reprise (2026-10-11) — cadence : 1 flux séquentiel + 3 lots en parallèle
- Flux principal (migration du moteur) : étape 1 = finaliser la phase rouge ProfitMargin (CP) selon profit-margin-wiring.md ; puis phase verte CP ; puis harnais CS ; puis verte CS ; puis rangs 3-7.
- Lots parallèles en cours : CQ verte ; création des issues + fermeture #47 ; application des diffs de l'autocritique (script de worktree versionné).

## Outillage agentique (rétrospective 2026-10-09)

- [ ] Lot mécanique dans `tests/` (via `test-writer`, après les modifications de tests en cours) : remplacer les marqueurs `// CARACTÉRISATION` / `CHARACTERIZATION` (102 occurrences) par `#[Group('issue-N')]` et des noms `…Currently…IssueN`, relecture `test-skeptic` limitée au point e. À faire avant la migration vers le noyau Industry (`--exclude-group issue-2`).
- Image : ni `gmp` ni `bcmath` dans evetools-base / evetools-app (vérifié 2026-10-11, `php -m`). minishlink/web-push le recommande fortement (chiffrement des payloads, sinon repli lent, notice). À ajouter dans `Dockerfile.base` (`install-php-extensions`) quand le web push sera réparé (audit notifications P1-1) — décision Sylvain (rebuild de l'image base).

## #113 taxe d'installation (2026-10-18)
- Branche `fix/structure-facility-tax` (4 commits, Fixes #113), non fusionnée dans main, non poussée.
- Proposition de refactor (revue API Platform) : déplacer la règle R5 (station → structure de joueur remet la taxe à null) de `UpdateStructureProcessor` vers l'entité (`IndustryStructureConfig::changeStructureType()`).
- Constat hors diff : `StructureConfigResource` déclare un `Get` collection sur `/industry/structures` sans opération item `/industry/structures/{id}` ; issue à proposer.

## Suivis de #87 (relectures du 2026-10-19)
- `GroupIndustryProjectMember::remove()` symétrique de `requestRejoin()` (refus pour le propriétaire, rôle remis à `member`), appelé par Kick et Leave ; supprimer ensuite `setStatus()` public.
- `UpdateGroupMemberProcessor` permet de changer le rôle d'un membre `removed`.
- `?? 'Unknown'` sur `characterName` dans Kick et Leave (valeur par défaut sur donnée métier).
- `{projectId}` sans contrainte UUID sur `/leave` : `Uuid::fromString` → 500 au lieu de 404.

## Suivis de #59 (relectures du 2026-10-19)
- Personnage transféré vers un autre compte : les jobs matchés gardés par une étape achetée ou `manual` de l'ancien compte restent réservés sur tous les projets (`jobIdsMatchedToOtherProjects`), le nouveau propriétaire ne peut plus les matcher. Supprimer ces matches au retrait du personnage, ou limiter la réservation aux projets du même utilisateur.
- #59 (DDD) : déplacer `keepsItsJobMatches()` dans `IndustryProjectStep` avec la constante `manual` ; ajouter « Étape achetée » et « Étape en stock » au glossaire.

## Suites relevées pendant #112 (nuit du 2026-10-19), issues à ouvrir après accord de Sylvain
- Recalcul d'un groupe découpé : la répartition des runs au prorata suit l'ordre de stockage des étapes, pas `splitIndex` (le dernier stocké prend le reste). Figé par `testRecalculatedSplitGroupSharesTheNeedBySplitIndexWhateverTheOrderOfTheSteps` (runs 2/3/1 au lieu de 2/2/2).
- `?? 1` sur les unités par run d'un produit absent du SDE : `UpdateStepProcessor::getQuantityPerRun()`, `IndustryStepCalculator.php:130`, `IndustryProjectFactory` (`outputPerRun ?? 1`). Figé au recalcul par `testChildWithoutProductInTheSdeIsCurrentlyRecalculatedAtOneUnitPerRun`, sans issue. Contredit R6 de #112.
- `UpdateStepProcessor::getQuantityPerRun()` lit le produit de fabrication pour une étape de copie (même défaut que celui corrigé au découpage manuel, R5 de #112).
- Buy vs Build : libellés « Prix de Vente (C-J6MT) » / `structureSell` « Vente C-J6MT » codés en dur, affichés même sans structure choisie (relevé pendant #100).
- #30 : laissé de côté en mode nuit (industry jobs reportés au noyau, cf. a822a59).

## Suivis de #86 (relectures du 2026-10-19)
- Course login / refresh : `AuthenticationService::updateCharacterToken()` réécrit le token et revalide le personnage hors du verrou `eve_token_refresh_<id>` ; un refresh concurrent en `invalid_grant` peut réinvalider un alt tout juste reconnecté, ou écraser le nouveau token. Faire la mise à jour du login sous le même verrou.
- `TokenManager::refuseRevokedCharacter()` est contrôlé avant le verrou : un second process en attente rappelle EVE SSO une fois après l'invalidation. Recharger le personnage sous le verrou et refaire le contrôle.
- `canSyncCorporationAssets()` répond `true` avec un directeur invalide alors que le handler refusera (A4 conservé) : réponse trompeuse pour `RefreshCorporationAssetsProcessor`.
- Front : un 401 `EVE_AUTH_REQUIRED` d'un alt déconnecte toute la session (A5) ; distinguer d'un JWT expiré.
- `/me.characters[]` : exposer le statut par personnage pour indiquer quel alt reconnecter.

## Mode nuit 2026-10-19 — décisions en attente

- **#105** : fusionné et poussé le 2026-10-19 (7bf99c9) via InventionService, décision de Sylvain.

## Nuit du 2026-10-19 — issues laissées de côté (décision de Sylvain nécessaire)
- #118 (stockpile, stock partiel d'un intermédiaire) : `IndustryStockpileTarget` est une liste à plat (typeId, targetQuantity, stage, sourceProductTypeId), sans lien parent-enfant ni ME/runs de l'import. Réduire le sous-arbre d'un intermédiaire suppose (a) de stocker la structure de l'arbre à l'import (migration), ou (b) de recalculer l'arbre au statut depuis le produit source, ce qui exige de stocker runs et ME de l'import. Un type partagé entre plusieurs parents complique les deux. À trancher avant /spec.
- #30 : jobs industry gardés en ligne volontairement (a822a59) jusqu'à la refonte de la sync corporation.
- #42, #44, #45/#46 : décisions de Sylvain en attente (cf. sections ci-dessus) ; `evetools-wt-BV` porte un lot de code mort non commité de 11 jours.
- Reste : noyau de calcul (#2, #6-#8, #15-#19, #21, #69, #73, #108), #3 (décision), features #49-#52, #53 (en pause).
- Profit Margins : sans lieu de vente qui ait une marge (coût 0, #8), revenu et prix unitaire du résumé tombent à 0 via des `?? 0` (ProfitMarginTab.vue), relevé pendant #108.
- **#20** : traité par un autre thread (ff4d489, partie stock partiel comprise) ; ma branche de spec supprimée le 2026-10-19.

## Décisions Sylvain (2026-10-19, matin) — à exécuter
- #105 : pas d'exception deptrac ; `GroupIndustryProjectService` passe par `InventionService` (ou les repositories SDE), harnais de test à refaire sur `fix/group-bom-t2-invention`.
- #115 (AssetsService) : repli sur le nom SDE (`findNames()`), sinon `null` ; `CachedAsset::$typeName` nullable + migration, « Unknown name (#id) » dans l'UI, tri NULLS LAST.
- #21 : respecter le ME/TE saisi pour un T2 (ME 2 / TE 4 seulement par défaut) ; après régénération des étapes, relancer le matcher pour rattacher les jobs existants.
- #118 : stocker runs + ME de l'import, recalculer l'arbre depuis le produit source au statut.
- #3 : un job partiel ne modifie ni les runs de l'étape ni ceux de ses sœurs, il compte comme avancement ; matcher + calculateur corrigés ensemble.
- #53 : escalations supprimées (même partagées) ; contributions dans le projet d'un autre anonymisées (`user_id` nullable) ; ventes, visibilité corp, structures corp en SET NULL ; 409 si projet collaboratif avec membres acceptés.
- #42 : aucun scope retiré, tous destinés à des features futures (commenter et fermer l'issue).
- gmp + bcmath : ajoutés à `Dockerfile.base` dans le lot de réparation du web push.
- Code mort : garder Ansiblex (Intel Map), les 6 colonnes jamais lues, toutes les commandes console ; supprimer `EsiClient::getScalar` ; brancher `hiddenByUsers` ; brancher les barèmes line rental + `estimatedValue` obligatoire hors `material` ; garder `submitAutoDetected` + ouvrir une issue pour la détection auto des contributions ; supprimer `design-*.html`, `design-proposals/`, `docs/industry-pipeline-plan.md`, déplacer `corp-industry-plan.md` dans `docs/` ; bonus de rig unifié avec le moteur (`SdeRigBonusReader`) ; retirer tout ce qui devient mort par cascade (`syncedAt`, `getCorporationInfo`, `EveToken::isExpired()`…).
- `evetools-wt-BV` : commit (Refs #44 #45 #46), rebase sur main, gates, merge.
- Payout Group Industry : formule `coûts apportés × (1 + marge %)` confirmée (marge négative possible) ; figer par un test.
- Ouvrir toutes les issues de suivi (#112, #86, #87, #59, #113, `?? 0` de ProfitMarginTab.vue) ; titres neutres pour la sécurité.
- Autocritique du 2026-10-19 : appliquer les 5 diffs (dont les scripts `wt-new.sh` et `tests-fingerprint.sh`).
- D11a / D12 (moteur, vérifs en jeu) : reportés.
- S9 (liste partagée) : n'accepter que `typeId` + `quantity` bornés, tout recalculer côté serveur ; correctif direct, titre neutre.
- S8 restes, tout corriger en TDD (commits neutres) : state OAuth comparé et à usage unique, message d'exception non renvoyé, IDOR UUID (CreatePurchase, UpdateStep) + règle d'accès unique aux projets collaboratifs, liste partagée bornée et `noindex`.
- Moteur CP : Decryptors en 5e argument, 400 pour un décrypteur inconnu, validé ; rebaser CP/CS/DD sur main et reprendre la phase verte.
- Ménage validé : supprimer les worktrees mergés et propres, les branches locales mergées, et les 6 branches distantes des PR #62-#68.
- Audits du 2026-10-11 (Market, PI, Notifications/admin, Mining/PVE/Wallet) : une issue par constat P1/P2 après dédoublonnage ; P3 restent dans les fichiers d'audit ; sécurité en titre neutre ou dans `security-private.md`.
- Ouvrir aussi : les 3 constats du lot de mutation, l'infra du 07/10 (migrations sur base vide, composer audit, `SyncStructureMarket` sans producteur, horloge `EsiClient`), le divers (barrel `stores/industry/index.ts`, `CONTRIBUTING.md`, containers corpo à revérifier après 8a5408d).
- Import d'une structure déjà configurée (user + locationId) : 409 avec une proposition de modifier la structure existante.

## Suivis de #118 (review du 2026-10-19)
- `DeleteStockpileTargetProcessor` : la suppression des imports à la dernière cible vit dans le processor ; la déplacer dans `StockpileService` (api-platform-reviewer).
- `failedProducts[].reason` (stockpile, comme la liste d'achats de #20) renvoie le message brut de toute `\RuntimeException` ; n'exposer qu'un code stable pour les deux cas connus, journaliser le reste (security-reviewer, titre neutre).
- `StockpileService` : `$prices[$tid] ?? 0.0` rend gratuit un type sans prix dans le statut (même famille que #8).

## Ménage des branches (2026-10-22)
- Branches #62-#66, #68 (PR fusionnées) supprimées en local et sur origin.
- `wt-AA` (#53) : 2 tests rouges archivés dans `.claude/tasks/archive/53/` ; #53 repart de `main` après les 4 décisions (section #53 ci-dessus).
- `wt-BV` (code mort) : diff archivé dans `.claude/tasks/archive/dead-code/` (base en fin de `wt-BV-status.txt`) ; lot refait sur `main` dans un thread dédié.
- `wt-CS` (Buy vs Build sur le noyau) : diff archivé dans `.claude/tasks/archive/buy-vs-build-on-engine/` ; à refaire après le rang 1.
- `wt-DD` (caractérisation sync PI) : 4 fichiers archivés dans `.claude/tasks/archive/planetary-sync-characterization/` ; à relancer si besoin.

## Profit Margins sur le noyau (fusionné 2026-10-22, 24d04f3 + glossaire)

- À confirmer par Sylvain : produit non T2 → `inventionCost = 0.0` (aucun test) ; nom de rig absent du SDE et produit de réaction sans formule → exception (écran bloqué pour l'utilisateur) au lieu d'ignorer.
- Bug probable : `industry_rig_categories` sans ligne pour les groupes de formules 1888-1890 en dev → rigs de réaction jamais appliqués (lien #72). Vérifier en prod, puis issue + TDD.
- Libellé « probabilité sans compétences » (décision b) non ajouté à l'écran ; suite : port `InventionSkills`.
- Glossaire : `EsiCostIndexService.php:25-26` (`missing_cost_index`, `missing_adjusted_price`) absents de **Coût inconnu**.
- Outillage : `.claude/scripts/wt-php.sh` refuse les worktrees (`grep -q` + `pipefail` → SIGPIPE sur `git worktree list`).
- Rang 2 (Buy vs Build sur le noyau) fusionné 2026-10-22 (f174a5f). À confirmer : constantes d’efficacité de `ProductionPlanner` rendues publiques ; produit sans recette → `DomainException` ; note de version (installations plus hautes, invention T2 incluse, runs réels).

## #121 — suites (2026-10-22, après fusion e937f9c)
- [ ] Rouge complémentaire (tests tueurs, Infection MSI 92 %) : tri des jobs par `splitIndex` ; Δ = 0 et stock = 0 gardent les runs persistés ; produit en erreur avec Δ non nul ; contrôle du nom SDE du produit lui-même.
- [ ] Arbre affiché (`ProjectProvider`) toujours sur `IndustryTreeService` : issue séparée à créer.
- [ ] Après push : commenter #2 et #69 (corrigés pour la liste d'achats et le coût, pas pour l'arbre) ; fermer #121 ; annoncer la baisse des chiffres de liste/coût sur les projets à intermédiaires partagés.

## Group Industry : suites décidées par Sylvain (2026-10-29)
- [ ] #154 : valeur d'une contribution matériau = prix BOM figé à la création ; valeur client refusée en 400 ; la source du prix est explicitée dans `ContributeModal.vue` (spec `docs/specs/154-group-contribution-price-source.md`, lever le bandeau « en attente »)
- [ ] #170 : frais par défaut d'un projet de groupe lus dans les réglages Industry de l'utilisateur (spec `docs/specs/170-group-roles-fees-character-id.md`, partie frais, Q2)
- [ ] #164/#257 : borne haute sur le nombre de lignes de BOM produites par le plan (pas sur les runs) ; seuil N à fixer avec Sylvain (spec 164, Q5 réécrite)

## Group Industry : fermeture du groupe (décisions du 2026-10-29)
- [ ] Lot 6 : #257 (MAX_BOM_LINES 5 000, runs ≤ 100 000, blacklists ≤ 200 par liste) ; suggestions de revue (quantity de `SubmitContributionInput` bornée, `Range(0, 100)` des frais création/mise à jour, deux libellés i18n de `ContributeModal.vue`, suppression de `submitAutoDetected()`, protection des lignes à contribution de `buildBom` par requête en base) ; glossaire « Répartition impossible » et « Avancement de la BOM »
- [ ] #168 : passe Infection + tests tueurs sur `GroupIndustryContributionService`, `GroupIndustryContainerService`, `GroupIndustryDistributionService`
- [ ] #241 : spec (a) vendeur + « mes ventes » puis (b) rapprochement des transactions wallet de vente ; migration (colonne vendeur), glossaire « Vente » ; rappeler la migration à lancer en production
- [x] Fermées le 2026-10-29 : #162 #170 #166 #160 #164 #242 #146 #156 #158 #253 #154 (+ #130) ; reste : tests d'intégration à lancer (`make test-db && make test-integration`)

## 2026-10-29 — suites du groupe production-tree (décisions de Sylvain, issues à créer)
- [ ] #275 (#126 A1) : Science -5 %/niveau + Advanced Industry -3 %/niveau sur la durée d'une copie (vérifier Advanced Industry en jeu)
- [ ] #276 (#126 A2) : bonus de structure d'une copie, à approfondir (issue séparée, rien tranché)
- [ ] #277 (#134) : arrondi round(x, 6) avant ceil de la durée par run, réécrire le test figé 12 921
- [ ] #278 (#125 T2) : commande console de recalcul du prix de vente estimé des projets de réaction existants, à lancer en prod
- [ ] #279 (#137 T1) : quantité d'une étape de copie au PATCH = 1 par run
- [ ] #280 (#137 T3) : CreateStepProcessor quantité = runs × unités par run, 400 sans producteur
- [ ] #281 (#125 T4) : unités par run exposées, prix de vente/unité de CostEstimationTab.vue divisé par la quantité produite
- [ ] #282 #283 durée : état inconnu si compétences absentes (calculateSkillTimeMultiplier ?? 0), puis extraction de la durée hors de IndustryCalculationService

## Refonte UX/UI — maquettes (2026-11-03)

Maquettes : `docs/design/mockups/` (index.html, dashboard, industry, states ; style HUD, bouton « Noms longs »). Non commitées.

- [x] Avant/après côte à côte : `docs/design/mockups/compare.html` (captures dans `screenshots/before` et `screenshots/after`, 1440×900 et 390 px)
- [x] Lighthouse Accessibilité app actuelle vs maquettes : dashboard 91 → 100, industrie 90 → 100 (contrastes slate-500/600/700, cloche sans nom, bouton New Project à 3,61:1)
- [x] Étape 1 poussée (801a6d84, 07c3f5a6) : Lighthouse dashboard 91 → 100, industrie 90 → 99
- [ ] Reste à 99 sur l'industrie : `image-redundant-alt` (icône produit dont l'alt répète le nom affiché à côté, `alt=""` suffit)
- [ ] `docs/design/` non commité : dépôt public et captures `before/` avec noms réels (persos, corpo, alliance) ; anonymiser avant tout commit
- [x] Étape 2 poussée (110f3cec) : coque HUD (fond fixe, nav à plat + section Compte, en-tête titre / Ctrl K / Tranquility / menu compte, bannière rate limit dans le flux). Lighthouse 100 / 99 inchangé
- [ ] Paramètres en page dédiée (aujourd'hui modale ouverte depuis la nav et le menu compte)
- [ ] i18n : clé `groupIndustry.available` en double dans en.json et fr.json (la chaîne ligne ~2011 est morte, l'objet l'écrase) ; tout outil qui relit en JSON perd une valeur
- [ ] Étape 3 : composants communs (cartes à équerres, `UnknownValue`, `IskAmount`, `DataFreshness`, `ErrorBanner`) alignés sur `states.html` et sur les maquettes industrie du thread docs/group-industry-mockup
- [ ] Test des 5 secondes avec 2-3 membres de la corpo (« qu'est-ce qui demande ton attention ? »)
- [ ] Valider les libellés avec `docs/glossary.md` (Stocks, Acheter ou produire, Reconversion, Planétaire (PI))
- [ ] Option à trancher : effet de lignes de balayage sur les panneaux, couleur du niveau de sécurité à côté des systèmes

## Issus de la rétro des skills du 2026-11-03 (mining-value)
- [ ] Trancher l'accès à `structureId` (journal evening 15:07, #268/#314/#264 et GET /ledger/mining/entries), puis ouvrir l'issue : service d'accès unique + test d'architecture ou règle PHPStan interdisant de lire `structureId` dans `src/State/` hors de ce service.
- [ ] `Character.corporationId` ne se rafraîchit qu'à la connexion (#274, journal 17:00 (b)) : issue à ouvrir.
- [ ] Les syncs planifiées existantes (planetary, industry-jobs) notifient-elles à chaque cycle ? Aligner avec `SILENT_SYNC_TYPES` (esi-api §5).
- [ ] Un module neuf doit-il viser un MSI (a6aead59 sur #268, périmètre report-only) ?
- [ ] #250 : si la purge des projets industriels est confirmée au prochain deploy, fermer #250 comme caduque (commentaire : ME de réaction hérité, plus aucune donnée). Spec et investigation archivées dans `.claude/tasks/archive/250-reaction-me/` (commit de spec 2ce78105, branche supprimée). Si la purge n'a pas lieu, requête de comptage dans l'investigation de la conversation du 2026-11-03.
- [ ] Purge des projets industriels au prochain deploy (à confirmer par Sylvain) : revoir les issues de normalisation de données devenues sans objet (`app:industry:reset-reaction-me-levels`, `regenerate-multi-unit-steps`, requêtes de comptage des specs #131/#202/#182, #190 M5).

## #365 Survey Scanner
- [ ] Vérifier dans #267 qu'un minerai absent d'un scan suivant compte bien comme 0 unité restante, pas comme donnée manquante.

## Idées en réserve
- [ ] Import du Mining Ledger par copier-coller (historique > 30 j, résidu, prix en jeu) : échantillon et idées dans `.claude/tasks/mining-ledger-paste.md`. Pas d'issue tant que le besoin n'est pas confirmé.
