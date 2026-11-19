# Lot 6 Group Industry : phase rouge (#257 bornes des saisies, plafond de 5000 lignes, suites de revue)

Date : 2026-10-29. Branche `fix/group-group-industry`. Rien n'est commité. Spec : `docs/specs/257-group-input-bounds-and-review-followups.md`.

## ARRÊT

Aucun arrêt sur B6 : **35 000 runs de Hail L donnent 3 616 lignes de BOM** (mesuré, message de refus actuel « The production plan has 3616 BOM lines… »), sous 5 000. Le test le fige (`assertCount(3_616, …)`).

**Blocage d'exécution (intégration)** : `.claude/scripts/wt-php.sh … integration` refuse de démarrer : `service "app" is not running` / `DATABASE_URL illisible dans le conteneur app (stack arrêtée ?)`. Conformément à la consigne, aucun autre chemin n'a été tenté. Les tests d'intégration sont écrits et passent `php -l`, **non exécutés** : à lancer stack démarrée avant le vert.

## Squelette dans `src/` (signalé)

- `src/Repository/GroupIndustryContributionRepository.php` : `public function findContributedBomItemIds(GroupIndustryProject $project): array { throw new \LogicException('not implemented'); }`. Nécessaire pour que le double (`createStub(...)->method('findContributedBomItemIds')`) se configure et que le test d'intégration échoue sur l'exécution plutôt que sur une méthode absente.

## Fichiers et résultats (unitaires lancés)

| Fichier | Changement | Rouges | Verts |
|---|---|---|---|
| `tests/Unit/ApiResource/Input/GroupIndustry/CreateGroupProjectInputValidationTest.php` | R2 (I1-I5, S1-S5, garde-fous de forme), R3 (L1-L4 sur les trois listes), R5 (F1-F6 par champ), R9 (F7, F8, sens inverse) | 15 | 61 |
| `tests/Unit/ApiResource/Input/GroupIndustry/UpdateGroupProjectInputValidationTest.php` | nouveau : F1-F8 à la mise à jour | 4 | 14 |
| `tests/Unit/ApiResource/Input/GroupIndustry/SubmitContributionInputValidationTest.php` | nouveau : N1-N4 | 1 | 4 |
| `tests/Unit/State/Processor/GroupIndustry/CreateGroupProjectProcessorTest.php` | R9 frais effectifs (F9 + 2 variantes refusées, 2 cas à 100 acceptés) ; texte du test de borne aligné sur `5001` / `5000` et le message réel | 3 | 19 |
| `tests/Unit/State/Processor/GroupIndustry/UpdateGroupProjectProcessorTest.php` | F10, F11 (avec un nom envoyé : rien ne change), F12, et courtage seul à 96,4 accepté | 2 | 7 |
| `tests/Unit/Service/GroupIndustry/GroupIndustryBomGoldenCharacterizationTest.php` | B1-B6, B8 réécrits à 5000 ; 35 000 runs de Hail L rétabli ; double du repository (R8) ; 5 tests R8 | 15 (4 erreurs, 11 échecs) | 100 |
| `tests/Unit/Service/GroupIndustry/GroupIndustryContributionServiceTest.php` | R7 : les trois tests `submitAutoDetected` supprimés (helpers gardés, encore utilisés par `submit()`) | 0 | inchangé |
| `tests/Unit/Service/GroupIndustry/GroupIndustryProjectServiceTest.php` | 8e argument du constructeur : stub `GroupIndustryContributionRepository` | 0 | inchangé |

Suite Unit complète : `Tests: 13319, Errors: 4, Failures: 36, PHPUnit Notices: 245, Notices: 1, Incomplete: 1`. Les 40 sont exactement les rouges ci-dessus (classes vérifiées une à une). Les classes touchées ne lèvent aucune notice PHPUnit (`--display-phpunit-notices`) ; notice et incomplet préexistants.

### Intégration (écrits, non exécutés : voir ARRÊT)

| Fichier | Tests |
|---|---|
| `tests/Integration/Repository/GroupIndustryContributionRepositoryTest.php` | lignes contribuées de tout statut, une fois chacune (Pyerite : pending + rejected de deux membres), contribution sans ligne ignorée ; autre projet exclu ; projet dont la seule contribution n'a pas de ligne → `[]` |
| `tests/Integration/Service/GroupIndustry/GroupIndustryBomPersistenceTest.php` | constructeur à 8 arguments ; tableau R8 (Robotics contribuée par SQL après le chargement, collection initialisée vide, Robotics retirée de la recette → ligne gardée 1/0 même id, 1 contribution, 11 lignes) ; nombre de requêtes sur `group_industry_contributions` pendant `buildBom()` = 1 pour 11 et 35 lignes (plafond SDE passé à 1 par SQL, collection initialisée avant la mesure) |

Raisons d'échec attendues aujourd'hui : repository → `LogicException: not implemented` ; R8 → ligne Robotics supprimée (cascade sur la contribution), puis 0 requête au lieu de 1 (la collection déjà chargée ne requête plus).

### Frontend (vitest lancé ; `npm run build` OK, `frontend/dist` et `tsconfig.tsbuildinfo` restaurés)

| Fichier | Changement | Rouges | Verts |
|---|---|---|---|
| `frontend/tests/components/group-industry/contributeModalBomPriceSource.spec.ts` | `describe` ajouté : EN « Auto-calculated », « Unit price: », aucun chemin de clé `groupIndustry.contribute` rendu (garde-fou, vert aujourd'hui car en dur) ; FR « Calculé automatiquement », « Prix unitaire : », ni « Auto-calculated » ni « Unit price » | 1 | 13 |

Aucun espace insécable littéral dans la spec (vérifié par `grep -P '\x{00A0}|\x{202F}'`) : `modalText()` normalise les espaces.

## Preuve des raisons d'échec

- Input création : tableaux vides au lieu de `['items[0][runs]']`, `['items']`, `['items', 'items[1][runs]']`, `['blacklistTypeIds']`, `['blacklistGroupIds']`, `['blacklistCategoryKeys']`, `['blacklistCategoryKeys', 'blacklistGroupIds']`, `['brokerFeePercent']`, `['salesTaxPercent']` ; cas « runs négatifs à côté de 100 001 » : `['items[1][runs]']` seul au lieu de `['items', 'items[0][runs]', 'items[1][runs]']`.
- Input mise à jour : `[]` au lieu de `['brokerFeePercent']`, `['salesTaxPercent']` (100,01 ; 60 + 40,01 ; 40,01 + 60).
- Contribution : `[]` au lieu de `['quantity']` pour 2 147 483 648.
- Processor création : `Failed asserting that null is an instance of class ApiPlatform\Validator\Exception\ValidationException` (3 cas).
- Processor mise à jour : `flush() was not expected to be called` (F11, F12 acceptés aujourd'hui).
- Golden, erreurs : `The production plan has 4999 / 5000 / 3616 / 5000 BOM lines, above the limit of 1000…` (B1, B2, B6, B4 refusés par la limite actuelle).
- Golden, échecs de borne : `'…has 5001 BOM lines, above the limit of 1000…' matches /…5000…/` (B3, B3 via `createProject()`, B5, plan de deux items, rebuild) et `'…27257…1000…' matches /…5000…/` (B8).
- Golden, R8 : Robotics supprimée (`[]` au lieu de l'objet), Robotics gardée (collection seule, attendu supprimée), ligne d'identifiant le plus bas gardée au lieu de la ligne contribuée en base, `[]` au lieu d'un appel au repository (11 et 260 lignes).
- Vitest : `expected '…' to contain 'Calculé automatiquement'` (texte en dur « Auto-calculated », « Unit price: »).

## Comptes mesurés (sonde temporaire dans le golden, retirée ; le message de refus actuel donne le compte)

- Hail L ME 2 / TE 4, plafond d'origine, 35 000 runs : **3 616 lignes** (B6) ; 9 000 runs : 958 (B7, inchangé).
- Fenrir ME 10 / TE 20, 5 000 runs : **27 257 lignes** (B8), figé dans le test.
- Nitrogen Fuel Block plafond forcé à 1 : 4 989 → 4 999, 4 990 → 5 000, 4 991 → 5 001 (B1-B3 de la spec confirmés).
- **Hail L plafond forcé à 1 : 4 954 → 5 000, 4 955 → 5 001.** La spec (B4, B5) annonçait 4 958 / 4 959 par `runs + 42` : faux à cette échelle (4 958 → 5 004, 4 959 → 5 005 ; 46 lignes hors jobs finaux au lieu de 42, des composants dépassent leur plafond). Les tests utilisent les valeurs mesurées ; la table B4/B5 de la spec est à corriger.
- 35 000 runs de Hail L : 3 500 jobs finaux, 10 295 tentatives, datacores 10 295 et 20 590 repris du test d'origine (`git show a5f53ad3^`), non réexécutables avant le vert (refus à 1000).

## Contrats figés pour la phase verte

- **R1** : `MAX_BOM_LINES = 5000`, refus strict au-delà, message avec le compte et la limite en entiers sans séparateur ; refus avant toute lecture de prix, rien persisté. Le test du processor attend le message réel `The production plan has 5001 BOM lines, above the limit of 5000 BOM lines per project.` (exception simulée).
- **R2** : `items[i][runs]` ≤ 100 000 (violation sur le chemin de l'item, validation de la propriété `items` seule) ; somme des runs ≤ 100 000 en **contrainte de classe**, violation sur `items` (validation de l'objet). Un item seul à 100 001 donne donc `['items', 'items[0][runs]']` en validation complète (somme 100 001 > 100 000 : lecture littérale de R2 et de I4). Ne comptent que les `runs` entiers ≥ 1 : texte `'5'`, décimal `2.5`, clé absente, item non objet et runs négatifs n'entrent pas dans la somme.
- **R3** : `Count(max: 200)` sur `blacklistTypeIds`, `blacklistGroupIds`, `blacklistCategoryKeys`.
- **R4** : `quantity` de 1 à 2 147 483 647.
- **R5** : `Range(0, 100)` sur les deux frais, création et mise à jour ; `null` accepté.
- **R9** : deux frais envoyés → violation de classe sur `salesTaxPercent` (création et mise à jour) ; un seul envoyé → l'input n'en dit rien (`testBrokerFeeOfOneHundredSentWithoutSalesTaxIsLeftToTheProcessor`) et le processor lève `ApiPlatform\Validator\Exception\ValidationException` (422) avec une seule violation de chemin `salesTaxPercent`, sur les valeurs effectives (réglages ou défaut 3,6 à la création, valeur enregistrée à la mise à jour) ; aucun `createProject()`, aucun flush, projet inchangé (nom compris). Sommes à 100 exactement acceptées (96,4 + 3,6 ; 98,5 + 1,5 ; vérifié en PHP : `== 100.0`).
- **R6** : clés `groupIndustry.contribute.autoCalculated` et `unitPrice` (noms non testés directement : rendu seul).
- **R8** : `GroupIndustryContributionRepository::findContributedBomItemIds(GroupIndustryProject $project): list<string>` (RFC 4122), **8e argument** du constructeur de `GroupIndustryProjectService` ; aucun appel pour un projet sans ligne ; exactement un appel, avec le projet, pour un rebuild (11 et 260 lignes) ; la collection en mémoire est ignorée (une contribution qui n'y figure que là ne protège plus) ; l'ensemble sert aussi au tri des lignes de même clé à runs égaux.

## Décisions prises dans les tests

- **Double du repository (golden)** : il renvoie les identifiants des lignes des contributions en mémoire, ou la liste posée par le test (`contributedBomItemIdsInTheDatabase`). Les identifiants ne sont pas posés par le double mais par le helper `contribution()` (une ligne contribuée est forcément persistée), au format séquentiel `10000000-0000-4000-8000-%012d` ; `persistLines()` fait de même pour toute la BOM. Ainsi le vert peut tester « aucune ligne » comme « aucune ligne persistée » sans casser les 18 tests de contribution, qui restent verts aujourd'hui et ne changent pas d'assertion.
- **R7** : aucun test rouge (suppression de code mort, non observable) ; seuls les trois tests disparaissent.
- Données distinctes par type : Fenrir/Scorpion/Ishtar pour les runs ; plages 30001-30201, 401-601, `category_N` pour les listes ; Tritanium/Pyerite/Mexallon/Isogen/Morphite/Zydrine/Nocxium pour le repository.

## Recherche des formes figées

- `grep -rn "MAX_BOM_LINES\|limit is 1000\|1001 BOM\|1000 BOM" tests frontend/tests` : vide après réécriture.
- `grep -rn "Currently.*Issue257\|Currently.*Issue164\|CARACTÉRISATION" tests frontend/tests` : seul `MarketServiceTest.php:212` (#26, hors lot). Aucun test de caractérisation dans ce lot (exigences a-e de `/characterize` sans objet).
- `grep -rn "submitAutoDetected" tests frontend/tests` : vide.
- `grep -rn "new GroupIndustryProjectService(" tests` : 3 constructions, toutes à 8 arguments.
- `grep -rln "Auto-calculated\|Unit price" frontend/tests` : seulement la spec modifiée.
- Frais dans `tests/` et `frontend/tests/` : toutes les valeurs ≤ 3,6 ; quantités de contribution sous la colonne.

## Points d'attention pour la phase verte

- Lancer l'intégration stack démarrée (`.claude/scripts/wt-php.sh … integration …` sur les deux fichiers).
- Spec #257 : corriger B4/B5 (4 954 / 4 955) et noter B6 = 3 616, B8 = 27 257.
- `make infection FILTER=src/Service/GroupIndustry/GroupIndustryProjectService.php` : B2/B3 (et B4/B5) tuent `>` / `>=`.

## Message de commit proposé

```
test(group-industry): specify the input bounds, the 5000 BOM line limit and the contributed lines read from the database (#257, #164)
```
