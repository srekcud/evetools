# Lot 1 Group Industry : phase rouge (#162, #170 réduit, #166, #160, #168)

Worktree `fix/group-group-industry`, 2026-10-28. Rien n'est commité. Suite Unit complète : 12 928 tests, 88 erreurs + 38 échecs, tous dans les classes ci-dessous (aucun autre test cassé).

## Squelettes créés dans `src/` (corps `throw new \LogicException('not implemented')`)
- `GroupProjectAccessChecker::assertCanRead(User, GroupIndustryProject): void`
- `GroupProjectAccessChecker::assertCanManageMember(User, GroupIndustryProject, GroupIndustryProjectMember): void`
- `GroupIndustrySaleRepository::findByProjectForListing(GroupIndustryProject): array`

## Harnais partagé
`tests/Unit/State/Provider/GroupIndustry/GroupProjectAccessProfiles.php` (trait) : projet `Sabre batch` `in_progress`, propriétaire C1 98000001 avec sa ligne `owner/accepted`, profils A1 à A13 de la spec #162, dépôt de memberships en mémoire (`findOneBy` compare chaque critère, `find` par id), dépôt de projets qui rend `null` pour un id inconnu (A11), `outcomeOf()` qui rend `granted` ou « code message » (toute autre exception devient une chaîne : les rouges sont des échecs d'assertion, pas des erreurs). Matrices `readAccessMatrix`, `participationAccessMatrix`, `administrationAccessMatrix`.

## Par fichier (Unit, lancés)
| Fichier | Tests | Rouges | Raison du rouge |
|---|---|---|---|
| `GroupProjectAccessCheckerTest` (réécrit sur le trait) | 51 (46 nouveaux) | 22 | `'LogicException: not implemented'` au lieu de `granted` / 403 : matrice lecture A1-A13 (12), propriétaire sans corpo, null≠null, membre sans corpo (3), `assertCanManageMember` (7). Matrices participation et administration : 24 verts (caractérisation) |
| `GroupProjectProviderTest` (constructeur #162) | 20 (15 nouveaux) | 20 erreurs | `TypeError` constructeur (voulu par la spec). Avec l'ancien constructeur : seul `testOwnerWithoutCorporationNorMembershipReadsHisProjectByIdentity` échoue (`403` au lieu de `Sabre batch -/-`) |
| `GroupBomProviderTest` (constructeur #162) | 22 (13 nouveaux) | 22 erreurs | `TypeError` constructeur ; ancien constructeur : 0 échec |
| `GroupDistributionProviderTest` (nouveau) | 15 | 15 erreurs | `TypeError` ; ancien constructeur : 0 échec |
| `GroupSaleCollectionProviderTest` (nouveau) | 15 | 15 erreurs | `TypeError` ; ancien constructeur : seul le test de liste échoue (le provider lit `findBy`, pas `findByProjectForListing`, #166) |
| `RecordSaleProcessorTest` (constructeur #162) | 16 (14 nouveaux) | 16 erreurs | `TypeError` ; ancien constructeur : A3-A10 et A13 échouent sur le message (`Only project owner or admin can record sales` au lieu de `…can perform this action`) |
| `MemberAndContributionListAccessTest` (nouveau) | 25 | 0 | caractérisation colonnes Membres/Contributions A1-A13 + 404 |
| `UpdateGroupMemberProcessorTest` (nouveau, #170 + #168) | 24 | 7 | R-E1 `granted member/accepted`, R-E3 `400 Only pending members…`, R-E4 `granted member/accepted`, admin retiré `granted member/removed`, R1 avant rôle invalide / promotion / statut invalide. Verts : R-E6, R-E8, R-E10, R-E11, R-E12, ordre R3 (401, 404 projet, 403, 404 membre, 400) |
| `KickGroupMemberProcessorTest` | +3 | 2 | R-E2 et admin retiré : `flush() was not expected to be called`. R-E7 vert ; R-E5 et R-E12 déjà couverts (`testAdminCannotKickHimself`, `testCannotKickTheOwner`) |
| `GroupIndustryResourceMapperTest` | 1 réécrit | 1 | C2 : `Failed asserting that 0 is null` (renommé `testMemberWithoutMainCharacterHasNoCharacterIdAndAnUnknownName`) |
| `GroupIndustryBomGoldenCharacterizationTest` | +3 | 2 | L1 (0), L2 (-1) : `InvalidArgumentException is an instance of UnexpectedValueException` ; L3 (limite 1) vert |
| `SdeBlueprintCatalogOutputPerRunTest` | +5 | 4 | limites 0, -1 (fabrication), 0, -7 (réaction) : même message ; limite 1 verte |
| `DeleteGroupProjectProcessorTest` (nouveau, #168) | 2 | 0 | caractérisation |
| `GroupProjectDeleteProviderTest` | +2 | 0 | 401 et 404 (#168) |

Vérification « ancien constructeur » : copies temporaires des 5 classes (supprimées) sous `var/`, lancées sur le code actuel : 88 tests, 11 échecs, tous des changements voulus listés ci-dessus.

## Non lancés (base `eve_app_test` absente)
- `tests/Integration/State/Provider/GroupIndustry/GroupDistributionProviderTest.php` (nouveau, #166) : E1 A/B (5 membres/10 contributions/4 ventes contre 10/20/8), borne `MAX_QUERIES = 5`, E6 (« Unknown », payouts 3 300 000 / 1 100 000).
- `tests/Integration/State/Provider/GroupIndustry/GroupSaleCollectionProviderTest.php` (nouveau, #166) : E3 A/B, borne `MAX_QUERIES = 4`, E4 garde-fou, E7 et ordre `soldAt DESC`.
- `tests/Integration/Industry/Infrastructure/Sde/SdeBlueprintCatalogTest.php` : `testBlueprintImportedWithAMaxProductionLimitOfZeroIsAnExplicitErrorNamingIt`.
- Réécrits : `Integration\…\GroupMemberCollectionProviderTest:132` (0 → `assertNull`), `AccountDeletionServiceTest::listSalesOf()` (checker du conteneur à la place du dépôt de memberships).
Ces deux nouveaux tests #166 construisent les providers avec le checker : ils échoueront d'abord en `TypeError` jusqu'au vert de #162, puis sur le comptage.

## Frontend
`frontend/tests/components/group-industry/memberTableAdminActions.spec.ts` : 3 tests, 1 rouge (l'admin voit `Demote, Kick` sur l'autre admin et `Promote, Kick` sur le membre). Prop retenue : `isOwner: boolean` à côté de `isAdmin` (Q4 de la note). `npm run build` vert (type-check des specs) ; `frontend/dist/index.html` et `tsconfig.tsbuildinfo` remis à l'état git. `frontend/node_modules` : lien symbolique (package-lock identiques, `cmp`). Les 8 autres specs group-industry restent vertes.

## Exigences a-e (caractérisation : #168, colonnes A1-A13 inchangées)
- a. Aucun bug connu dans les attendus des tests de caractérisation ; les changements voulus (message de vente, propriétaire par identité, `characterId: null`, rôles) sont dans des tests à part.
- b. Prix distincts : ventes 61 250 000 / 1 837 500 / 23 450 ISK × 3 / 14 / 260 ; distribution par membre `index × 125 000 + n × 7 500` ; frais du stub de distribution tous différents.
- c. Sorties assertées en valeurs exactes : code + message HTTP, `myRole/myStatus`, rôle/statut après PATCH et nombre de flush, nombre de ventes persistées, tous les champs de la distribution et des ventes.
- d. Aucun snapshot ni `markTestIncomplete/Skipped`.
- e. Aucun comportement suspect figé : pas de `Currently…`. Proposition d'issue : aucune.

## Points à trancher au vert
- Messages #160 : les tests exigent `Blueprint <id>` et la valeur lue (regex, id retiré du message) ; formulation libre sinon.
- `assertCanManageMember` : un admin cible un membre `removed` de rôle `member` → non testé (kick d'un retiré hors périmètre).
