# Lot 6 Group Industry : phase verte (#257)

Date : 2026-10-29. Branche `fix/group-group-industry`. Rien n'est commité. `tests/` et `frontend/tests/` non touchés.

## Changements

| Fichier | Changement |
|---|---|
| `src/Service/GroupIndustry/GroupIndustryProjectService.php` | `MAX_BOM_LINES = 5000` ; 8e argument `GroupIndustryContributionRepository` ; `reconcileBomLines()` ne lit plus `getContributions()` : `contributedLineIdsOf()` (aucun appel si la BOM du projet est vide, sinon un appel) et rapprochement sur `getId()->toRfc4122()` pour le tri et la suppression |
| `src/Repository/GroupIndustryContributionRepository.php` | `findContributedBomItemIds()` : `SELECT DISTINCT IDENTITY(c.bomItem)`, `c.project = :project`, `c.bomItem IS NOT NULL`, `list<string>` (squelette remplacé) |
| `src/ApiResource/Input/GroupIndustry/CreateGroupProjectInput.php` | `Range(1, 100000)` sur `runs` ; `Callback` sur la somme des runs (violation sur `items`, ne compte que les `runs` entiers >= 1) ; `Count(max: 200)` sur les trois blacklists ; `Range(0, 100)` sur les frais ; `Callback` sur la somme des frais |
| `src/ApiResource/Input/GroupIndustry/UpdateGroupProjectInput.php` | `Range(0, 100)` sur les frais ; `Callback` sur la somme des frais |
| `src/ApiResource/Input/GroupIndustry/GroupProjectFees.php` (nouveau) | une seule règle R9 : `validateSentFees()` (contexte de validation) et `rejectEffectiveFeesAboveMaximum()` (`ValidationException` 422 sur `salesTaxPercent`, même précédent que `ProductionStructureRigsValidator`) |
| `src/ApiResource/Input/GroupIndustry/SubmitContributionInput.php` | `LessThanOrEqual(2147483647)` sur `quantity` |
| `src/State/Processor/GroupIndustry/CreateGroupProjectProcessor.php` | frais effectifs (réglages ou défaut) calculés une fois, contrôlés avant `createProject()` |
| `src/State/Processor/GroupIndustry/UpdateGroupProjectProcessor.php` | somme effective contrôlée avant toute écriture, seulement si un frais est envoyé (un projet existant à somme > 100 reste renommable) |
| `src/Service/GroupIndustry/GroupIndustryContributionService.php` | `submitAutoDetected()` supprimée (aucun import orphelin : `incrementFulfillment` et `validateAntiDuplicate` servent encore) |
| `frontend/src/components/group-industry/ContributeModal.vue`, `en.json`, `fr.json` | clés `groupIndustry.contribute.autoCalculated` et `unitPrice` (pas d'espace insécable littéral) |
| `docs/specs/257-...md` | B4/B5 corrigés (4 954 / 4 955), B6 = 3 616, B8 = 27 257, note `runs + 42` fausse, règle de blocage Q1 retirée |

## Résultats

- Unit complète : `Tests: 13319, Assertions: 22832, PHPUnit Notices: 245, Notices: 1, Incomplete: 1` : 0 erreur, 0 échec (avant : 4 erreurs, 36 échecs).
- Deptrac : 0 violation, 0 erreur.
- PHPStan (fichiers touchés) : reste le bruit connu `Uuid|null` (`GroupIndustryContainerService:182`, `GroupIndustryDistributionService:66`, `UpdateGroupProjectProcessor:121`, préexistants), le trait `ResolvesSaleOfProject` non utilisé et le motif ignoré `property.unusedType` non apparié ; rien dans le code ajouté.
- Frontend : `npm run test` 129 fichiers / 949 tests, code 0 ; `lint` 0 erreur (117 avertissements préexistants) ; `build` OK, `frontend/dist` et `tsconfig.tsbuildinfo` restaurés.

## Non exécuté

- Intégration (stack arrêtée) : `GroupIndustryContributionRepositoryTest` et `GroupIndustryBomPersistenceTest` à lancer stack démarrée. Relus contre le code : construction à 8 arguments (`getContainer()->get(GroupIndustryContributionRepository::class)`), nom et signature de la méthode, retour en chaînes RFC 4122 (`getSingleColumnResult` + `strval`, même précédent que `sumApprovedValueByProject`). Point à confirmer sur PostgreSQL : le format renvoyé par `IDENTITY(c.bomItem)` (UUID natif, attendu hyphéné).
- Infection (`make infection FILTER=src/Service/GroupIndustry/GroupIndustryProjectService.php`) : non lancé ici.

## Points d'attention

- Aucune migration, aucune commande à rejouer en production.
- Précondition R8 : la base fait foi ; tout futur appelant de `buildBom()` doit flusher avant.
