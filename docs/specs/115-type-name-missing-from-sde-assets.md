# Spec #115 (lot 6, assets) — Un asset d'un type sans nom a un nom inconnu

## Contexte

Lots précédents (specs `115-type-name-missing-from-sde*.md`) : un nom de type inconnu vaut `null` dans les données et l'API ; le front affiche « Nom inconnu (#id) » (`useTypeNames().typeNameLabel()`) ; un texte composé côté serveur utilise `UnknownTypeName::label()`.

`AssetsService` (`src/Service/ESI/AssetsService.php`) nomme les types des assets via ESI `POST /universe/names/` et invente `"Type #<typeId>"` pour un type absent de la réponse (`:112`), y compris dans le libellé d'emplacement d'un item rangé dans un container (`:167`, « <container> — <station> »). Ce nom est enregistré dans `cached_assets.type_name` (colonne `string` non nullable) par `AssetsSyncService`, puis exposé par `AssetItemResource::$typeName` (assets personnage et corporation). Le front l'affiche (`AssetLocationGroup.vue`) et le filtre (`Assets.vue`, `a.typeName.toLowerCase()`).

## Règles métier

- **R1 — Résolution.** Nom d'un type d'asset : nom ESI, sinon nom SDE (`TypeNameResolver::findNames()`), sinon `null`. Jamais `Type #<id>`.
- **R2 — Libellé de container.** Le container d'un item rangé porte son nom personnalisé, sinon le nom de son type (R1), sinon `UnknownTypeName::label(<typeId du container>)`.
- **R3 — Stockage.** `AssetDto::$typeName`, `CachedAsset::$typeName` et `AssetItemResource::$typeName` deviennent `?string`. La colonne `cached_assets.type_name` devient nullable (migration) ; la migration remet à `NULL` les lignes `Type #%`.
- **R4 — Tri.** Les listes d'assets triées par nom placent les noms inconnus en dernier (`ORDER BY type_name ASC` de PostgreSQL = NULLS LAST, à figer par test).
- **R5 — Front.** Le type `Asset.typeName` devient `string | null` ; l'affichage (nom seul, « <nom perso> (<type>) », `alt`) passe par `typeNameLabel()` ; la recherche ne plante pas sur un nom `null` et trouve un asset inconnu par son libellé affiché.

## Exemples chiffrés

Types : Tritanium 34 (ESI et SDE), Pyerite 35 (absent de la réponse ESI, présent dans le SDE), 9848 (absent des deux), Station Container 17366. Station Jita IV - Moon 4 - Caldari Navy Assembly Plant (60003760).

| # | Situation | Entrée | Attendu |
|---|---|---|---|
| E1 | Garde-fou | asset 34, ESI « Tritanium » | `typeName = "Tritanium"` |
| E2 | Repli SDE | asset 35, ESI ne renvoie pas 35, SDE « Pyerite » | `typeName = "Pyerite"` |
| E3 | Nom inconnu | asset 9848, absent ESI et SDE | `typeName = null`, typeId 9848, quantité inchangée |
| E4 | Container sans nom de type | item dans un container 9848 sans nom perso, en station | `locationName = "type 9848 (name missing from the SDE) — Jita IV - Moon 4 - Caldari Navy Assembly Plant"` |
| E5 | Container nommé par le SDE | container 17366 absent ESI, SDE « Station Container » | `locationName = "Station Container — Jita IV …"` |
| E6 | Sync | `AssetDto` avec `typeName = null` | `CachedAsset::getTypeName() = null` |
| E7 | API | asset en cache sans nom (personnage et corporation) | `AssetItemResource::typeName = null` |
| E8 | Tri | même station : « Tritanium », `null`, « Pyerite » | ordre Pyerite, Tritanium, inconnu |
| E9 | Front affichage | `typeName = null`, typeId 9848 | « Nom inconnu (#9848) » ; avec nom perso « Coffre » : « Coffre (Nom inconnu (#9848)) » |
| E10 | Front recherche | recherche « inconnu » ou « 9848 » | l'asset 9848 est trouvé ; aucune erreur sur `null` |

## Décisions prises en autonomie

- **Ordre ESI puis SDE** : la décision de Sylvain (2026-10-22) fixe le repli « nom SDE puis `null` » ; l'ESI reste la source première ici car il connaît les types publiés avant l'import du SDE.
- **Données existantes** : `Type #…` remis à `NULL` par la migration, comme pour le mining ; le cache est réécrit à la sync suivante. `down` remet `Type #<type_id>` puis `NOT NULL`.
- **`BatchScanTab.vue` `Type #${typeId}`** : liste d'ids sans prix, pas un repli de nom manquant ; hors périmètre.
- **Action prod** : migration à lancer au déploiement.

## Hors périmètre

- Erreur ESI 404 de `/universe/names/` sur un id invalide (comportement inchangé).
- `Location #<id>`, `Station #<id>`, `Structure #<id>` : #115 porte sur les noms de type.

## Glossaire

Aucun terme ajouté.
