# Spec #115 (lot 3b, syncs PI et PVE) — Un type absent du SDE n'a plus de nom inventé

## Contexte

Lots précédents (specs `115-type-name-missing-from-sde*.md`) : le **nom de type inconnu** vaut `null` dans les données ; dans un texte enregistré (console, notifications), il s'écrit `type <typeId> (name missing from the SDE)` via `UnknownTypeName::label()` (lot 3a).

Deux syncs inventent encore `"Type #<typeId>"` :

- `PlanetarySyncService` :
  - nom enregistré sur chaque pin (`:202`, `resolveMany()`) ; la colonne `PlanetaryPin::$typeName` accepte déjà `null` ;
  - produit d'un extracteur expiré ou bientôt expiré (`:292`, `resolve()`) : alerte Mercure `planetary-expiry` (données `productName`) et notification (texte `%s extractor on %s has expired (%s)` / `%s extractor on %s expires in %d min (%s)`, données `productName`) ;
- `PveSyncService::getTypeName()` (`:58-65`) : descriptions des revenus et dépenses PVE (`"{quantité}x {nom}"`, `:367`, `:459`, `:580`), enregistrées en base.

## Règles métier

- **R1 — Données.** Le nom enregistré sur un pin et `productName` dans les données de l'alerte et de la notification valent `null` pour un type absent de `invTypes`.
- **R2 — Texte.** Dans un texte (notification d'extracteur, description PVE), un type inconnu s'écrit `UnknownTypeName::label($typeId)`. Exemple : `3x type 60459 (name missing from the SDE)`.
- **R3 — Regroupement.** Les quantités de loot d'un contrat PVE restent regroupées par type : deux types inconnus distincts restent deux entrées distinctes.
- **R4 — Inchangé.** Un type connu garde exactement les textes, montants, dates et nombre d'entrées actuels.
- **R5 — Résolution.** `PlanetarySyncService` passe par `TypeNameResolver::findNames()` / `findName()` ; `PveSyncService` garde sa lecture d'`InvTypeRepository` et retire seulement le nom inventé.

## Exemples chiffrés

| # | Situation | Entrée | Attendu |
|---|---|---|---|
| E1 | Garde-fou | pins, extracteurs et revenus PVE sur des types connus | noms du SDE, sortie inchangée |
| E2 | Pin d'un type inconnu | pin de type 9848 | `PlanetaryPin::typeName = null`, `typeId = 9848` |
| E3 | Extracteur expiré, produit inconnu | extracteur de produit 9848 expiré sur « Planet I » (pilote « Test Pilot ») | texte `type 9848 (name missing from the SDE) extractor on Planet I has expired (Test Pilot)`, données `productName = null` (notification et alerte Mercure) |
| E4 | Extracteur bientôt expiré, produit inconnu | expire dans 30 min | texte `type 9848 (name missing from the SDE) extractor on Planet I expires in 30 min (Test Pilot)`, `productName = null` |
| E5 | Vente de loot PVE, type inconnu | 3 × 60459 vendus | description `3x type 60459 (name missing from the SDE)`, montant inchangé |
| E6 | Contrat de loot PVE, deux types inconnus et un connu | 9 × 60459, 2 × 60460, 6 × un type connu | trois entrées distinctes dans la description, quantités inchangées |

## Décision prise en autonomie

- **Textes enregistrés** : même choix qu'au lot 3a, le texte porte le libellé explicite plutôt qu'un nom vide.
- **Longueur des descriptions PVE** : le libellé est plus long que `Type #<id>` ; la troncature existante des descriptions s'applique telle quelle (les tests de caractérisation concernés sont mis à jour sur le nouveau texte).

## Hors périmètre

- Mining (`MiningEntry::$typeName` non nullable, `str_starts_with('Type #')`), processors de scan PVE, `AssetsService` : demandent une migration ou une décision sur le cache d'assets (dernier lot).
- Suppression de `resolve()` / `resolveMany()` (encore appelés par `MiningSyncService`).

## Glossaire

Aucun terme ajouté.
