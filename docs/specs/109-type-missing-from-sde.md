# Spec #109 — Arbre de production : un type absent du SDE lève une erreur explicite

## Contexte

`IndustryTreeService` lit le nom de chaque type dans `invTypes` (produit d'un nœud, `IndustryTreeService.php:58-59` ; matériau, `:137-138`). Un type absent reçoit le nom inventé `"Type #<typeId>"`, qui se propage dans les étapes générées, la liste d'achats et les exports. Comme les recettes viennent elles aussi du SDE, ce cas ne se produit que si l'import SDE est incohérent (une recette référence un type absent de `invTypes`) : la bonne réponse est de signaler l'import à refaire, pas d'inventer un nom.

Les appelants traitent déjà l'erreur « No blueprint or reaction found » (`RuntimeException`) : création de projet → 400 avec le message (`CreateProjectProcessor`), vue d'un projet → arbre à `null` (`ProjectProvider`), liste d'achats → produit retiré en silence (#20).

## Règles métier

- **R1 — Erreur explicite.** Quand au moins un type de l'arbre (produit d'un nœud ou matériau, y compris un matériau exclu par la liste noire) est absent de `invTypes`, `buildProductionTree()` lève une exception dédiée au lieu de renvoyer un arbre. Aucun nom n'est inventé.
- **R2 — Tous les types absents.** L'arbre est parcouru jusqu'au bout avant de lever l'erreur. L'exception porte la liste des typeIds absents, chacun une fois, triés par ordre croissant. Message : `Types missing from the SDE: 4051, 9848`.
- **R3 — Même chemin que les erreurs existantes.** L'exception étend `\RuntimeException`. Aucun appelant n'est modifié : 400 avec ce message à la création d'un projet, comportements silencieux existants inchangés ailleurs.
- **R4 — Priorité.** Si le produit racine n'a ni blueprint ni réaction, l'erreur « No blueprint or reaction found » reste levée, comme aujourd'hui.

## Exemples chiffrés

| # | Situation | Entrée | Attendu |
|---|---|---|---|
| E1 | Tous les types connus | arbre de Nitrogen Fuel Block (4051), 1 run | arbre inchangé, noms du SDE |
| E2 | Produit racine absent de `invTypes` | 4051 sans nom | exception, `missingTypeIds = [4051]`, message `Types missing from the SDE: 4051` |
| E3 | Matériau absent de `invTypes` | Robotics (9848) sans nom, arbre de 4051 | exception, `missingTypeIds = [9848]` |
| E4 | Produit et matériau absents | 4051 et 9848 sans nom | exception, `missingTypeIds = [4051, 9848]`, message `Types missing from the SDE: 4051, 9848` |
| E5 | Intermédiaire absent, utilisé dans deux branches | un intermédiaire sans nom présent à deux endroits de l'arbre | son typeId une seule fois dans la liste |
| E6 | Matériau exclu par la liste noire et absent | matériau exclu sans nom | exception, son typeId dans la liste |
| E7 | Création d'un projet sur un arbre incomplet | `POST` création de projet, 9848 sans nom | 400, message `Types missing from the SDE: 9848` |
| E8 | Produit racine sans recette | type sans blueprint ni réaction | `No blueprint or reaction found for product type ID …`, comme aujourd'hui |
| E9 | BOM d'un projet Group Industry | item 4051 ; 4051 et 9848 sans nom | exception, `missingTypeIds = [4051, 9848]`, BOM laissé vide |
| E10 | Aperçu d'import stockpile | aperçu de 4051 ; 4051 et 9848 sans nom | exception, `missingTypeIds = [4051, 9848]` |

## Cas limites

- Un intermédiaire sans recette (traité comme matériau non fabricable) mais connu de `invTypes` : comportement inchangé.
- L'exception expose les typeIds (`getMissingTypeIds()`) pour un usage futur ; aucun appelant ne la lit dans ce ticket.

## Hors périmètre

- `TypeNameResolver` / `IndustryCalculationService::resolveTypeName()` et la vingtaine d'autres `"Type #id"` (étapes et achats enregistrés, assets, PVE, marché, PI, mining) : #115.
- Les `catch (\RuntimeException)` silencieux de `ProjectProvider` et de la liste d'achats (#20).
- Toute modification de `IndustryProjectFactory` (en cours sur #111).

## Glossaire

Aucun terme ajouté.

## Tests impactés

Trois tests figent le bug et sont remplacés par un test de l'erreur :

- `ProjectProviderGoldenTreeCharacterizationTest::testTypeWithoutSdeNameCurrentlyGetsAPlaceholderName` → E4 ;
- `GroupIndustryBomGoldenCharacterizationTest::testTypeMissingFromTheSdeTypesIsNamedByItsTypeId` → E9 ;
- `StockpileGoldenImportCharacterizationTest::testPreviewNamesAnItemMissingFromTheSdeTypesByItsTypeId` → E10.
