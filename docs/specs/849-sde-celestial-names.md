# Spec #849 : noms des lunes et planètes depuis le SDE

Circuit m, mode nuit. Modèle : résolution des stations NPC par le SDE dans `AssetsService` (#814).

## Problème

`CorporationMiningExtractionSyncService::readMoon()` appelle `GET /universe/moons/{id}` par lune et par run (30 min) ; `PlanetarySyncService` appelle `GET /universe/planets/{id}` par colonie sans nom. Le SDE (`mapPlanets.jsonl`, `mapMoons.jsonl`) contient ces corps célestes, sans champ `name`.

## Règles

1. **Import** : `SdeMapImporter` charge `mapPlanets.jsonl` dans `sde_map_planets` et `mapMoons.jsonl` dans `sde_map_moons`, juste après les systèmes solaires (dans `importSolarSystems`, sans toucher `SdeImportService`, périmètre de #847/#848). Une ligne dont le système est absent de `sde_map_solar_systems` est ignorée, comme pour les stations. Chaque import vide sa table avant de la remplir ; insertion par lots multi-lignes (342 170 lunes).
2. **Colonnes** : planète = `planet_id`, `solar_system_id`, `celestial_index`, `type_id` ; lune = `moon_id`, `solar_system_id`, `orbit_id` (planète), `celestial_index` (indice de la planète), `orbit_index`. Pas de clé étrangère. Le nom n'est pas stocké.
3. **Règle de nommage** (vérifiée sur 10 ids ESI, 0 écart, wormholes inclus ; sur tout le SDE chaque lune a la même `celestialIndex` et le même système que sa planète) :
   - planète : `<nom du système> <celestial_index en chiffres romains>` (`Rashy I`, `J105201 VIII`) ;
   - lune : `<nom du système> <celestial_index en romain> - Moon <orbit_index>` (`Abhan V - Moon 8`).
   `CelestialNameFormatter` (final, méthodes statiques pures) porte la règle ; romains jusqu'à 3999.
4. **Lecture** : `SdeCelestialNameResolver` donne `planetName(int): ?string` et `moon(int): ?ResolvedMoon` (`name`, `solarSystemId`). Id absent de `sde_map_planets` / `sde_map_moons`, ou système absent de `sde_map_solar_systems` : `null`, jamais un nom approximatif.
5. **Sync des extractions** : `readMoon()` lit le résolveur d'abord ; aucun appel ESI quand la lune est connue. Lune inconnue du SDE : `warning` (`moon_id`), puis l'ESI comme aujourd'hui (échec ESI = nom `null`, warning existant inchangé).
6. **Sync PI** : le nom de planète d'une colonie sans nom vient du résolveur ; `fetchPlanetInfo()` n'est appelé que si l'id manque au SDE, après un `warning` (`planetId`). Un nom déjà posé n'est jamais relu.
7. **Mercure** : aucune action async nouvelle, topics inchangés.

## Exemples

| Entrée | Sortie |
|---|---|
| système `Rashy`, celestial_index 1 | planète `Rashy I` |
| système `V-4DBR`, 3 | `V-4DBR III` |
| système `J105201`, 8 | `J105201 VIII` |
| système `Abhan`, celestial 5, orbit 8 | lune `Abhan V - Moon 8` |
| système `J123352`, celestial 3, orbit 14 | lune `J123352 III - Moon 14` |
| indices 4, 9, 14, 18 | `IV`, `IX`, `XIV`, `XVIII` |
| lune absente du SDE | résolveur `null`, warning, ESI |

## Hors périmètre

`SdeImportService` (appel séparé des imports après #847/#848), rattrapage des noms déjà stockés, retrait de `fetchPlanetInfo()` (gardé pour le repli), type de planète lu dans l'UI.

## Actions prod

Migration (deux tables vides), puis ré-import du SDE (`make sde-import`, ou le refresh admin) pour les remplir : tant qu'elles sont vides, les syncs retombent sur l'ESI avec un warning par id.

## Décisions

Sans clé étrangère (même choix que `sde_sta_stations` côté colonnes dérivées, et un truncate de `sde_map_solar_systems` ne doit pas vider ces tables). Nom calculé à la lecture : la règle vit à un seul endroit.
