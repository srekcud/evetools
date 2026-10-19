# Spec #115 (lot 5, marges et Buy vs Build) — Un type absent du SDE a un nom inconnu

## Contexte

Lots 1, 2, 4a et 4b (specs `115-type-name-missing-from-sde*.md`) : le **nom de type inconnu** vaut `null` dans l'API, à côté du typeId ; le front affiche « Nom inconnu (#id) » (`useTypeNames().typeNameLabel()`).

Deux services d'analyse appellent encore `TypeNameResolver::resolve()`, qui invente `"Type #<typeId>"` :

- `ProfitMarginService` : nom du produit analysé (`:54`, via `resolveTypeName()` `:510`), exposé par `ProfitMarginResource::$typeName` ;
- `BuyVsBuildService` : nom du produit (`:62`), des matériaux d'un composant (`:139`) et des composants (`:180`), exposés par `BuyVsBuildResource`.

Les autres noms de ces écrans viennent de l'arbre de production, qui lève déjà une erreur pour un type absent (#109).

## Règles métier

- **R1 — Nom inconnu.** Le nom du produit (marges, Buy vs Build), d'un composant ou d'un matériau de composant (Buy vs Build) absent de `invTypes` vaut `null` ; son typeId reste renseigné.
- **R2 — Calculs inchangés.** Coûts, marges, verdicts, économies, ordre et nombre d'éléments inchangés.
- **R3 — Résolution.** Les deux services passent par `TypeNameResolver::findName()`. Après ce lot, seuls les syncs du lot 3 (mining, PI) appellent encore `resolve()` / `resolveMany()`.
- **R4 — Front.** Écrans Marges et Buy vs Build : un nom `null` s'affiche avec `typeNameLabel()`.

## Exemples chiffrés

Types : Nitrogen Fuel Block 4051 (connu), Robotics 9848 (absent de `invTypes`).

| # | Situation | Entrée | Attendu |
|---|---|---|---|
| E1 | Garde-fou | analyses sur des types connus | noms du SDE, sortie inchangée |
| E2 | Marges, produit inconnu | analyse du produit 9848 | `typeName = null`, `typeId = 9848`, coûts et marges inchangés |
| E3 | Buy vs Build, produit inconnu | analyse du produit 9848 | `typeName = null`, composants et verdicts inchangés |
| E4 | Buy vs Build, composant inconnu | composant 9848 | `components[].typeName = null`, `verdict` et `savings` inchangés |
| E5 | Buy vs Build, matériau de composant inconnu | matériau 9848 d'un composant | `buildMaterials[].typeName = null`, quantité et prix inchangés |

## Décision prise en autonomie

- **Tests existants** : les tests de ces services qui stubbent `resolve()` passent à `findName()`, assertions inchangées.
- **Pas de test front** (pas de harnais composant) : vérifié par le typecheck.

## Hors périmètre

- Lot 3 (noms enregistrés en base, migration), puis suppression de `resolve()` / `resolveMany()`.

## Glossaire

Aucun terme ajouté.
