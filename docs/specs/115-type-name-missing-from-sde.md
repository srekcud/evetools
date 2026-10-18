# Spec #115 (lot 1, chemin industrie) — Un type absent du SDE a un nom inconnu, jamais « Type #id »

## Contexte

Depuis #109, l'arbre de production lève `TypeMissingFromSdeException` quand un type manque dans `invTypes`. Les noms relus **après** la création d'un projet passent encore par `IndustryCalculationService::resolveTypeName()` → `TypeNameResolver::resolve()`, qui invente `"Type #<typeId>"` (`TypeNameResolver.php:20`). Ce nom factice s'affiche comme un vrai nom dans les projets, les étapes, les achats, les jobs disponibles, les suggestions d'achat et l'estimation du coût de production. Le cas se présente quand le SDE a été réimporté sans un type (type retiré du jeu, import incomplet) ou quand l'ESI renvoie un type (job, transaction) que le SDE ne connaît pas encore.

#115 est découpé en lots. Ce lot couvre les appelants de `IndustryCalculationService::resolveTypeName()` et `getProjectDisplayName()`.

## Règles métier

- **R1 — Nom inconnu.** Un type absent de `invTypes` a un nom de type inconnu : `null` dans l'API. Son typeId reste exposé à côté. Aucun nom n'est inventé côté back.
- **R2 — Résolution.** `TypeNameResolver::findName(int $typeId): ?string` renvoie le nom du SDE ou `null`. `IndustryCalculationService::resolveTypeName()` renvoie `?string` et s'appuie sur `findName()`. `TypeNameResolver::resolve()` et `resolveMany()` ne changent pas dans ce lot (lots suivants).
- **R3 — Nom d'affichage d'un projet.** Le nom personnalisé reste prioritaire. Sans nom personnalisé, si le nom d'au moins un produit racine est inconnu, `displayName` vaut `null`. Il en va de même quand le projet n'a aucune étape racine et que le nom de son produit est inconnu. `getProjectDisplayName()` renvoie alors `?string`.
- **R4 — Calculs inchangés.** Un nom inconnu ne change ni les quantités, ni les coûts, ni l'ordre, ni le nombre d'éléments renvoyés.
- **R5 — Commandes console.** Les commandes qui affichent le nom d'un projet (`MatchProjectJobsCommand`, `RegenerateProjectStepsCommand`) affichent `type <typeId> (name missing from the SDE)` quand le nom est inconnu.
- **R6 — Front.** Un nom `null` s'affiche avec un libellé i18n explicite : « Nom inconnu (#9848) » / « Unknown name (#9848) ». Un `displayName` `null` est recomposé côté front à partir de `rootProducts` avec ce libellé (même format `×N` et ` + ` que le back). Le tri par nom de `ProjectTable` utilise ce texte affiché.

## Exemples chiffrés

Types : Nitrogen Fuel Block 4051 (connu), Robotics 9848 (absent de `invTypes` sauf mention contraire).

| # | Situation | Entrée | Attendu |
|---|---|---|---|
| E1 | Garde-fou : tous les noms connus | étape 4051, achat 4051, projet 4051 sans nom personnalisé | noms du SDE, `displayName = "Nitrogen Fuel Block"`, comme aujourd'hui |
| E2 | `findName()` | 4051 ; 9848 | `"Nitrogen Fuel Block"` ; `null` |
| E3 | Étape dont le produit est inconnu | `stepToResource`, produit 9848 | `productTypeId = 9848`, `productTypeName = null` |
| E4 | Achat d'un type inconnu | `purchaseToResource`, achat 9848 ×10 à 1 000 | `typeId = 9848`, `typeName = null`, `quantity = 10`, `totalPrice` inchangé |
| E5 | Achats listés dans une étape | `stepToResource`, étape 4051 avec un achat 9848 | `purchases[0].typeName = null`, `purchases[0].typeId = 9848` |
| E6 | Projet, un produit racine inconnu | projet 9848 sans nom personnalisé, une étape racine 9848 | `productTypeName = null`, `rootProducts = [{typeId: 9848, typeName: null, …}]`, `displayName = null` |
| E7 | Projet, deux produits racines dont un inconnu | racines 4051 et 9848, sans nom personnalisé | `rootProducts` : `"Nitrogen Fuel Block"` et `null` ; `displayName = null` |
| E8 | Projet avec nom personnalisé | projet 9848 nommé « Batch robotics » | `displayName = "Batch robotics"`, `productTypeName = null` |
| E9 | Projet sans étape racine | projet 9848 sans nom personnalisé ni étape | `displayName = null` ; `getProjectDisplayName()` → `null` |
| E10 | Jobs disponibles | job ESI produisant 9848, lié à une étape 9848 | `productTypeName = null` ; `linkedToStepId` renseigné et `linkedToStepName = null` ; job toujours listé |
| E11 | Suggestion d'achat | transaction wallet sur 9848 | `typeName = null`, `typeId = 9848`, quantités et prix inchangés |
| E12 | Estimation du coût de production | étape 9848 | `steps[].productName = null`, `installCost` et total inchangés |
| E13 | Commande console | `RegenerateProjectStepsCommand` sur un projet 9848 sans nom personnalisé | sortie `Regenerating steps for: type 9848 (name missing from the SDE)` |
| E14 | Liste des projets (intégration, SDE vide) | `ProjectCollectionProviderTest` | `displayName = null` et `rootProducts[].typeName = null` au lieu de `"Type #<id>"` ; coûts et profits inchangés |

## Cas limites

- Le nom personnalisé d'un projet n'est jamais remplacé, même si tous les produits sont inconnus.
- Les typeIds restent toujours renseignés : le front peut afficher l'icône et le libellé « Nom inconnu (#id) ».
- Un type présent dans `invTypes` mais au nom vide n'est pas traité ici (le SDE impose un nom).

## Hors périmètre

- Lot 2 : `PivotAdvisorService`, `BatchProfitScannerService`, `InventionService`, `SlotTrackerService` ; `ProfitMarginService` et `BuyVsBuildService` après les branches CP / CS.
- Lot 3 : syncs qui enregistrent un nom en base (industry jobs, mining et son `str_starts_with('Type #')`, PVE, PI), avec migration.
- Lot 4 : assets, processors de scan PVE, `SettingsProvider` PVE, favoris marché, PI, skill queue, contrats, puis suppression de `TypeNameResolver::resolve()` et `resolveMany()`.
- Liste d'achats collée et Appraisal (#117), `GroupIndustryProjectService` (#106), `IndustryProjectFactory` (#110).
- Pas de test front (aucun harnais) : R6 est vérifié à la main dans l'application.

## Glossaire

Ajouté : **Nom de type inconnu** / *Unknown type name* (section « Comptes et ESI »).

## Tests impactés

- `ProjectCollectionProviderTest` : les assertions `'Type #' . …` deviennent `null` (E14) ; le commentaire « type names resolve to "Type #<id>" » disparaît.
- `ProductionCostServiceTest` : le mock `resolveTypeName` qui renvoie `"Type #{$typeId}"` reste valide (chaîne quelconque), inchangé.
