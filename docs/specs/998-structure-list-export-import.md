# Spec — Export et import d'une liste de structures de production (#998)

## Contexte

Un joueur configure jusqu'à 150 structures (`IndustryStructureConfig`), propres à son compte. Les passer à un autre joueur oblige à tout ressaisir. Décision du 2026-11-14 : pas de pool corporatif, l'import/export est la voie retenue. Décisions de la nuit : format JSON seul, `isDefault` jamais exporté ni importé.

## Règles

- **R1.** `GET /industry/structures/export` renvoie `{format: "evetools.industry-structures", version: 1, structures: [...]}` : les structures non supprimées de l'utilisateur, une entrée par structure avec `name`, `locationId`, `solarSystemId`, `structureType`, `securityType`, `rigs`, `facilityTaxRate`. Jamais `isDefault`, `isCorporationStructure`, `corporationId` ni id interne.
- **R2.** `POST /industry/structures/import` reçoit `{entries, overwriteExisting, dryRun}` (`entries` : au plus 500 entrées de la forme de R1). Réponse : une ligne par entrée `{index, name, locationId, status, errors}` avec `status` parmi `create`, `update`, `skip`, `invalid`, et `applied` (faux en dry-run).
- **R3.** Identité d'une entrée : son `locationId` quand il existe, sinon son `name` parmi les structures de l'utilisateur sans `locationId`. Une entrée dont l'identité existe déjà chez l'importateur est `skip` ; avec `overwriteExisting`, elle est `update`. Une seconde entrée de la même liste avec la même identité est `skip`.
- **R4.** Validation d'une entrée, mêmes règles que la création : nom non vide de 255 caractères au plus, `securityType` et `structureType` dans leurs listes, `facilityTaxRate` de 0 à 50 ou `null`, ids positifs, rigs par `ProductionStructureRigsValidator` (rig inconnu, rig qui ne va pas au type, plus de 3 rigs). Un champ absent, d'un mauvais type ou invalide rend l'entrée `invalid` avec ses motifs, jamais importée avec une valeur par défaut. Une taxe absente reste `null` (inconnue), jamais 0.
- **R5.** `create` passe par `CreateStructureProcessor` : système solaire lu dans le cache ESI quand la structure y est connue (sinon celui de l'entrée), `isCorporationStructure` recalculé pour l'importateur, `isDefault` faux, restauration d'une structure supprimée par l'importateur au lieu d'un doublon.
- **R6.** `update` ne change que les rigs (validés contre le type de la structure existante) et la taxe d'installation, et seulement si l'entrée en porte une (une taxe `null` n'efface pas la taxe connue). Nom, type, sécurité, défaut intacts.
- **R7.** `dryRun: true` n'écrit rien. Une entrée `invalid` n'empêche jamais l'import des autres. L'import d'un utilisateur ne touche que ses structures.
- **R8.** Interface (Industrie > Réglages > Structures) : bouton d'export (copie et téléchargement du JSON), zone de collage ou choix d'un fichier, aperçu par statut avec case par entrée (cochées par défaut sauf `skip` et `invalid`), option « écraser rigs et taxe », bouton d'import, résumé du résultat. JSON illisible : message d'erreur, aucun appel.

## Exemples

| # | Cas | Attendu |
|---|---|---|
| E1 | Export puis import sur un autre compte, 3 structures | 3 `create`, mêmes rigs et taxes |
| E2 | Même liste importée une seconde fois | 3 `skip`, aucun doublon |
| E3 | Entrée avec rig « Standup L-Set … » sur un raitaru | `invalid`, motif du validateur, rien créé |
| E4 | Taxe 51 | `invalid` |
| E5 | Taxe absente | `create`, `facilityTaxRate` null |
| E6 | Entrée existante, rigs différents, `overwriteExisting` | `update`, rigs et taxe remplacés |
| E7 | Entrée existante, taxe `null`, `overwriteExisting` | `update`, taxe conservée |
| E8 | Export d'une structure corpo, import par un joueur d'une autre corporation | `isCorporationStructure` faux |
| E9 | Deux entrées de même `locationId` dans la liste | `create` puis `skip` |
| E10 | Deux entrées manuelles de même nom, aucune en base | `create` puis `skip` |
| E11 | `dryRun` | statuts calculés, rien écrit, `applied` faux |
| E12 | 501 entrées | 422 |

## Hors périmètre

Format texte lisible, import du défaut, pool corporatif, structures supprimées dans l'export.

## Décisions ouvertes

Aucune ; JSON seul et défaut non importé tranchés en autonomie (journal « Décisions »).
