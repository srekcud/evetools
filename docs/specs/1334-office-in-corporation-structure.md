# #1334 — Station d'un asset dans le bureau d'une structure Upwell de corporation

Circuit `m`, mode nuit, back-end seulement (aucun écran, aucune migration, aucun nouveau champ). Corrige la résolution de station utilisée par `ProjectContainerReadings`, `app:industry:migrate-to-station-model` (#1320, R8), le stock perso et les blueprints d'étape.

## Contexte

`AssetStations::locationOf` remonte l'asset jusqu'à son bureau (`OfficeFolder`) et prend `locationId` du bureau comme station. Un bureau de corporation dans une structure Upwell appartenant à la corporation est stocké avec `location_type = item` : son `locationId` est l'item id de la structure, qui est elle-même un asset de la corporation posé dans l'espace (`location_type = solar_system`). Ce cas renvoyait `null` (`station_unresolved`), donc 6 containers sur 6 de la copie de production ne se localisaient pas.

## Règles

R1. Bureau avec `location_type` différent de `item` (station NPC, structure d'un tiers) : inchangé, station = `locationId` du bureau, nom et système du bureau.

R2. Bureau avec `location_type = item` dont le parent (`locationId` du bureau) est un asset de la corporation (`findCorporationByItemIds`) stocké avec `location_type = solar_system` : station = item id de la structure (un id de structure est son item id), nom et système solaire lus sur la ligne du bureau (elle les porte déjà).

R3. Bureau avec `location_type = item` dont le parent n'est pas dans les assets de la corporation : non résolu (`null`).

R4. Bureau avec `location_type = item` dont le parent est un asset qui n'est pas posé dans l'espace (cas d'un conteneur, d'un bureau dans un objet) : non résolu (`null`, comportement existant, test `testOfficeStoredInAnItemLeavesTheStationUnresolved`).

R5. Les parents de bureaux se lisent en une seule requête par instance de `AssetStations` (comme les bureaux), jamais une requête par asset.

## Exemples

| Asset | Bureau | Parent du bureau | Résultat |
|---|---|---|---|
| container nommé | `station`, id 100 | n/a | station 100 |
| container nommé | `item`, parent 5000 | asset corpo 5000, `solar_system` | station 5000, nom et système du bureau |
| container nommé | `item`, parent 5000 | absent des assets corpo | `null` |
| container nommé | `item`, parent W | W asset corpo en `item` | `null` |
| deux containers, deux bureaux sous la même structure | | | une requête de parents |

## Hors périmètre

Les structures absentes du cache des assets de la corporation (3 assets de la copie) : restent `null` (R3). Aucune lecture du nom de la structure elle-même (la ligne du parent peut ne pas avoir de nom).
