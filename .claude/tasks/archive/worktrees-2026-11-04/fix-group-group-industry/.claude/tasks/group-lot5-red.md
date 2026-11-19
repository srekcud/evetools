# Lot 5 Group Industry : phase rouge (#154 prix d'une contribution, frais par défaut de #170, borne de lignes de BOM de #164/#257)

Date : 2026-10-29. Branche `fix/group-group-industry`. Rien n'est commité, `.claude/state/tdd-phase` non écrit. Specs : `docs/specs/154-group-contribution-price-source.md`, `docs/specs/170-group-roles-fees-character-id.md` (partie frais), `docs/specs/164-group-sale-input-validation.md` (partie borne R10).

Aucun squelette créé dans `src/` : les nouveaux contrats passent par des arguments en trop (ignorés par PHP aujourd'hui) ou en moins (TypeError attendue, voir #154 ci-dessous).

## Fichiers

### PHP, unitaires (lancés)

| Fichier | Changement | Rouges | Verts |
|---|---|---|---|
| `tests/Unit/Service/GroupIndustry/GroupIndustryContributionServiceTest.php` | réécrit (#154) : constructeur à 2 arguments `(GroupIndustryContributionRepository, EntityManagerInterface)`, tests `calculateMaterialValue` supprimés (R4), lignes de BOM à prix BOM distincts (Tritanium 5,5 ; Mexallon 47,25 ; Isogen 0 ; Morphite sans prix) | 38 (TypeError constructeur) | 0 |
| `tests/Unit/State/Processor/GroupIndustry/SubmitContributionProcessorTest.php` | constructeur du service à 2 arguments ; tests « WithoutJitaPrice » renommés « WithoutBomPrice » ; ajout E1 (5 500 au prix BOM) et E3 (400, message R2, ni persist ni Mercure) | 7 (TypeError) | 0 |
| `tests/Unit/State/Processor/GroupIndustry/ReviewContributionProcessorTest.php` | constructeur du service à 2 arguments, rien d'autre | 18 (TypeError) | 0 |
| `tests/Unit/State/Processor/GroupIndustry/CreateGroupProjectProcessorTest.php` | 7e argument `IndustryUserSettingsRepository` (stub, `findOneBy` rend les réglages seulement pour `['user' => owner]`) ; F1 à F8 en data provider (capture du `CreateProjectData` reçu par `createProject()`) ; `\RuntimeException` de borne → `BadRequestHttpException` même message ; `#[AllowMockObjectsWithoutExpectations]` ajouté (supprime 9 notices PHPUnit) ; 4 commentaires existants retirés | 4 (F2, F5, F6, F8) | 13 (F1, F3, F4, F7, mapping 400, 8 anciens) |
| `tests/Unit/Service/GroupIndustry/GroupProjectDefaultFeesTest.php` | nouveau (#170 R8) : `assertSame(3.6, …)` sur `CreateProjectData`, `GroupIndustryProject`, `GroupIndustryProjectResource` neufs (garde contre `0.036 * 100`) | 0 | 3 |
| `tests/Unit/Service/GroupIndustry/GroupIndustryBomGoldenCharacterizationTest.php` | 9 tests R10 (10 cas) + helper `createProjectFailure()` ; `testThirtyFiveThousandRunsOfHailL…` réécrit en `testNineThousandRunsOfHailL…` (3 500 lignes de job dépassaient la borne) | 6 | 4 R10 + Hail L 9 000 |
| `tests/Unit/ApiResource/Input/GroupIndustry/CreateGroupProjectInputValidationTest.php` | `testItemWithOneHundredThousandRunsCurrentlyPassesTheInputValidationIssue257` → `testItemWithOneHundredThousandRunsIsAcceptedByTheInputValidation`, sans `#[Group('issue-257')]` (garde `issue-164` de la classe) | 0 | 1 |

### Frontend (vitest, lancés ; `npm run build` OK, `frontend/dist` et `tsconfig.tsbuildinfo` restaurés)

| Fichier | Changement | Rouges | Verts |
|---|---|---|---|
| `frontend/tests/components/group-industry/contributeModalUnknownMaterialPrice.spec.ts` | test du matériau à prix : corps sans `estimatedValue` (V1) ; ajout Isogen à prix BOM 0 : corps sans `estimatedValue` (0 ≠ null) | 2 | 3 |
| `frontend/tests/components/group-industry/contributeModalJobInstallValue.spec.ts` | Pyerite 9,75 × 300 : corps sans `estimatedValue` | 1 | 3 |
| `frontend/tests/components/group-industry/contributeModalBomPriceSource.spec.ts` | nouveau (V3 à V6, R6) | 9 | 3 |

Verts frontend de ce fichier : aperçu « 1,250 ISK » (V1, déjà vrai) ; matériau sans prix BOM (EN et FR) sans libellé du prix BOM, sans ligne de date, champ de saisie présent (V6).

### Intégration

Aucun test d'intégration écrit : la spec n'en demande pas, et « rien persisté » est observé au niveau unitaire (`persist`/`flush` capturés).

## Preuve des raisons d'échec

### #154 : TypeError attendue, puis preuve sur assertion

Les 63 tests qui construisent `GroupIndustryContributionService` échouent par `TypeError: … Argument #2 ($jitaMarketService) must be of type App\Service\JitaMarketService, MockObject_EntityManagerInterface given`. C'est R4 (dépendance au marché supprimée, constructeur à 2 paramètres) : le contrat est figé dans les tests et la phase verte ne peut pas les modifier.

Pour prouver les raisons métier, les 3 fichiers ont été lancés une fois avec une variante temporaire (non conservée, fichiers restaurés depuis une copie) qui repasse un `JitaMarketService` simulé à prix courant différent (Tritanium 7,0 puis 9,0 ; Mexallon 51 ; Isogen 3 ; Morphite 7). Résultat : `Tests: 63, Failures: 16`, 47 verts, tous les échecs sur assertion :

- E1 / processor E1 : `7000.0 is identical to 5500.0` (prix Jita courant lu au lieu du prix BOM, E2) ;
- E8 deux membres : `7000.0 … 5500.0` ;
- non entier : `1683.0 … 1559.25` ; null explicite : `1400.0 … 1100.0` ; après rejet : `3500.0 … 2750.0` ;
- E4 prix BOM 0 : `900.0 … 0.0` ;
- E3, E3b, valeur 0 sur prix BOM, E4b, E6, E7, valeur négative, processor E3 et « sans prix BOM ni valeur » : `persist(...) was not expected to be called` (valeur reçue gardée, ou repli Jita à 700 enregistré : E9).

### Autres

- CreateGroupProjectProcessor : `Failed asserting that 3.6 is identical to 1.5` (F2), `… to 0.0` (F5), `… to 1.4234` (F6), `… to 4.5` (F8).
- Golden R10 : `Failed asserting that null is an instance of class RuntimeException` (6) : aucun refus aujourd'hui.
- Vitest (12) : `AssertionError` uniquement : corps `{…(3)}` au lieu de `{…(2)}` (estimatedValue encore envoyé), texte sans « BOM price, frozen at project creation » / « Prix BOM, figé à la création du projet » / « Method: BOM price × quantity » / « Frozen on 01/10/26 » / « Frozen on 09/14/26 » / « Figé le 01/10/26 », texte qui contient encore « weighted ».

### Suite Unit complète

`Tests: 13207, Errors: 63, Failures: 10, PHPUnit Notices: 242, Notices: 1, Incomplete: 1`. Les 73 sont exactement les rouges ci-dessus (63 TypeError de constructeur, 4 frais, 6 borne). Notice et incomplet préexistants (lot 4 : 251 notices PHPUnit, baisse due à `AllowMockObjectsWithoutExpectations`).

## Comptes mesurés (sonde temporaire, retirée)

Nitrogen Fuel Block, ME 10 / TE 20, plafond forcé à 1 : 989 runs → 999 lignes, 990 → 1 000, 991 → 1 001 (9 matériaux, 1 copie, N jobs) : B4 à B6 de la spec confirmés. Fenrir 1 run ME 10 / TE 20 → 113 lignes (B3). Hail L ME 2 / TE 4 plafond forcé à 1 : N runs → N + 42 lignes (26 matériaux, 5 copies, 1 invention, 6 réactions, 4 fabrications hors Hail L). Hail L 9 000 runs sans forçage : 958 lignes, 2 648 tentatives d'invention.

## Contrats figés pour la phase verte

- **#154** : `new GroupIndustryContributionService(GroupIndustryContributionRepository, EntityManagerInterface)`. Matériau à prix BOM (`estimatedPrice !== null`, 0 compris) sans valeur → `prix BOM × quantité` non arrondi. Valeur reçue `!== null` sur matériau à prix BOM (9 999,99 ; 5 500 ; 0) → `\DomainException` message exact `Type <typeId> has a BOM price: a material contribution is valued from it.`, ni persist ni flush. Sans prix BOM : valeur > 0 gardée (900 ; 0,01), sinon (null, 0, -50) message exact `No market price for type 11399: enter an estimated value.`. Croisement : job install, BPC et location de ligne (0) sur une ligne à prix BOM gardent leur valeur saisie (R7).
- **#154 front** : aucun `estimatedValue` dans le corps pour un matériau à prix BOM (12,5, 9,75, 0). Libellés EN « BOM price, frozen at project creation », « Frozen on {date} », « Jita, cheapest 5% of sell orders », « Method: BOM price × quantity » ; FR « Prix BOM, figé à la création du projet », « Figé le {date} », « Jita, 5 % des ordres de vente les moins chers », « Méthode : prix BOM × quantité » (espaces normalisés : insécable fine ou non acceptée). Date = `formatDate(currentProject.createdAt)` selon `dateFormat` (eu `01/10/26`, us `09/14/26`). Prix BOM 0 : libellé et date affichés, pas de total. Plus de « weighted » ni « pondéré ». Les noms de clés i18n ne sont pas testés directement (rendu seul).
- **#170 frais** : `CreateGroupProjectProcessor` reçoit `IndustryUserSettingsRepository` en **7e** argument ; lecture `findOneBy(['user' => $user])` ; `rate × 100` arrondi à 4 décimales (F3 `assertSame(3.6)`) ; défauts 3,6 gardés sur les trois classes (R8).
- **#164/#257 R10** : `\RuntimeException` dont le message contient le nombre produit et la limite comme nombres entiers sans séparateur (`1001`, `1000`) et « BOM lines » (Fenrir 1 000 runs) ; refus avant toute lecture de prix Jita (`pricedTypeIds === []`), BOM vide, aucun `persist`/`flush` via `createProject()` ; compte après `replaceT2BlueprintCopiesByInventions()` (Hail L 958 → 1 000 accepté, 959 → 1 001 refusé ; compter avant le remplacement donnerait 998 et accepterait : mutant tué) ; compte sur tout le plan (deux items 500 + 491 runs) ; rebuild refusé laisse lignes, contributions et appels de prix intacts. Le nom `MAX_BOM_LINES` n'est pas référencé par les tests.

## Exigences de la caractérisation réécrite (#257)

`…CurrentlyPassesTheInputValidationIssue257` devient un garde-fou du comportement voulu (absence de borne haute sur l'input), comme le demande la spec : plus de `Currently`, plus de `#[Group('issue-257')]`. Aucun autre test `Currently…Issue154/170/257` n'existe (`grep -rn "Currently.*Issue\(154\|170\|257\)" tests frontend/tests` vide).

## Recherche des formes figées

- `grep -rn "GroupIndustryContributionService(\|calculateMaterialValue" tests` : 3 constructions, toutes mises à jour ; plus aucun appel à `calculateMaterialValue`.
- `grep -rn "brokerFeePercent\|salesTaxPercent" tests` : `UpdateGroupProjectProcessorTest:106` attend 3,6 par défaut de l'entité (reste vert, R8 garde 3,6) ; `GroupIndustryProjectServiceTest:508` passe ses frais (inchangé).
- `grep -rln "ContributeModal\|weighted sell" frontend/tests` : `iconButtonsHaveName.spec.ts` et `groupIndustryDetailSalesFetch.spec.ts` stubbent la modale, rien à changer.
- Plans de plus de 1 000 lignes dans les tests : seul `testThirtyFiveThousandRunsOfHailL…` (réécrit). Les intégrations (`GroupIndustryBomPersistenceTest`) restent à 1 ou 25 runs.

## Points d'attention pour la phase verte

- Le texte de `testJobLinesCurrentlyHaveNoEstimatedPriceIssue156` (hors lot) n'est pas touché.
- La modale ouverte sans `currentProject` (cas des deux specs existantes, qui ne posent pas de projet) ne doit pas planter sur la ligne de date : ces tests le vérifient implicitement.
- Le glossaire (« Prix BOM », « Frais de courtage », « Taxe de vente ») est à compléter dans le commit d'implémentation.

## Message de commit proposé

```
test(group-industry): specify the BOM price of a material contribution, the default fees from the industry settings and the BOM line limit (#154, #170, #164, #257)
```
