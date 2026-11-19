# Lot 3 Group Industry : phase rouge (#146 répartition impossible, #156 inconnu jamais 0)

Date : 2026-10-28. Branche `fix/group-group-industry`. Rien n'est commité. Spec : `docs/specs/146-156-154-group-industry-unknown-not-zero.md` (Q1 à Q5 : recommandations retenues). La partie #154 n'est pas couverte : aucun test sur `ContributeModal.vue` ni sur `GroupIndustryContributionService::resolveMaterialValue`.

## Fichiers

### PHP, tests nouveaux

| Fichier | Tests | Rouges | Verts (garde-fous) | Lancé |
|---|---|---|---|---|
| `tests/Unit/Service/GroupIndustry/GroupIndustryDistributionServiceUndistributableTest.php` (`#[Group('issue-146')]`) | 6 | 4 | 2 | oui |
| `tests/Unit/State/Provider/GroupIndustry/GroupIndustryResourceMapperProjectTest.php` (`#[Group('issue-156')]`) | 23 (dont 3 data sets) | 12 | 11 | oui |
| `tests/Integration/Repository/GroupIndustryContributionRepositoryTest.php` (`#[Group('issue-156')]`) | 4 | | | **non lancé** (pas de base) |

### PHP, tests modifiés

| Fichier | Changement | Lancé |
|---|---|---|
| `tests/Unit/Service/GroupIndustry/GroupIndustryDistributionServiceTest.php` | `testZeroCostsNoContributions` réécrit et renommé `testMarginIsUnknownWhenNoContributionIsApproved` (D2 : 500 000, frais 3/3, revenu net 470 000, coût 0, marge `null`, aucun membre), `#[Group('issue-146')]`. Rouge | oui |
| `tests/Unit/State/Provider/GroupIndustry/GroupDistributionProviderTest.php` | + `testUndistributableDistributionReachesTheApiWithAnUnknownMarginShareProfitPartAndPayout` (D1 de bout en bout : vrai `GroupIndustryDistributionService`, dépôts stub, ressource API à marge, part, part de profit et payout `null`). Rouge | oui |
| `tests/Unit/State/Provider/GroupIndustry/GroupProjectProviderTest.php` | + `testProjectIsReadWithTheValueOfItsApprovedContributionsAndItsBomValueAndProgress` (provider d'item, repli du mapper : K1 1 250 000, valeur BOM 6 000 000, avancement 70.0). Rouge | oui |
| `tests/Integration/State/Provider/GroupIndustry/GroupProjectCollectionProviderTest.php` | `MAX_QUERIES` 4 → 5 (requête groupée de R7) ; constructeur du provider avec `GroupIndustryContributionRepository` en **4e argument** ; contributions (approuvées, en attente, rejetées) ajoutées aux fixtures, y compris celles du test A/B (2 × 2 contre 10 × 10) pour qu'un repli projet par projet y soit détecté ; Sabre : avancement 50.0 → **70.0**, `approvedContributionValue` 1 250 000 ; Jackdaw : renommé `testJackdawProjectFallsBackToUnknownOwnerAndIgnoresMissingPrices` → `testJackdawProjectFallsBackToUnknownOwnerAndHasAnUnknownBomValueWhenAMaterialHasNoPrice`, `totalBomValue` 15 000 000 → **`null`**, avancement 25.0 → **16.67**, `approvedContributionValue` 0.0 (une contribution en attente de 500 000) ; 10 projets : `approvedContributionValue` 345 000 (300 000 + 45 000 approuvées, 70 000 en attente). Commentaires devenus faux supprimés | **non lancé** |
| `tests/Integration/State/Provider/GroupIndustry/AvailableGroupProjectsProviderTest.php` | constructeur avec `GroupIndustryContributionRepository` en **5e argument** ; Sabre : 50.0 → **70.0**, `approvedContributionValue` 1 250 000 ; Jackdaw : 0.0 (contribution en attente de 640 000) ; 10 projets : 415 000 (380 000 + 35 000 approuvées, 90 000 rejetée), fixtures du test A/B comprises | **non lancé** |
| `tests/Integration/State/Provider/GroupIndustry/GroupDistributionProviderTest.php` | + `testSaleWhoseOnlyApprovedContributionIsAFreeLineRentalIsUndistributable` (D1 en base, `#[Group('issue-146')]`) | **non lancé** |

Inchangés, conformément à la spec : `testTenProjectsAreListedWithTheirTenMembersAndTenBomItems` et `testIshtarProjectOwnedByTheUserWithoutBom` (valeurs), `testTenOpenProjectsAreListed…` et `testInProgressProjectWithoutBom` (valeurs), les tests A/B de requêtes. `GroupIndustryBomGoldenCharacterizationTest::testJobLinesCurrentlyHaveNoEstimatedPriceIssue156` reste juste : R6/Q2 garde les lignes de job sans prix (le coût d'installation d'un job groupé est hors lot). À renommer ou rattacher à l'issue de suivi de Q2 quand elle existera.

### Frontend

| Fichier | Tests | Rouges | Verts |
|---|---|---|---|
| `frontend/tests/components/group-industry/jobsTableUnknownCost.spec.ts` | 6 | 3 (J1, J3, premier job sans prix) | 3 (colonne déjà `—`, J2 = 300, total 0 connu) |
| `frontend/tests/components/group-industry/bomTableProjectTotals.spec.ts` | 4 | 4 (B1, total lu du projet 840 au lieu de 600, B2 75 %, arrondi 16.67 → 17 %) | 0 |
| `frontend/tests/components/group-industry/projectKpiCards.spec.ts` | 10 | 9 (K3, K3 discriminant, 0 contribution, K4, deux exclus, aucun connu, FR, description EN et FR) | 1 (K5) |
| `frontend/tests/components/group-industry/projectCardUnknownBomValue.spec.ts` | 2 | 1 (C1) | 1 (valeur connue 6.0 M) |
| `frontend/tests/components/group-industry/financialSummaryUnknownMargin.spec.ts` | 4 | 2 (F1 FR, F1 EN) | 2 (profit 928 000 affiché, F2) |
| `frontend/tests/components/group-industry/distributionTableUndistributable.spec.ts` | 8 | 6 (F3 : bandeau, ligne membre, totaux, aucun payout 0, anneau et légende, pas d'alerte de perte) | 2 (D4 : part 0.0 % et payout 0 connus, anneau à deux segments, pas de bandeau) |
| `frontend/tests/a11y/iconButtonsHaveName.spec.ts`, `frontend/tests/components/common/commandPaletteHarness.ts` | fixture `GroupProject` : + `approvedContributionValue` (champ obligatoire) | | verts |

## Squelettes créés (à signaler)

| Fichier | Contenu |
|---|---|
| `src/ApiResource/GroupIndustry/GroupIndustryProjectResource.php` | `public float $approvedContributionValue = 0.0;` (R7). Le défaut 0.0 rend verts, dès la phase rouge, K2 et « valeur précalculée 0 non remplacée » |
| `src/Repository/GroupIndustryContributionRepository.php` | `sumApprovedValueByProject(array $projects): array` (`list<GroupIndustryProject>` → `array<string, float>`, clé = id du projet, chaque projet donné présent, 0.0 sans contribution approuvée), corps `throw new \LogicException('not implemented')` |
| `frontend/src/stores/group-industry/types.ts` | `GroupProject.totalBomValue: number \| null`, `GroupProject.approvedContributionValue: number`, `MemberDistribution.sharePercent/profitPart/payoutTotal: number \| null`, `Distribution.marginPercent: number \| null` (R1, R3, R7) |

Signatures figées par les tests (phase verte verrouillée) :
- `GroupIndustryResourceMapper::projectToResource($project, $myMembership, ?int $acceptedMembersCount = null, ?float $approvedContributionValue = null)` : 4e paramètre positionnel ; `null` → calcul depuis `getContributions()` filtrées sur `approved` ; une valeur donnée, 0.0 compris, est prise telle quelle.
- `GroupProjectCollectionProvider(Security, GroupIndustryProjectMemberRepository, GroupIndustryResourceMapper, GroupIndustryContributionRepository)`.
- `AvailableGroupProjectsProvider(Security, GroupIndustryProjectRepository, GroupIndustryProjectMemberRepository, GroupIndustryResourceMapper, GroupIndustryContributionRepository)`.
- Aujourd'hui l'argument en trop est ignoré par PHP ; les intégrations ne sont pas lancées.

Libellés figés par les specs vitest (clés i18n libres) :
- bandeau FR : « Répartition impossible : aucune contribution approuvée n'a de coût, le revenu net ne peut pas être réparti » (texte de la spec ; espaces insécables tolérées) ; aucun texte EN imposé ;
- exclusion du pipeline : EN « {n} project with unknown value excluded » / « {n} projects with unknown value excluded » (calqué sur `unknownProfitExcluded`), FR « {n} projet à la valeur inconnue exclu » / « {n} projets à la valeur inconnue exclus » ;
- `groupIndustry.kpis.pipelineDesc` : EN « estimated material cost », FR « coût matériaux estimé » ;
- formats inchangés : KPI en `formatIsk(x, 1)` (« 1.3 M », « 2.9 M », « 7.0 M ») ; pieds de `BomTable` et `JobsTable` testés sur des montants < 1 000 pour rester valables avec `formatIsk(x, 1)` comme avec `<IskAmount>`.

## Raisons d'échec observées

- Service (5) : `marginPercent` 0.0 au lieu de `null` ; part, part de profit et payout 0.0 au lieu de `null` (diff d'assertion ; revenu, frais, revenu net et coûts par type déjà égaux).
- Provider de distribution (1) : même diff, sur la ressource API.
- Mapper (12) : `Failed asserting that 10000.0 / 17000000.0 / 19000000.0 / 6000000.0 / 0.0 is null` (valeur BOM), `50.0 / 25.0 / 27.38 is identical to 75.0 / 16.67 / 50.0` (avancement), `0.0 is identical to 1250000.0 / 857500.0 / 2750000.0` (valeur approuvée).
- Provider d'item (1) : `[0.0, 6000000.0, 50.0]` au lieu de `[1250000.0, 6000000.0, 70.0]`.
- Suite `Unit` complète : `Tests: 13115, Failures: 19, PHPUnit Notices: 251, Notices: 1, Incomplete: 1`. Les 19 échecs sont exactement les nouveaux rouges ; l'incomplet est `InstallCostTest` (préexistant) ; aucune notice ne vient des classes touchées (vérifié avec `--display-phpunit-notices`).
- Vitest : 25 rouges. 19 `AssertionError` (« 0 » / « 100 » / « 275 » au lieu de « — », « 15.0 K » au lieu de « — », « 600 » au lieu de « 840 », « 50% » au lieu de « 75% », « 2.2 M » au lieu de « 1.3 M », mention d'exclusion absente, « estimated sell value », « Margin: +0.0% »). Les 6 rouges de `DistributionTable` (impossible) échouent par `TypeError: Cannot read properties of null (reading 'toFixed')` au rendu : le composant ne sait pas afficher une part inconnue, il n'y a pas d'autre chemin sans toucher `src/`.
- Lancement : vitest sur `tests/components/group-industry`, `tests/stores`, `tests/views/GroupIndustry`, `tests/a11y`, `tests/components/common` : 25 échecs (les nouveaux), 250 verts.

## Build frontend

`npm --prefix frontend run build` échoue sur 11 erreurs TS, **toutes dans `src/`**, aucune dans `tests/` : `DistributionTable.vue` (`sharePercent`, `profitPart`, `payoutTotal`, `marginPercent` possiblement `null`, lignes 42, 54, 58, 122, 178, 182, 185, 190, 289) et `ProjectKpiCards.vue` (`totalBomValue` possiblement `null`, lignes 19 et 23). Conséquence attendue de l'élargissement des types (R1, R3) ; le vert les corrige en implémentant R2 et R8. `frontend/dist` et `frontend/tsconfig.tsbuildinfo` restaurés.

## Couverture de la spec

- R1 : D1 (service, provider unitaire, intégration), D2 (test réécrit), D3, deux membres à 0 (location et matériau à prix 0), D4 (garde-fou : part 0 et payout 0 connus), borne 1 ISK (marge 99 900 %, garde-fou contre `> 1`). D5 : test existant `testZeroRevenueNoSales`, inchangé.
- R2 : F1 FR et EN, F2, F3 (bandeau FR, ligne membre `0 — — —`, ligne total `0 — — —`, total de l'anneau `—`, légende `—`, aucun segment d'anneau, aucun « 0.0% » ni « +0 », pas d'alerte de perte), garde-fou D4 à l'écran.
- R3 : M1, M2, variante à trois matériaux (24 500), M3, prix 0 à côté d'un prix manquant (`null`), prix manquant en 1re, 2e, 3e position, M4, job sans prix à côté d'un matériau prix connu (10 000, pas `null`), job avec prix exclu de la somme, projet sans matériau (0.0 / 0.0).
- R4 : P1, P2, P3, arrondi au supérieur (16.67), P4, avancement connu sans prix.
- R5 : B1, total lu du projet (840) plutôt que la somme des lignes (600), B2, arrondi à l'entier (16.67 → 17 %).
- R6 : J1, J2, J3, premier job sans prix, total 0 connu.
- R7 : K1 (mapper, provider d'item, deux providers de collection, dépôt), tous types de contribution (job, BPC), K2, valeur précalculée prise telle quelle (2 750 000 et 0.0), requêtes bornées (MAX_QUERIES 5, A/B avec contributions), K3 et variante discriminante, 0 contribution.
- R8 : K4, deux exclus, aucun connu (0 et 1 exclu), K5, exclusion en FR, description EN et FR, C1 et garde-fou valeur connue.
