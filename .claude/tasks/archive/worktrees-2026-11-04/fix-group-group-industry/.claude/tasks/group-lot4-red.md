# Lot 4 Group Industry : phase rouge (#158 contenu imbriqué du container, #253 BOM reconstruite sur place, reste de #242)

Date : 2026-10-28. Branche `fix/group-group-industry`. Rien n'est commité, `.claude/state/tdd-phase` non écrit. Specs : `docs/specs/158-group-container-nested-contents.md` (Q1 à Q5 : recommandations retenues), `docs/specs/253-group-bom-rebuild-in-place.md` (Q1, Q2 : recommandations retenues). #242 sans spec : périmètre (a), (b), (c) du brief.

## Fichiers

### PHP, unitaires (lancés)

| Fichier | Tests | Rouges | Verts (garde-fous) |
|---|---|---|---|
| `tests/Unit/Service/GroupIndustry/GroupIndustryContainerServiceTest.php` (réécrit en entier, `#[Group('issue-158')]`) | 30 | 26 | 4 |
| `tests/Unit/Service/GroupIndustry/GroupIndustryBomGoldenCharacterizationTest.php` (#253 : 18 méthodes, 24 cas avec data sets) | 24 | 23 | 1 |

Container, verts : sans ligne matériau → `[]` (E17) ; sans ligne matériau, aucun asset lu ; 500/500 `verified` et 799/800 `partial` (bornes) ; identifiant de ligne et nom de type dans l'ordre de la BOM.
Golden, vert : plan en erreur (item sans recette) → BOM, quantités, livré et contributions intacts (R7, déjà vrai).

### PHP, intégration (écrits, **non lancés**, pas de base)

| Fichier | Changement |
|---|---|
| `tests/Integration/Service/GroupIndustry/GroupIndustryContainerServiceTest.php` | `verifyContainerOf` rend `[containerQuantity, status, unknownReason]`. Réécrits : `testContainerOutsideTheAllowedDivisionsHoldsNothing` → `testContainerOutsideTheAllowedDivisionsIsNotFound` (E9, `null`/`container_not_found`) ; `testContainerHoldsNothingWhileNoDivisionIsAllowed` → `testCheckIsUnknownWhileNoDivisionIsAllowed` (E12, `unknown`/`no_visible_division`) ; `testContainerInTheDeliveriesHangarHoldsNothingEvenWithEveryDivisionAllowed` → `…IsNotFoundEvenWithEveryDivisionAllowed` (E10). `testContainerInAnAllowedDivisionHoldsItsContents` : valeurs inchangées, motif `null` ajouté. Nouveaux : E1 en base (Build > Minerals > 240 Pyerite), contenu 5 niveaux sous le hangar (borne réelle de `findContentsOfContainers`), E11 (aucun asset en cache), assets d'une autre corporation seulement → `corporation_assets_not_synced` (le dépôt doit filtrer par corporation) |
| `tests/Integration/Service/GroupIndustry/GroupIndustryBomPersistenceTest.php` | `testRebuildingThePersistedBomCurrentlyAppendsASecondBomAndLeavesTheContributionsUntouchedIssue253` → `testRebuildingThePersistedBomWithTheSamePlanKeepsItsLinesAndContributions` (E1 persisté : 11 lignes, mêmes identifiants et valeurs ligne par ligne, contributions inchangées ; sans `Currently` ni `#[Group('issue-253')]`). Nouveau : `testRebuildingAProjectRecordedWithTheBomOfOneRunUpdatesItsPersistedLinesInPlaceForItsTwentyFiveRuns` (E2 persisté sur `projectRecordedWithTheBomOfOneRunForTwentyFiveRuns()` : 11 lignes, Nitrogen Isotopes 10 125 livré 300 à 512,5, copie 25/25, final 25/1 000, mêmes identifiants pour les 3 lignes enregistrées, contributions inchangées). `testProjectCreatedBeforeThePlan…` (D2) inchangé |

### Frontend (vitest, lancés)

| Fichier | Tests | Rouges | Verts |
|---|---|---|---|
| `frontend/tests/components/group-industry/containerVerificationStates.spec.ts` (#158) | 12 | 8 | 4 |
| `frontend/tests/stores/groupDistributionErrors.spec.ts` (#242 a, b) | 12 | 8 | 4 |
| `frontend/tests/components/group-industry/distributionTablesErrors.spec.ts` (#242 a, affichage) | 7 | 7 | 0 |
| `frontend/tests/views/GroupIndustry/groupIndustryDetailSalesFetch.spec.ts` (#242 c) | 3 | 1 | 2 |

Verts frontend : ligne `missing` sans libellé vérifié/partiel, aucune mention par ligne pour `container_not_found` et `unknown` (2 cas), bandeau muet avant vérification et sur des lignes vérifiées ; store : erreur effacée par un fetch réussi, erreurs du projet précédent effacées, échec tardif du projet précédent ignoré, ventes vides au premier échec ; vue : un seul fetch des ventes en ouvrant directement l'onglet, `SalesTable` charge ses ventes au montage.

## Squelettes créés (à signaler)

| Fichier | Contenu |
|---|---|
| `src/Repository/CachedAssetRepository.php` | `hasCorporationAssets(int $corporationId): bool`, corps `throw new \LogicException('not implemented')` (spec 158, « la corporation a-t-elle des assets en cache ») |
| `src/ApiResource/GroupIndustry/GroupContainerVerificationResource.php` | `public ?string $unknownReason = null;` (R5). `$containerQuantity` reste `int` : le vert le passe en `?int` |
| `frontend/src/stores/group-industry/types.ts` | `ContainerVerificationStatus = 'verified' \| 'partial' \| 'missing' \| 'container_not_found' \| 'unknown'` (`unchecked` retiré), `ContainerCheckUnknownReason` (3 motifs), `ContainerVerification.containerQuantity: number \| null`, `unknownReason` |
| `frontend/src/stores/group-industry/distribution.ts` | refs `distributionError`, `salesError`, `membersError` exposées, jamais écrites |

## Contrats figés par les tests (phase verte verrouillée)

- `new GroupIndustryContainerService(VisibleCorporationAssetsFinder, GroupIndustryBomItemRepository, CachedAssetRepository, CorpAssetVisibilityRepository)`. Les tests unitaires utilisent le vrai `VisibleCorporationAssetsFinder` sur des dépôts simulés (frontière base) ; aujourd'hui les deux arguments en trop sont ignorés par PHP.
- Ordre R4 : sans nom → `[]` (avant tout, même owner sans corporation) ; owner sans corporation (sans main, ou main sans corporation) ; aucun asset en cache pour **la corporation de l'owner** ; visibilité absente ou `[]` ; container introuvable ; puis ligne par ligne. Croisements testés : owner sans corporation et aucun asset ; aucun asset et aucune visibilité.
- #253 : message exact `The BOM of a completed project cannot be rebuilt.` (E5, « TO CONFIRM » de la spec), levé avant le plan (un item sans recette ajouté au projet terminé ne produit pas l'erreur du plan) et avant tout appel de prix. Statuts `draft`, `published`, `in_progress`, `selling` reconstruits.
- Q1 figé : rang par runs décroissants des deux côtés (une ligne de 50 runs insérée avant celle de 200 est rapprochée au rang 2) ; à runs égaux, la ligne qui porte une contribution passe d'abord. Q2 figé : `estimatedPrice` réécrit avec le prix courant, `null` compris ; `estimatedValue` des contributions inchangée.
- Libellés FR exacts (Q1 de 158) : lignes « Vérifié », « Partiel », « Absent du container » ; bandeau « Container introuvable », « Non vérifiable » + « Synchronisez les assets corpo » / « Aucune division partagée » / « L'owner n'a pas de corporation » (apostrophe droite ou typographique). EN imposé : « Verified », « Partial », « Missing from container », « Container not found », « Cannot be checked » ; motifs EN libres mais distincts et traduits. Bandeau : un seul « Container not found » / « Non vérifiable » pour plusieurs lignes. `verified`/`partial` gardent « x / y ». À noter : le fr.json actuel dit « Verifie » sans accent et `BomTable.vue` code les libellés en anglais en dur.
- #242 (c) : choix figé = **la vue** cesse d'appeler `fetchSales` au changement d'onglet ; `SalesTable` garde son `onMounted` (3e test).
- #242 (a) : chaque fetch écrit sa propre erreur et n'efface que la sienne ; `error` réservé aux actions, ni effacé ni écrit par un fetch ; ouvrir un autre projet efface les trois erreurs de ressource. Affichage : `SalesTable` montre `salesError` et `error`, `MemberTable` `membersError`, `DistributionTable` `distributionError` (aujourd'hui aucune erreur affichée).
- #242 (b) : un fetch des ventes en erreur laisse `sales = []`, sans toucher distribution ni membres.

## Raisons d'échec observées

- Container (26) : `Failed asserting that two arrays are identical`, ex. `Pyerite 0 'unchecked'` au lieu de `240 'partial'` (E1), `450 'partial'` au lieu de `550 'verified'` (E2), `0 'unchecked'` au lieu de `null 'container_not_found'` / `'unknown'` + motif, lignes `unchecked` au lieu de `[]` (E15).
- Golden (23) : tailles 22/14/20/4/3 au lieu de 11/11/10/2/2, identifiants de lignes en double dans les états, `200/8000` au lieu de `150/6000` (rang), message `No blueprint or reaction found for product type ID 11399` au lieu du refus du projet terminé.
- Vitest (24) : `AssertionError` uniquement : « Mexallon » sans « Missing from container », « Verified » au lieu de « Vérifié », 0 occurrence du bandeau, erreurs écrasées ou effacées (`[false, true]`, `'' to contain 'Members unavailable…'`), `sales` gardant l'ancienne liste, `[true, 2]` au lieu de `[true, 1]` fetch des ventes.
- Suite `Unit` complète : `Tests: 13155, Failures: 49, PHPUnit Notices: 251, Notices: 1, Incomplete: 1`. Les 49 échecs sont exactement les nouveaux rouges (26 + 23), aucune erreur ; notices et incomplet identiques au lot 3 (préexistants).
- Vitest sur `tests/components/group-industry`, `tests/stores`, `tests/views/GroupIndustry`, `tests/a11y`, `tests/components/common` : 24 échecs (les nouveaux), 285 verts.
- `npm --prefix frontend run build` : vert (vue-tsc sans erreur) ; `frontend/dist` et `tsconfig.tsbuildinfo` restaurés. ESLint vert sur les 4 specs.

## Recherche des formes figées

`grep -rn "GroupContainerVerification|containerVerification|fetchSales|buildBom|hasCorporationAssets|unchecked|Currently.*Issue(158|253|242)|CARACTÉRISATION" tests frontend/tests` : `ContainerVerificationProviderTest` (affecte `containerQuantity = 500`, compatible `?int`), `GroupIndustryProjectServiceTest` (buildBom sur projet vide, inchangé selon la spec), `groupDistributionAnotherProject.spec.ts` (aucune assertion sur `error` ni sur les ventes après échec : inchangé). Plus aucun `…Issue253` ni `issue-253`.

## Remarques pour le vert et hors périmètre

- `SalesTable` et `MemberTable` lisent encore `store.loading` (combiné) : le spinner d'un tableau s'affiche pendant le chargement d'une autre ressource. Non testé ici (hors brief) ; à traiter avec #242 si voulu.
- Même double appel pour les membres (`MemberTable` `onMounted` + watcher de l'onglet) : non testé, hors brief.
- `ContainerBanner` n'apparaît que si le projet a un nom de container (inchangé, E15 → `[]`).
- Glossaire : « Vérification du container » (158) et « Régénération de la BOM » (253) restent à ajouter avec le lot (docs hors `tests/`).
