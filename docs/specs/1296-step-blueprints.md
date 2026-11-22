# #1296 — Attach a specific blueprint to its project step and check its station

Circuit `m`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype (« un blueprint est un objet du flux : il doit être dans la station du job »). Back-end et couche de données front : la grille par station est #1307, le détail de carte #1310 ; ici on livre le lien, le contrôle de station et le transfert à faire.

## Contexte

`OwnedBlueprint` (sync des blueprints perso et corpo) porte `itemId`, `typeId`, ME, TE, runs, personnage ou corporation, mais rien ne le relie à une ligne de job de la BOM (`IndustryProjectBomLine`, `isJob`, `activityType`, `plannedStructureId`), ni ne dit s'il est dans la station du job. La station de l'étape se lit par `ActionPlaceResolver::ofStep` (configuration de structure du propriétaire). La station d'un objet se lit par `AssetStations::locationOf` sur les assets visibles (`PersonalStockReader`, `ProjectContainerReadings` font de même).

## Règles

R1. **Blueprint d'étape.** Entité `IndustryProjectLineBlueprint` : `id` (uuid v7), `project`, `bomLine` (la ligne perd ses blueprints avec elle : `orphanRemoval` côté ligne, `ON DELETE CASCADE`), `itemId` (bigint, l'objet EVE), `attachedBy` (User nullable, `ON DELETE SET NULL`), `attachedAt`. Unicité `(project_id, item_id)` : un objet n'est rattaché qu'à une seule ligne d'un même projet (409 sinon). Plusieurs blueprints par ligne permis (5 BPC pour 5 jobs de l'Ishtar : un par ligne ; deux BPC pour une même ligne : permis). Migration réversible, table vide au départ, aucune donnée existante transformée.

R2. **Ligne éligible.** `isJob` et `activityType` = `manufacturing` ou `reaction`. Copie et invention : 422 `line_not_blueprint_step`.

R3. **Blueprint éligible.** Un `OwnedBlueprint` visible de l'utilisateur qui rattache (ses personnages, plus les blueprints de sa corporation que `CorpAssetVisibility::showsBlueprintIn` lui montre ; réutiliser la lecture d'`OriginalBlueprintFinder`, qui rend sa méthode `visibleBlueprintsOf` publique) dont le `typeId` est le blueprint qui produit le type de la ligne pour l'activité de la ligne (`IndustryActivityProductRepository::findBlueprintForProduct(typeId, 1)` fabrication, `11` réaction). Copie (`isCopy`) ou original (`isOriginal`) acceptés : `kind` = `bpc` | `bpo`. Item invisible ou inconnu : 422 `blueprint_not_visible` ; autre type : 422 `blueprint_does_not_fit_line`.

R4. **Qui peut quoi.** Lire, lister les candidats, rattacher : participant `accepted` ou propriétaire (`ProjectAccessChecker::assertAcceptedMember`, 403 étranger, 404 projet inconnu, 401 anonyme). Détacher : celui qui a rattaché, le propriétaire ou un admin (403 sinon). Projet `completed` : 409 pour rattacher et détacher. Ligne d'un autre projet ou inconnue : 404.

R5. **Lecture de la position** (`LineBlueprintPositions`, service partagé par la liste et les candidats). Pour un blueprint et une ligne :
- station de l'étape = `ActionPlaceResolver::ofStep($line, $project->getOwner())` ;
- station du blueprint = `AssetStations::locationOf` de son asset. Blueprint perso (`OwnedBlueprint::getCharacter` non nul) : assets de ce personnage (`CachedAssetRepository::findByCharacter`, lus une fois par personnage). Blueprint corpo : `VisibleCorporationAssetsFinder::findVisibleAssets` pour l'utilisateur dont la visibilité vaut (celui qui rattache), après `ProjectContainerService::unavailabilityReasonOfUser` ;
- `state` : `here` (même `locationId`), `elsewhere` (autre station), `unknown` avec `unknownReason`, jamais `here` par défaut. Raisons : `blueprint_not_synced` (plus d'`OwnedBlueprint` pour cet `itemId`, ou plus visible de celui qui l'a rattaché), `attacher_unknown` (rattacheur supprimé), `step_station_unresolved` (lieu d'étape inconnu), `asset_not_found` (aucun asset pour l'objet : blueprint en cours de job ou assets non synchronisés), `station_unresolved` (la chaîne de containers n'atteint pas une station), ou la raison d'indisponibilité des assets corpo reprise telle quelle.

R6. **Transfert à faire.** Quand `state = elsewhere`, la lecture porte `transferToMake` : `{typeId, typeName, itemId, quantity: 1, from: {locationId, name, solarSystemName}, to: {locationId, name, solarSystemId, solarSystemName}}` (la station de l'étape est la destination). C'est la donnée que #1307 et #1310 afficheront et qui se déclare par le `POST station-transfers` de #1295 (ligne par type, quantité 1). Aucune déclaration automatique, aucun coût ISK. `transferToMake` vaut `null` sinon.

R7. **Lire.** `GET /industry/projects/{projectId}/line-blueprints` : tous les blueprints d'étape du projet, ordre `attachedAt`. Champs : `id`, `bomLineId`, `itemId`, `typeId`, `typeName`, `kind`, `runs` (null pour un original), `materialEfficiency`, `timeEfficiency`, `attachedBy` (nom du personnage principal ou null), `attachedAt`, `stepPlace`, `blueprintPlace` (`locationId`, `name`, `solarSystemName`, nuls si inconnus), `state`, `unknownReason`, `transferToMake`. ME, TE et runs sont ceux du dernier sync ; si l'`OwnedBlueprint` a disparu, ces champs valent `null` (jamais 0) avec `blueprint_not_synced`.

R8. **Candidats.** `GET /industry/projects/{projectId}/line-blueprint-candidates` : pour chaque ligne éligible (R2) du projet et chaque blueprint éligible (R3) visible de l'appelant et pas encore rattaché dans ce projet : `bomLineId`, `itemId`, `typeId`, `typeName`, `kind`, `runs`, `materialEfficiency`, `timeEfficiency`, `state`, `unknownReason`, `transferToMake` calculés comme R5 et R6. Ordre : ligne (nom du type), puis `elsewhere` après `here`, puis runs décroissants.

R9. **Rattacher.** `POST /industry/projects/{projectId}/line-blueprints` : `bomLineId` (uuid), `itemId` (entier positif) ; 201, réponse = la lecture R7 du blueprint rattaché. Validation : types, `itemId` > 0 ; 422 pour R2, R3 ; 409 pour l'unicité (R1) et le projet terminé.

R10. **Détacher.** `DELETE /industry/projects/{projectId}/line-blueprints/{id}` (provider qui renvoie la ressource), 204. Détacher ne touche ni le blueprint dans le jeu ni les jobs liés.

R11. **Temps réel.** Rattacher et détacher publient `line_blueprint_changed` par `MercurePublisherService::publishGroupProjectEvent` (topic projet existant), donnée `{lineId, itemId, action}` avec `action` = `attached` | `detached`. Pas de nouveau topic, pas de câblage front dans les rafraîchissements de la page (aucun écran ne l'affiche avant #1307).

R12. **Front, couche de données seulement.** Types et module `lineBlueprints.ts` dans `frontend/src/stores/group-industry/` (charger, charger les candidats, rattacher, détacher), `state` et `null` conservés, jamais convertis en 0 ni en `here`.

## Exemples (tests rouges)

Ligne Ishtar (fabrication, étape à la station S, `locationId` 100). Blueprint d'Ishtar : BPC 20 runs ME 2 TE 4.

| Cas | Résultat |
|---|---|
| BPC en hangar perso du personnage, station 100 | `here`, `transferToMake` null |
| BPC en hangar perso à la station 200 | `elsewhere`, `transferToMake.from` = station 200, `to` = station 100, quantité 1, `typeId` = blueprint |
| BPC dans un container du hangar de la station 100 (asset parent = container, parent = station) | `here` |
| BPC corpo en division visible de l'utilisateur, station 100 | `here` |
| BPC corpo, division masquée à l'utilisateur | rattachement refusé 422 `blueprint_not_visible` |
| Blueprint rattaché puis `OwnedBlueprint` supprimé par la sync | `unknown`, `blueprint_not_synced`, ME, TE, runs `null` |
| Ligne sans `plannedStructureId` | `unknown`, `step_station_unresolved`, pas de `transferToMake` |
| Aucun asset pour l'item | `unknown`, `asset_not_found` |
| Blueprint de Vexor sur la ligne Ishtar | 422 `blueprint_does_not_fit_line` |
| Ligne de copie ou d'invention | 422 `line_not_blueprint_step` |
| Même `itemId` sur deux lignes du projet | 409 |
| Détacher par un autre membre qui n'est ni propriétaire ni admin | 403 |
| Détacher par le propriétaire | 204, `line_blueprint_changed` détaché |
| Ligne retirée du plan | ses blueprints d'étape disparaissent |
| Candidats : un BPC rattaché | absent des candidats |

## Hors périmètre

Carte et colonnes du kanban (#1307, #1308, #1310) ; action « emporter » (source d'actions de #1300) ; vérification des runs du BPC contre les runs de la ligne ; blueprint partagé entre deux projets ouverts ; déclaration automatique du transfert ; blueprint d'une ligne de copie ou d'invention (#1316, #1193).

## Décisions

- Originaux acceptés en plus des copies (le prototype pose un BPO de composant à la station de la ligne).
- `from` du transfert n'a pas d'identifiant de système (l'asset n'en porte que le nom) : #1307 le résout à la déclaration.
- Un blueprint en cours de job n'a plus d'asset visible : `asset_not_found`, pas `here`.
