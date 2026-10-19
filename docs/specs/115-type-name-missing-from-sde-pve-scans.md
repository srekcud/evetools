# Spec #115 (lot 3c, scans PVE) — Un type absent du SDE n'a plus de nom inventé

## Contexte

Lots précédents (specs `115-type-name-missing-from-sde*.md`) : un nom de type inconnu vaut `null` dans les données ; dans un texte composé, il s'écrit `type <typeId> (name missing from the SDE)` via `UnknownTypeName::label()` (lots 3a, 3b, dont les descriptions de la sync PVE).

Les trois processors de scan PVE inventent encore `"Type #<typeId>"` dans ce qu'ils détectent et proposent à l'import :

- `ScanContractsProcessor` (`:132`, `:228`) : dépenses détectées, `DetectedExpenseResource::$typeName` est un texte composé (`"3x …"`, ou la liste des items) ;
- `ScanLootContractsProcessor` (`:130`) : contrats de loot détectés, `description` composée et `items[].typeName` (nom isolé d'un item) ;
- `ScanLootSalesProcessor` (`:128`, `:234`, `:395`) : ventes détectées, `DetectedSaleResource::$typeName` est un texte composé.

## Règles métier

- **R1 — Texte composé.** Dans un texte composé (`DetectedExpenseResource::$typeName`, `DetectedSaleResource::$typeName`, `DetectedLootContractResource::$description`), un type inconnu s'écrit `UnknownTypeName::label($typeId)`, par exemple `3x type 60459 (name missing from the SDE)`.
- **R2 — Nom isolé.** `DetectedLootContractResource::$items[].typeName` vaut `null` pour un type inconnu ; `typeId` et `quantity` inchangés.
- **R3 — Inchangé.** Types connus, prix, quantités, dates, nombre et ordre des détections inchangés ; le libellé `'Items'` d'une contribution sans typeId (`typeId = 0`, `:396`) reste tel quel.
- **R4 — Résolution.** Les processors gardent leur lecture d'`InvTypeRepository`.
- **R5 — Front.** L'écran d'import PVE affiche un `items[].typeName` `null` avec `typeNameLabel()` s'il affiche ce champ.

## Exemples chiffrés

| # | Situation | Entrée | Attendu |
|---|---|---|---|
| E1 | Garde-fou | scans sur des types connus | textes et noms du SDE inchangés |
| E2 | Dépense détectée, type inconnu | contrat reçu de 4 × munition 9848 suivie | `typeName = "4x type 9848 (name missing from the SDE)"` |
| E3 | Contrat de loot, item inconnu | 9 × 60459 et 6 × un type connu | `items` : `{typeId: 60459, typeName: null, quantity: 9}` puis le connu ; `description` avec le libellé pour 60459 |
| E4 | Vente de loot, type inconnu | 3 × 60459 vendus | `typeName = "3x type 60459 (name missing from the SDE)"`, prix inchangé |
| E5 | Contribution à un projet corp, type inconnu | 5 × 60459 contribués | `typeName = "5x type 60459 (name missing from the SDE)"` |

## Décision prise en autonomie

- Même règle que les lots 3a et 3b : texte composé = libellé explicite, nom isolé = `null`.

## Hors périmètre

- Mining (migration), `AssetsService` (cache d'assets), suppression de `resolve()` / `resolveMany()`.

## Glossaire

Aucun terme ajouté.
