# Spec #115 (lot 3d, mining) — Un minerai absent du SDE a un nom inconnu

## Contexte

Lots précédents (specs `115-type-name-missing-from-sde*.md`) : un nom de type inconnu vaut `null` dans les données et l'API ; le front affiche « Nom inconnu (#id) » (`useTypeNames().typeNameLabel()`).

`MiningSyncService` enregistre le nom du minerai de chaque entrée du mining ledger (`MiningEntry::$typeName`, colonne `string` non nullable) via `TypeNameResolver::resolve()`, qui invente `"Type #<typeId>"` (`:275`, `:315-320`). À la sync suivante, une entrée dont le nom commence par `Type #` est renommée si le SDE connaît désormais le type (`:252-258`, `str_starts_with`). Ce nom part dans les entrées du ledger (`MiningEntryResource::$typeName`) et les statistiques par type (`MiningTypeStatsResource::$typeName`, regroupées par `typeId, typeName`).

## Règles métier

- **R1 — Nom inconnu enregistré.** Une nouvelle entrée d'un type absent de `invTypes` est enregistrée avec `typeName = null`. La colonne `mining_entries.type_name` devient nullable (migration).
- **R2 — Reprise des anciennes entrées.** La migration remet à `null` le nom des entrées existantes qui valent `Type #<typeId>` (le nom factice n'est plus une donnée). Le typeId est conservé.
- **R3 — Renommage à la sync.** À la sync, une entrée existante dont le nom est `null` reçoit le nom du SDE dès qu'il est connu (même effet qu'aujourd'hui, sans test sur un préfixe). Une entrée déjà nommée n'est pas touchée.
- **R4 — API et statistiques.** `MiningEntryResource::$typeName` et `MiningTypeStatsResource::$typeName` valent `null` pour un type inconnu ; quantités, valeurs, regroupement par typeId et ordre inchangés.
- **R5 — Résolution.** Le service passe par `TypeNameResolver::findName()`.
- **R6 — Front.** Le tableau des entrées de minage et les statistiques par type affichent un nom `null` avec `typeNameLabel()`.

## Exemples chiffrés

Types : Pyroxeres 1224 (connu), 62568 (absent de `invTypes`).

| # | Situation | Entrée | Attendu |
|---|---|---|---|
| E1 | Garde-fou | nouvelle entrée de 1224 | `typeName = "Pyroxeres"` |
| E2 | Nouvelle entrée, minerai inconnu | 15 587 unités de 62568 | entrée créée, `typeName = null`, `typeId = 62568`, quantité inchangée |
| E3 | Entrée existante sans nom, SDE complété | entrée 1224 enregistrée avec `typeName = null` | renommée `"Pyroxeres"`, `syncedAt` mis à jour, résultat `updated` |
| E4 | Entrée existante sans nom, toujours inconnue | entrée 62568, `typeName = null`, même quantité | nom toujours `null`, résultat `unchanged` |
| E5 | Entrée nommée | entrée 1224 « Pyroxeres » | nom inchangé |
| E6 | API d'une entrée inconnue | entrée 62568 | `MiningEntryResource::typeName = null` |
| E7 | Statistiques par type | 62568 et 1224 | ligne 62568 : `typeName = null`, totaux inchangés |
| E8 | Migration | lignes `Type #62568` et `Pyroxeres` | `NULL` et `Pyroxeres` |

## Décision prise en autonomie

- **Données existantes** : les noms `Type #<id>` déjà enregistrés sont remis à `NULL` par la migration plutôt que gardés : c'est le même nom factice que celui que #115 supprime, et le typeId suffit à les renommer à la prochaine sync (R3). Migration réversible pour le schéma (`down` remet les `NULL` à `Type #<type_id>` puis la contrainte `NOT NULL`).
- **Action prod** : migration à lancer au déploiement (ajoutée à la checklist de déploiement).
- **Nom de système `System #<id>`** (même service) : hors périmètre, #115 porte sur les noms de type.

## Hors périmètre

- `AssetsService` et le cache d'assets (dernier lot), suppression de `resolve()` / `resolveMany()` après ce lot si plus aucun appelant.

## Glossaire

Aucun terme ajouté.
