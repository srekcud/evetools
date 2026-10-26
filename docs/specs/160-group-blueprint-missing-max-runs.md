# Spec #160 — BOM Group Industry : limite de runs absente du SDE = erreur explicite

Statut : **brouillon, rédigé sans Sylvain**. Les réponses recommandées sont appliquées dans la spec ; elles ne valent qu'après validation des questions de la section finale.

## Contexte

Faits vérifiés dans le code au 2026-10-26 (les numéros de ligne de l'issue sont périmés).

`GroupIndustryProjectService::splitJobsByMaxProductionLimit()` découpe les lignes de job de production de la BOM (fabrication et réaction) selon le `maxProductionLimit` de leur blueprint (formule pour une réaction). Les lignes de copie et d'invention ne sont ni lues ni découpées. La méthode charge les limites en une requête (`IndustryBlueprintRepository::findBy(['typeId' => …])`), puis lit `$maxRunsByBlueprint[$job['blueprintTypeId']] ?? null`. Quand la limite manque, elle en invente une : 500 pour une réaction, 10 000 pour une fabrication. Le découpage reste plausible mais arbitraire, sans erreur ni signal.

Deux autres constats :

- **Limite à 0 ou négative.** Elle n'est pas `null` et échappe au repli. La boucle de découpage prend alors `min($remaining, 0)` = 0 run par ligne : `$remaining` ne diminue jamais et la boucle ne se termine pas (épuisement mémoire). L'import écrit 0 quand le SDE ne donne pas de limite (`SdeBlueprintImporter`, `'max_production_limit' => $blueprint['maxProductionLimit'] ?? 0`).
- **Un seul appelant.** `buildBom()` n'est appelé que par `createProject()`, lui-même appelé par `CreateGroupProjectProcessor::process()`. Ce dernier convertit toute `\RuntimeException` en 400 avec son message, comme `TypeMissingFromSdeException` (#109, E7) ou `InventionProbabilityMissingException`. Il n'existe aucune régénération de BOM : les projets en base ne bougent pas.

Le projet solo applique déjà la règle cible. `IndustryProjectFactory::maxProductionLimitOf()` (#234 R7, #235 R7) lève une erreur qui nomme le blueprint quand la ligne `sde_industry_blueprints` manque ou que la limite est ≤ 0. Pas de valeur par défaut.

## Règles métier

- **R1 — Limite lue, jamais inventée.** Le découpage d'une ligne de fabrication ou de réaction exige le `maxProductionLimit` de son blueprint. Une ligne absente de `sde_industry_blueprints`, ou une limite ≤ 0, fait échouer la construction de la BOM. Aucune limite par défaut.
- **R2 — Toutes les limites manquantes d'un coup.** Les limites sont lues en une fois ; l'erreur est levée ensuite et liste chaque blueprint concerné une seule fois, triés par ordre croissant. Message : `Max production limit missing from the SDE for blueprints: 1011544, 1016656`. Une limite ≤ 0 compte comme manquante (Q2).
- **R3 — Exception dédiée.** Une exception dédiée de `src/Exception/`, sur le modèle de `TypeMissingFromSdeException`, étend `\RuntimeException` et porte la liste des blueprints. Aucun appelant n'est modifié : la création d'un projet répond 400 avec le message, et rien n'est persisté. L'exception est levée avant `persist()`.
- **R4 — Inchangé quand la limite est connue.** Le découpage reste celui d'aujourd'hui : des lignes pleines, puis le reste (35 runs, limite 10 : 10 + 10 + 10 + 5). Les lignes de copie et d'invention ne demandent aucune limite. Un blueprint T1 qui ne sert qu'à la copie pour une invention n'a donc pas besoin de ligne `sde_industry_blueprints`.
- **R5 — Périmètre du plafonnement inchangé.** Toutes les lignes de fabrication et de réaction restent plafonnées, y compris les fabrications sans activité de copie et les T2. L'alignement sur #234 (seulement les fabrications copiées, répartition égale) reste un sujet séparé (Q3).

## Exemples chiffrés

SDE golden (`GroupIndustryBomGoldenCharacterizationTest`). Les blueprints golden ont pour identifiant typeId + 1 000 000 (`BLUEPRINT_TYPE_ID_OFFSET`). Les limites viennent de `sde-blueprint-recipes.json` (`maxProductionLimit`), sauf mention. Colonnes des lignes de job : `[typeId, activité, groupe, runs, ME, TE, quantité requise, parent]`.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| L1 | Widow 1 run, toutes les limites golden déclarées : Titanium Diborite Armor Plate (limite 20 000), 18 750 runs | une ligne de 18 750 runs + copie 18 750 | inchangé (garde-fou, R4) |
| L2 | Widow 1 run, blueprint de Titanium Diborite Armor Plate (1 011 544) absent des limites | `[11544, manufacturing, component, 10000, …]`, `[11544, manufacturing, component, 8750, …]`, copie 18 750 (`testManufacturingJobMissingFromTheSdeLimitsIsSplitAt10000Runs`) | exception, blueprints `[1011544]`, message `Max production limit missing from the SDE for blueprints: 1011544`, BOM vide |
| L3 | Fernite Alloy 100 001 runs saisis (501 runs de réaction, #2), formule 1 016 656 absente des limites | `[500, 100000]` et `[1, 200]` (`testReactionMissingFromTheSdeLimitsIsSplitAt500RunsIssue2`) | exception, blueprints `[1016656]`, BOM vide |
| L4 | L3 avec la limite golden de la formule (1 000) | — (le harnais ne la déclare pas) | une ligne `[16656, reaction, final, 501, 0, 0, 100200, null]` (garde-fou, R4) |
| L5 | Widow 1 run, limite de 1 011 544 à 0 | la boucle de découpage ne se termine pas | exception, blueprints `[1011544]` |
| L6 | Widow 1 run, limite de 1 011 544 à -1 | la boucle ne se termine pas | exception, blueprints `[1011544]` |
| L7 | deux blueprints de production absents, dont un utilisé par deux lignes de job (ex. composant final et intermédiaire) | lignes découpées à 10 000 ou 500 | une seule exception, chaque blueprint une fois, ordre croissant |
| L8 | Hail L 1 run, limites déclarées pour tous les blueprints et formules de production, aucune pour Depleted Uranium L Blueprint (898), copié pour l'invention | BOM construite | inchangé : aucune erreur, lignes `invention` et `copy` présentes (R4) |
| L9 | Fenrir 1 run, limite de Capital Propulsion Engine déclarée à 15 | `[15, …]`, `[5, …]`, copie 20 | inchangé (garde-fou, `testJobAboveTheMaxProductionLimitOfItsBlueprintIsSplitButNotItsCopy`) |
| L10 | `createProject()` sur L2 | projet persisté avec 10 000 + 8 750 | exception, aucune entité persistée (modèle `testInventionWithoutProbabilityPersistsNoProject`) |
| L11 | `POST /group-industry/projects` sur L2 (processor) | 201 | 400, message de R2 |

## Cas limites

- **Produit sans blueprint ni réaction.** Refusé plus tôt, inchangé : 400 « No blueprint or reaction found for: … » (`CreateGroupProjectProcessor::resolveItemTypeIds()`), ou « No blueprint or reaction found » de l'arbre.
- **Limite manquante et autre erreur à la fois.** Si un type manque aussi dans `invTypes` (#109), ou qu'une probabilité d'invention manque, l'erreur levée la première reste la première. Ces deux erreurs viennent du parcours de l'arbre et de `replaceT2BlueprintCopiesByInventions()`, qui passent avant le découpage.
- **Runs égaux à la limite.** Une seule ligne (`testJobAtTheMaxProductionLimitOfItsBlueprintIsNotSplit`).
- **Projets déjà en base.** Inchangés : leur BOM n'est jamais reconstruite, et aucune migration n'est prévue.

## Hors périmètre

- **Alignement sur #234.** Répartition égale, plafond réservé aux fabrications copiées, réactions non plafonnées (Q3).
- **Runs d'un BPC T2 inventé** comme plafond de la fabrication T2.
- **`SdeBlueprintImporter`.** Il écrit 0 pour une limite absente (`?? 0`) ; R2 traite déjà ce 0 comme une limite manquante. Corriger l'import relève d'un sujet séparé.
- **`IndustryProjectFactory`** (projet solo), déjà conforme (#234, #235).

## Tests existants appelés à changer

- **`GroupIndustryBomGoldenCharacterizationTest`.**
  - Le harnais démarre avec `maxProductionLimits = []` : tout test qui construit une ligne de fabrication ou de réaction lèverait l'erreur. Les limites par défaut doivent venir de `maxProductionLimit` des recettes golden (renseigné pour les 96 recettes). Les tests qui les surchargent (15, 20) gardent leur valeur.
  - La note de câblage recensera les résultats qui changent quand les vraies limites remplacent 500 et 10 000.
  - `testManufacturingJobMissingFromTheSdeLimitsIsSplitAt10000Runs` est remplacé par L2, et `testReactionMissingFromTheSdeLimitsIsSplitAt500RunsIssue2` par L3.
  - `testEachLineOfASplitReactionJobRequiresItsOwnRunsTimesTheUnitsPerRun` (#107) n'obtient son découpage que par le repli de 500. Il doit déclarer une limite explicite (500) pour garder son intention.
- **`GroupIndustryProjectServiceTest`.**
  - Le `setUp()` renvoie `findBy → []` : tout test qui construit une BOM avec une ligne de job lèverait l'erreur. Il faut un stub par défaut qui renvoie une limite pour chaque blueprint demandé.
  - `testBuildBomUsesDefaultLimitWhenBlueprintNotInSde` est remplacé par un test de l'erreur.
- **Sans changement.** `GroupIndustryBomPersistenceTest` insère déjà la limite du Nitrogen Fuel Block (200). `CreateGroupProjectProcessorTest` remplace le service par un stub.

## Glossaire

Proposé, à ajouter seulement après validation : compléter **Plafond de runs d'un BPC**. Group Industry plafonne par `maxProductionLimit` toutes ses lignes de fabrication et de réaction, par lignes pleines puis le reste. Une limite absente du SDE, ou ≤ 0, fait échouer la création du projet (spec #160). Symbole : `GroupIndustryProjectService::splitJobsByMaxProductionLimit()`.

## 🚦 Questions pour Sylvain

1. **Erreur ou état inconnu ?** Faut-il faire échouer la création du projet (400, rien de persisté), comme #109 et #234 R7, plutôt que créer le projet avec une ligne de job « limite inconnue » ? Une telle ligne demanderait une nouvelle colonne, une migration et un affichage. Recommandé : l'erreur.
2. **Limite ≤ 0.** La traiter comme une limite absente, avec la même erreur ? Recommandé : oui. Aujourd'hui elle fait tourner le découpage sans fin.
3. **Périmètre.** Garder le plafonnement de toutes les lignes de fabrication et de réaction, et ne retirer que les valeurs inventées ? Recommandé : oui. L'alignement sur #234 (fabrications copiées seulement, répartition égale) fera l'objet d'une issue séparée. Avec ce choix, une formule de réaction absente du SDE bloque la création, alors que #234 n'en aurait pas besoin.
4. **Une seule erreur pour tous les blueprints manquants.** Les lister tous, triés, plutôt que de s'arrêter au premier comme `IndustryProjectFactory::maxProductionLimitOf()` ? Recommandé : oui, car les limites sont déjà lues en une requête, et c'est la règle de #109 R2.
