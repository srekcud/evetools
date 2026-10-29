# Spec #193 — Arbre de production : les produits en erreur sont signalés au lieu de faire disparaître l'arbre sans message

**Statut : rédigée le 2026-10-29 sans entretien, validée par Sylvain (T1 : pas d'arbre partiel, T2 : avertissement toujours affiché, le 2026-10-29).** Les choix faits en autonomie sont dans « Décisions prises en autonomie », avec leur raison. Les décisions produit sont dans « À trancher par Sylvain », avec la recommandation retenue par cette spec : à confirmer avant la phase rouge. Aucun montant affiché ne change.

## Contexte

Quand un type d'un projet est absent du SDE, l'onglet Étapes n'affiche pas l'arbre et ne dit ni pourquoi ni quel type est en cause.

État vérifié dans le code au 2026-10-29 (les lignes citées par l'issue ont bougé) :

- `ProjectProvider::provide()` ne capte plus de `RuntimeException` : il fait `$resource->tree = $this->productionTree->buildTree($project)`.
- `ProductionTreeFromSteps::buildTree()` appelle `ProductionStepNeedsResolver::resolve()`. Il renvoie `null` si la racine n'est pas dans `needsByProduct` (racine sans étape de production, ou sans recette). `nodeOf()` renvoie `null` pour un **produit en erreur** (`StepProductNeed::$isFailed`), et ce `null` remonte jusqu'à la racine : l'arbre entier vaut `null`, sans motif.
- `resolve()` calcule déjà la liste des produits en erreur (`ProductionStepNeeds::$failedProducts`, `{typeId, reason}`, triée par typeId croissant) : « No blueprint or reaction found for product type ID … » ou le message de `TypeMissingFromSdeException` (« Types missing from the SDE: … »). La liste d'achats, l'estimation de coût et le statut du Stockpile l'exposent ; `ProjectResource` ne l'expose pas.
- `StepsTab.vue` affiche `industry.stepsTab.treeUnavailable` (« Arbre non disponible ») dans la vue hiérarchique seulement ; la vue à plat ne dit rien.
- `ShoppingFailedProductsWarning.vue` (onglets Liste d'achats et Coût) liste les produits en erreur avec leur raison ; sans nom connu, il affiche le typeId seul.
- La réponse d'un `PATCH` ou d'un `POST` de projet (`IndustryResourceMapper::projectToResource()`) ne calcule ni étapes ni arbre ; le store front (`updateProject`) garde alors les `steps` et le `tree` déjà chargés.

Règle du projet (CLAUDE.md) : une donnée SDE manquante remonte une erreur explicite ou un état « inconnu », jamais une valeur par défaut.

## Règles métier

- **R1 — Produits en erreur du projet.** `GET /industry/projects/{id}` porte un champ `failedProducts` : la liste des **produits en erreur** du projet, `{typeId, reason}`, la même que celle de la liste d'achats du même projet (mêmes entrées, même ordre : typeId croissant). Aucun nouveau calcul : c'est `ProductionStepNeeds::$failedProducts` de la passe qui construit l'arbre.
- **R2 — Une seule passe.** Un `GET` de projet résout les besoins des étapes une seule fois : l'arbre et `failedProducts` viennent du même appel à `ProductionStepNeedsResolver::resolve()`.
- **R3 — Arbre.** `tree` est inchangé : `null` quand la racine n'a ni étape de production ni recette, ou quand un nœud affiché est un produit en erreur (R7 de la spec #121, complété : le `null` est désormais accompagné de `failedProducts`). Aucun arbre partiel (T1).
- **R4 — Tous les produits en erreur, affichés ou non.** `failedProducts` liste aussi les produits en erreur absents de l'arbre affiché (produit blacklisté, étape racine ajoutée à la main). Un arbre peut donc être affiché avec un `failedProducts` non vide (T2).
- **R5 — Non calculé n'est pas vide.** `failedProducts` vaut `[]` quand le projet n'a aucun produit en erreur, et `null` dans une réponse qui ne le calcule pas (`PATCH`, `POST` du projet). `null` ne veut jamais dire « aucune erreur ».
- **R6 — Écran.** `StepsTab.vue` affiche `ShoppingFailedProductsWarning` dès que `failedProducts` n'est pas vide, une seule fois, au-dessus du contenu des deux vues (à plat et hiérarchique), avec le message `industry.stepsTab.failedProductsWarning`. Chaque ligne : nom du produit, puis la raison telle que l'API la donne. La vue hiérarchique garde « Arbre non disponible » sous l'avertissement quand `tree` est `null`.
- **R7 — Nom du produit.** Le nom vient des étapes du même projet (`productTypeName` de l'étape dont le produit a ce typeId), comme l'onglet Liste d'achats. Nom absent (type absent du SDE) : « Nom inconnu (#typeId) » / « Unknown name (#typeId) » (`common.unknownTypeName`, règle **Nom de type inconnu** #115), jamais le typeId seul ni un nom inventé. Cette règle s'applique au composant, donc aussi aux onglets Liste d'achats et Coût.
- **R8 — Chargement conservé.** Après un `PATCH` du projet, le store garde le `failedProducts` chargé par le dernier `GET`, comme il garde `steps` et `tree`.
- **R9 — i18n.** Nouvelle clé `industry.stepsTab.failedProductsWarning` en FR et en EN. Clés réutilisées : `common.unknownTypeName`, `industry.stepsTab.treeUnavailable`.

| Clé | EN | FR |
|---|---|---|
| `industry.stepsTab.failedProductsWarning` | `The production tree of these products could not be built.` | `L'arbre de production de ces produits n'a pas pu etre construit.` |

Le FR suit le style sans accents des clés voisines (`industry.stepsTab.*`, `industry.shoppingTab.failedProductsWarning`).

## Exemples chiffrés (tests rouges)

### Backend (PHPUnit, harnais `ProjectProviderGoldenTreeCharacterizationTest`, SDE golden, groupe `issue-193`)

Types : Nitrogen Fuel Block 4051, Hydrogen Fuel Block 4246, Robotics 9848, Fernite Carbide 16673 (réaction, racine), Fernite Alloy 16656, Ceramic Powder 16660, Scandium 16639, Oxygen Isotopes 17887. Les types absents du SDE sont posés après la création du projet par la factory (`typeIdsMissingFromSde`), comme dans les tests existants.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Nitrogen Fuel Block 1 run, ME 10, tous les types connus (garde-fou) | arbre complet | arbre inchangé ; `failedProducts = []` |
| E2 | Nitrogen Fuel Block 1 run, ME 10, Robotics (matériau de la racine) absent | `tree = null`, rien d'autre | `tree = null` ; `failedProducts = [{typeId: 4051, reason: "Types missing from the SDE: 9848"}]`. Réécrit `testTreeIsNullWhenATypeOfAShownNodeIsMissingFromTheSde` |
| E3 | Fernite Carbide 1 run, ME 10, Scandium (matériau de l'intermédiaire Fernite Alloy) absent | `tree = null` | `tree = null` ; `failedProducts = [{16656, "Types missing from the SDE: 16639"}]` (même entrée que la liste d'achats, `IndustryShoppingListBuilderFromStepsTest`). Réécrit `testTreeIsNullWhenATypeOfAnIntermediateNodeIsMissingFromTheSde` |
| E4 | Fernite Carbide 1 run, ME 0, Scandium et Robotics absents | `tree = null` | `tree = null` ; `failedProducts = [{4246, "Types missing from the SDE: 9848"}, {16656, "Types missing from the SDE: 16639"}]` (typeId croissant) |
| E5 | Fernite Carbide 1 run, ME 0, le produit Fernite Alloy lui-même absent | `tree = null` | `tree = null` ; `failedProducts = [{16656, "Types missing from the SDE: 16656"}, {16673, "Types missing from the SDE: 16656"}]` : l'intermédiaire et son consommateur, la racine |
| E6 | Fernite Carbide 1 run, ME 0, Fernite Alloy blacklisté, Scandium absent (produit en erreur hors de l'arbre affiché) | arbre affiché, erreur invisible | arbre affiché : ligne `{typeId: 16656, typeName: "Fernite Alloy", quantity: 100, isBuildable: false, activityType: null}` sans sous-arbre ; `failedProducts = [{16656, "Types missing from the SDE: 16639"}]` |
| E7 | Fernite Carbide 1 run, ME 0, étape Oxygen Isotopes ajoutée (profondeur 1, sans recette, consommée par aucune étape) | arbre affiché, erreur invisible | arbre inchangé ; `failedProducts = [{17887, "No blueprint or reaction found for product type ID 17887"}]` |
| E8 | Racine Robotics, une étape de fabrication, sans recette | `tree = null` | `tree = null` ; `failedProducts = [{9848, "No blueprint or reaction found for product type ID 9848"}]`. Complète `testTreeIsNullWhenTheRootStepHasNoBlueprintNorReaction` |
| E9 | Projet sans étape (Nitrogen Fuel Block 25 runs) | `tree = null` | `tree = null` ; `failedProducts = []`. Complète `testTreeOfAProjectWithoutStepIsNull` |
| E10 | Cas E3 : nombre de lectures des produits SDE des blueprints pendant un `GET` (garde-fou contre un second appel au résolveur) | 1 (l'arbre seul) | toujours 1 : `IndustryActivityProductRepository::findProductsForBlueprints()` appelé exactement une fois (R2) |
| E11 | `PATCH` des notes d'un projet (`ProjectPatchWithoutProductionTreeTest`, test fonctionnel) | champ absent | `failedProducts` présent et `null` dans la réponse ; l'arbre n'est toujours pas construit |

### Frontend (Vitest)

`StepsTab` monté avec un projet chargé dans le store (harnais de `stepsTabFixedRuns.spec.ts`, `StepHierarchyTree` stubbé), locale EN sauf mention. Étapes du projet : Fernite Carbide (16673, nom « Fernite Carbide ») et Fernite Alloy (16656, nom « Fernite Alloy » sauf mention).

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| F1 | Vue à plat (par défaut), `tree = null`, `failedProducts = [{16656, "Types missing from the SDE: 16639"}]` | liste des étapes, aucun message | `[data-testid="failed-products-warning"]` présent : « The production tree of these products could not be built. », « Fernite Alloy », « Types missing from the SDE: 16639 » ; liste des étapes toujours affichée |
| F2 | F1 en vue hiérarchique (bouton « Tree ») | « Tree unavailable » seul | avertissement de F1 et « Tree unavailable » |
| F3 | Vue hiérarchique, `failedProducts = [{16656, "Types missing from the SDE: 16656"}, {16673, "Types missing from the SDE: 16656"}]`, étape 16656 sans nom (`productTypeName: null`) | « Tree unavailable » seul | deux lignes, dans cet ordre : la première contient « Unknown name (#16656) » et « Types missing from the SDE: 16656 », la seconde « Fernite Carbide » et la même raison |
| F4 | Vue hiérarchique, arbre présent, `failedProducts = [{16656, "Types missing from the SDE: 16639"}]` (cas E6) | arbre seul | arbre (`StepHierarchyTree`) et avertissement |
| F5 | Arbre présent, `failedProducts = []` (garde-fou) | arbre | arbre, aucun avertissement, pas de « Tree unavailable » |
| F6 | Vue hiérarchique, `tree = null`, `failedProducts = []` (projet sans étape) | « Tree unavailable » | inchangé, aucun avertissement |
| F7 | `failedProducts = null` (réponse qui ne le calcule pas) | — | aucun avertissement, aucune erreur de rendu |
| F8 | F1 en locale FR | — | « L'arbre de production de ces produits n'a pas pu etre construit. » |
| F9 | Store : `fetchProject` charge F1, puis `updateProject` reçoit une réponse `PATCH` avec `failedProducts: null` | — | `currentProject.failedProducts` vaut toujours `[{16656, "Types missing from the SDE: 16639"}]` |
| F10 | `ShoppingFailedProductsWarning` seul, produit 123456 absent de la table des noms | « 123456 » seul | « Unknown name (#123456) » (FR : « Nom inconnu (#123456) ») |

## Cas limites

- **Plusieurs produits en erreur** : tous listés, typeId croissant (E4, E5), dans les deux vues.
- **Produit en erreur hors de l'arbre affiché** (blacklisté, étape racine ajoutée à la main, intermédiaire sous un nœud à 0 run) : listé, l'arbre reste affiché (E6, E7, F4).
- **Type absent dans un nœud non affiché** : même règle ; seule compte la liste du résolveur, pas le parcours de l'arbre.
- **Nom du produit en erreur lui-même inconnu** : « Nom inconnu (#typeId) » (E5, F3). La raison porte les typeIds absents ; aucun nom n'est cherché pour eux, ils sont absents du SDE par définition.
- **Un intermédiaire absent fait échouer aussi son consommateur** : les deux sont listés avec la même raison (E5). Pas de déduplication par cause.
- **Arbre partiel** : jamais dans ce lot (T1).
- **Clients qui lisent `tree`** : forme et sémantique inchangées ; le champ `failedProducts` est un ajout. La liste des projets (`ProjectListResource`) ne le porte pas.
- **Réponse `PATCH`/`POST`** : `failedProducts = null` (E11), le front garde la valeur chargée (F9).
- **Raison en anglais** : texte du backend, affiché tel quel comme dans la liste d'achats (hors périmètre).
- **Cycle entre étapes** : l'erreur remonte comme aujourd'hui (spec #121), inchangé.

## Décisions prises en autonomie

1. **Format `{typeId, reason}` identique à la liste d'achats, sans nom côté API** (R1, R7). Le nom est déjà dans la même réponse (`steps[].productTypeName`, résolu par le mapper, `null` si absent du SDE) et chaque produit en erreur est le produit d'une étape du projet. Un `typeName` côté API demanderait une lecture du SDE en plus : `ProductionStepNeeds::$typeNames` ne contient pas les produits sans recette (retirés avant la lecture des noms), il donnerait « inconnu » à tort. Même type TypeScript (`ShoppingListFailedProduct`), même composant sans changement de props.
2. **Une seule passe** (R2) : le résolveur est appelé une fois et fournit l'arbre et la liste. Forme recommandée pour la phase verte : `ProductionTreeFromSteps::buildTree()` renvoie l'arbre et la liste ensemble, comme `IndustryShoppingListBuilder::getShoppingList()` renvoie `materials` et `failedProducts`. `ProductionTreeFromSteps` n'a qu'un appelant (`ProjectProvider`).
3. **`null` hors `GET` détaillé** (R5) : une réponse `PATCH`/`POST` ne calcule rien ; `[]` y affirmerait à tort l'absence d'erreur (règle « jamais une valeur par défaut plausible »). Côté TypeScript, champ optionnel et nullable sur `IndustryProject` (type partagé avec la liste), lu sans `?? []`.
4. **Avertissement au-dessus des deux vues, une seule fois** (R6) : la vue à plat est celle par défaut ; l'issue veut un message à l'écran, pas seulement dans la vue hiérarchique.
5. **« Arbre non disponible » gardé sous l'avertissement** quand `tree` est `null` : l'avertissement dit pourquoi, le texte existant dit ce qui manque. Aucune mise en page nouvelle.
6. **Libellé « Nom inconnu (#typeId) » dans le composant partagé** (R7) : règle du glossaire (#115) ; change le texte affiché dans Liste d'achats et Coût pour un produit sans nom (aucun montant). Les tests existants (`toContain(typeId)`) restent verts.
7. **Pas de maquette** : composant existant réutilisé, sans nouvelle mise en page.

## À trancher par Sylvain

- **T1 — Arbre partiel.** Recommandation (retenue par la spec) : non, `tree` reste `null` dès qu'un nœud affiché est en erreur. Un arbre affiché sans la branche en erreur montrerait des quantités et des feuilles incomplètes sans que rien ne le distingue. Alternative : nœud en erreur affiché comme une feuille marquée « en erreur » avec sa raison, le reste de l'arbre visible ; change la forme d'un nœud (R8 de #121) et `StepHierarchyTree.vue`, à faire dans une issue dédiée.
- **T2 — Avertissement quand l'arbre est affiché.** Recommandation (retenue) : oui (R4, E6, E7, F4). Les onglets Liste d'achats et Coût signalent déjà ces produits ; l'onglet Étapes dirait la même chose. Effet visible : un projet avec une étape sans recette ajoutée à la main affiche désormais l'avertissement dans l'onglet Étapes. Alternative : ne lister que les produits en erreur d'un nœud qui serait affiché ; demande un filtrage par parcours de l'arbre, et l'onglet Étapes contredirait la Liste d'achats.

## Hors périmètre

- Traduction des raisons (« Types missing from the SDE: … », « No blueprint or reaction found … ») : texte backend en anglais partout.
- Arbre partiel (T1, option alternative).
- `IndustryTreeService` et ses appelants (factory, création de projet : 400 avec le message, spec #109).
- Signalement dans la vue à plat, étape par étape (marqueur sur la ligne de l'étape en erreur).
- Liste des projets (`ProjectListResource`) et réponses `PATCH`/`POST` : pas de calcul de `failedProducts`.
- `StockpileDashboard.vue` (liste propre des produits en erreur, autre composant).

## Tests existants touchés

- **Réécrits (complétés), groupe `issue-121` gardé, `issue-193` ajouté** : `ProjectProviderGoldenTreeCharacterizationTest::testTreeIsNullWhenATypeOfAShownNodeIsMissingFromTheSde` (E2) et `::testTreeIsNullWhenATypeOfAnIntermediateNodeIsMissingFromTheSde` (E3). Ils ne cassent pas (`tree` reste `null`) : leur nom et leur assertion passent au signalement (`tree` nul et `failedProducts` attendu). Même chose pour `::testTreeIsNullWhenTheRootStepHasNoBlueprintNorReaction` (E8) et `::testTreeOfAProjectWithoutStepIsNull` (E9).
- **Restent verts** : `ProjectPatchWithoutProductionTreeTest` (complété par E11) ; `shoppingFailedProductsWarning.spec.ts` (« names a failed product unknown to the project by its type ID » : à renommer et préciser par F10), `shoppingTabFailedProducts.spec.ts`, `costEstimationTabFailedProducts.spec.ts` (`toContain`), `stepsTabFixedRuns.spec.ts` (champ optionnel).
- **Spec touchée** : R7 de `docs/specs/121-production-tree-from-steps.md`, complétée par R3 de cette spec.

## Termes du glossaire

- Aucun terme ajouté.
- Modifié : **Produit en erreur** : l'onglet Étapes le liste aussi, arbre affiché ou non, et l'arbre vaut `null` quand un nœud affiché est en erreur (#193) ; symboles `ProjectResource::$failedProducts`, `ProductionTreeFromSteps::buildTree()`, `StepsTab.vue`.
