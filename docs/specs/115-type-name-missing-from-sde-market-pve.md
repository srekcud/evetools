# Spec #115 (lot 4a, favoris marché et réglages PVE) — Un type absent du SDE a un nom inconnu

## Contexte

Lots 1 et 2 (`115-type-name-missing-from-sde.md`, `115-type-name-missing-from-sde-services.md`) : le **nom de type inconnu** vaut `null` dans l'API, à côté du typeId, et le front affiche « Nom inconnu (#id) » (`useTypeNames().typeNameLabel()`). `TypeNameResolver::findName()` / `findNames()` renvoient seulement les noms connus.

Trois lectures hors industrie inventent encore `"Type #<typeId>"` :

- `MarketFavoriteCollectionProvider.php:68` (liste des favoris marché) ;
- `MarketFavoriteDeleteProvider.php:44` (réponse du `DELETE` d'un favori) : le nom y est **toujours** `"Type #<id>"`, même pour un type connu ;
- `Pve/SettingsProvider.php:44`, `:53` (types de munitions et de loot suivis dans les réglages PVE).

## Règles métier

- **R1 — Nom inconnu.** Un type absent de `invTypes` a un nom `null` dans ces trois réponses ; le typeId reste renseigné. Prix, variation 30 j et dates inchangés.
- **R2 — Nom réel au `DELETE`.** La réponse du `DELETE` d'un favori porte le nom du SDE du type (ou `null` s'il est inconnu), plus jamais `"Type #<id>"`.
- **R3 — Résolution.** `MarketFavoriteDeleteProvider` reçoit `TypeNameResolver` et passe par `findName()`. La liste des favoris et les réglages PVE gardent leur lecture groupée actuelle d'`InvTypeRepository` et retirent seulement le nom inventé (leurs tests construisent le provider avec le dépôt).
- **R4 — Front.** Favoris marché et réglages PVE affichent un nom `null` avec `typeNameLabel()`.

## Exemples chiffrés

Types : Tritanium 34 (connu), Robotics 9848 (absent de `invTypes`).

| # | Situation | Entrée | Attendu |
|---|---|---|---|
| E1 | Garde-fou, favoris connus | favoris 34 | `typeName = "Tritanium"`, prix inchangés |
| E2 | Favori inconnu | favoris 34 et 9848 | 9848 : `typeName = null`, `typeId = 9848`, `jitaSell` / `jitaBuy` / `change30d` inchangés ; 34 : `"Tritanium"` ; deux lignes, même ordre |
| E3 | `DELETE` d'un favori connu | favori 34 | `typeName = "Tritanium"` (aujourd'hui `"Type #34"`) |
| E4 | `DELETE` d'un favori inconnu | favori 9848 | `typeName = null` |
| E5 | Réglages PVE, munition inconnue | `ammoTypeIds = [34, 9848]` | `ammoTypes` : `"Tritanium"`, puis `null` pour 9848 |
| E6 | Réglages PVE, loot inconnu | `lootTypeIds = [9848]` | `lootTypes[0].typeName = null`, `typeId = 9848` |

## Décision prise en autonomie

- **Périmètre réduit.** Les processors de scan PVE et `AssetsService` alimentent des données enregistrées (descriptions de dépenses PVE, cache d'assets dont le nom vient de l'ESI) : ils rejoignent le lot 3 (noms enregistrés, migration). `PlanetaryResourceMapper`, `PlanetaryProductionCalculator`, `SkillQueueProvider` et `ContractCollectionProvider` restent pour le lot 4b, faute de temps cette nuit.
- **Pas de test front** (pas de harnais composant) : vérifié par le typecheck.

## Hors périmètre

- Lot 3, lot 4b, suppression de `resolve()` / `resolveMany()`.
- Tout fichier industrie (threads en cours).

## Glossaire

Aucun terme ajouté.
