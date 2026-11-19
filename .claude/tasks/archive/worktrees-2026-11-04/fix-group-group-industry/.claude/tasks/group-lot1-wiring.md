# Lot 1 Group Industry : note de câblage (#162, #170 réduit, #166, #160 réduit, #168)

Code lu sur `fix/group-group-industry` (HEAD `3c43544`), 2026-10-28.

## Ordre dans le lot et fichiers partagés
1. **#162** d'abord : il fixe les constructeurs que #166 et #170 réutilisent.
2. **#170** (rôles, `characterId: null`) : ajoute une 2e méthode au même `GroupProjectAccessChecker`.
3. **#166** : ses tests construisent `GroupDistributionProvider` et `GroupSaleCollectionProvider` avec le checker (signature de #162).
4. **#160** : indépendant (`SdeBlueprintCatalog::recipeOf()`), à tout moment.
Fichiers partagés : `GroupProjectAccessChecker` (#162 `assertCanRead`, #170 `assertCanManageMember`) ; `GroupSaleCollectionProvider` (#162 constructeur, #166 `findByProjectForListing`) ; `GroupDistributionProvider` (#162 ; #166 passe par `GroupIndustryDistributionService`, nom `findApprovedByProject()` gardé) ; `RecordSaleProcessor` (#162 seul dans ce lot).

## Signatures cibles (à respecter par test-writer et dev vert)
- `GroupProjectAccessChecker` : constructeur inchangé (`GroupIndustryProjectMemberRepository`). Ajouts : `assertCanRead(User, GroupIndustryProject): void` (403 « You do not have access to this project ») ; `assertCanManageMember(User, GroupIndustryProject, GroupIndustryProjectMember): void` (403 « Only the project owner can manage an admin »).
- Remplacement en place de `GroupIndustryProjectMemberRepository` par `GroupProjectAccessChecker` : `GroupBomProvider(Security, ProjectRepo, BomItemRepo, Checker)`, `GroupDistributionProvider(Security, ProjectRepo, Checker, DistributionService)`, `GroupSaleCollectionProvider(Security, ProjectRepo, Checker, SaleRepo)`, `RecordSaleProcessor(Security, ProjectRepo, Checker, EM, MercurePublisherService)` (le repo n'y sert qu'à `:113`).
- `GroupProjectProvider(Security, ProjectRepo, MemberRepo, Checker, Mapper)` : le repo reste pour `myRole`/`myStatus` (#162 R2).
- `UpdateGroupMemberProcessor` et `KickGroupMemberProcessor` : constructeurs inchangés (checker déjà injecté).
- Nouveau : `GroupIndustrySaleRepository::findByProjectForListing(GroupIndustryProject): GroupIndustrySale[]`.
- DI : tout est autowiré (`config/services.yaml` ne déclare aucun de ces services), aucune config à toucher.

## Harnais par dépendance
- **Checker** : toujours le vrai, sur un stub `GroupIndustryProjectMemberRepository` dont `findOneBy()` compare chaque critère (callback `membershipMatches`, déjà copié dans 5 tests : `GroupProjectAccessCheckerTest`, `GroupProjectProviderTest`, `GroupMemberCollectionProviderTest`, `Join…`, `Leave…ProcessorTest`). Ne pas réutiliser le `willReturn($adminMember)` aveugle de `RecordSaleProcessorTest::setUp()` pour A3/A9 : il ignore le critère `role`.
- **User** : stub avec `getCorporationId()` (C1 98000001, C2 98000002, `null` pour A10).
- **Membres cibles (#170)** : entités réelles (`new GroupIndustryProjectMember` + `setRole`, comme `KickGroupMemberProcessorTest::acceptedMember()`), pas de stub dont `getRole()` n'est pas configuré (valeur par défaut PHPUnit non vérifiée ici).
- **Mapper** : `new GroupIndustryResourceMapper()` sans argument. **Mercure** : `MercurePublisherService(HubInterface mock, NullLogger)`.
- **Distribution (unit #162)** : stub de `GroupIndustryDistributionService` (classe non finale) ; la matrice d'accès n'a pas besoin du calcul.
- **Ventes (unit #162)** : stubber `findByProjectForListing()` (méthode cible de #166), pas `findBy()`, sinon le test casse au vert de #166. Exige en rouge un squelette de la méthode (ex. délégation à `findBy` trié `soldAt DESC`) : à signaler.
- **Comptage SQL (#166)** : trait `tests/Integration/RecordsSqlQueries.php`, providers montés à la main avec `self::getContainer()->get(GroupProjectAccessChecker::class)` (motif `GroupContributionCollectionProviderTest:300`), sans le filtre `GROUP_INDUSTRY_TABLES_PATTERN`.
- **#160** : golden `GroupIndustryBomGoldenCharacterizationTest` (`maxProductionLimitOverrides` indexé par typeId produit, lu `:1679`) ; variante sans base : `SdeBlueprintCatalogOutputPerRunTest::catalogWithOneProducer(…, $maxProductionLimit, …)`. Aujourd'hui l'erreur est une `\InvalidArgumentException` (pas une `\RuntimeException`) : catcher `\Throwable` puis `assertInstanceOf(\UnexpectedValueException::class)`, sinon le rouge est une erreur et non une assertion.
- **#168** : `GroupProjectDeleteProviderTest` (401 sans user, 404 `find()` null, `:35`, `:41`) ; `DeleteGroupProjectProcessorTest` : stub repo + mock EM (`remove`/`flush` une fois, ou jamais si `find()` rend null). Caractérisation : verts d'emblée.
- **Front (#170 Q4)** : `MemberTable.vue` ne reçoit que `isAdmin` ; prop à ajouter (`isOwner` ou `myRole`). Test vitest avec `createTestingPinia` (stores `group-industry/distribution` et `auth`).

## Environnement du worktree
- `vendor/` et `frontend/node_modules/` absents, aucun service compose lancé : `composer install` (et `npm ci`) via Docker avant toute exécution.
- **Non lancés ici (base `eve_app_test` requise)** : `Integration\…\GroupDistributionProviderTest` et `GroupSaleCollectionProviderTest` (#166, nouveaux), `Integration\…\GroupMemberCollectionProviderTest:132`, `AccountDeletionServiceTest`, `Integration\Industry\Infrastructure\Sde\SdeBlueprintCatalogTest` (#160). Tout le reste est Unit.

## Tests existants qui cassent (à réécrire en rouge : `tests/` est verrouillé en vert)
- `GroupBomProviderTest::setUp()` (`:44`), `GroupProjectProviderTest::setUp()` (`:67`), `RecordSaleProcessorTest::setUp()` (`:70`) : nouveau constructeur (#162). Assertions inchangées ; rouge = `TypeError` au constructeur, voulu par la spec #162 (Harnais).
- `AccountDeletionServiceTest::listSalesOf()` (`:820-825`) : checker à la place du repo de memberships (#162). Non lancé.
- `GroupIndustryResourceMapperTest::testMemberToResourceHandlesNullMainCharacter` (`:84`, `assertSame(0, …)`) → `null` (#170 C2).
- `Integration\…\GroupMemberCollectionProviderTest::testPendingMemberAndMemberWithoutMainCharacter` (`:132`) → `null` (#170). Non lancé.
- Restent verts (vérifié) : `KickGroupMemberProcessorTest` (les refus « owner » et « soi-même » passent avant R1 ; les autres visent un rôle `member`), `GroupProjectAccessCheckerTest`, `GroupIndustryDistributionServiceTest` (nom `findApprovedByProject` gardé). Aucun test n'asserte « …can record sales ».

## Vrai rouge attendu (le reste est caractérisation)
- #162 : matrice `assertCanRead()` (méthode absente) ; message 403 de `RecordSaleProcessor` (A3, A9, A13). A1 à A13 sur les providers = comportement inchangé (R3).
- #170 : R-E1, R-E2, R-E3, R-E4, C2. Garde-fous verts : R-E5 à R-E12.
- #166 : E1 (A/B) et E3 ; E4 garde-fou. #160 : L1, L2 ; L3, L4 garde-fous.

## Questions ouvertes
1. Ordre des paramètres des constructeurs ci-dessus : proposé, à confirmer au vert.
2. #166 Q1 à Q3 (ventes corrigées, jeu E1 seul, `MAX_QUERIES` 5/4) : recommandations prises, spec encore « brouillon ».
3. #160 L5 (`POST` 400 nommant le blueprint) : aucun test de processor prévu par la spec ; le golden suffit-il ?
4. #170 R5 : nom de la prop de `MemberTable.vue` (`isOwner` ou `myRole`), à fixer avant le test vitest.
