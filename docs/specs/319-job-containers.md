# Spec #319 : containers d'entrée et de sortie des jobs ESI d'un projet

Maquette : `industry-project-v3.html` (écrans « Jobs terminés hors container » et vérification du container). Décision de Sylvain du 2026-11-09 sur l'issue : garder tel quel (module unifié #362). Dépend de #318 (jobs ESI liés aux lignes de BOM, livré).

## Règles

1. **Sync.** `CachedIndustryJob` garde `blueprintLocationId` et `outputLocationId` (bigint nullable, colonnes `blueprint_location_id`, `output_location_id`, migration). `IndustryJobSyncService` les écrit à la création et à chaque mise à jour d'un job (personnel et corpo). Champ absent de l'ESI : `null`, jamais de valeur par défaut.
2. **Qui voit.** Chaque lien de job (`ProjectJobMatchResource`, lecture de la BOM) porte `locations`. Il est rempli pour le propriétaire et les admins du projet, `null` pour les autres participants. `ProjectAccessChecker::isAdminOrOwner` (nouveau, `assertAdminOrOwner` l'utilise).
3. **Forme.** `locations = {blueprint: Side, output: Side}`, `Side = {locationId: int|null, kind: 'container'|'hangar'|null, name: string|null, divisionName: string|null, status: 'inside'|'outside'|'unknown', unknownReason: string|null}`.
4. **Résolution d'un lieu** (`ProjectJobLocationResolver`), dans cet ordre :
   - id absent (job synchronisé avant la migration) : inconnu, raison `location_missing` ;
   - un asset dont `itemId` = id : `kind=container`, `name=itemName` (à défaut `typeName`), `divisionName`. Un asset corpo n'est retenu que s'il est visible du propriétaire du projet (visibilité des divisions) ; un asset personnel l'est toujours ;
   - sinon `IndustryCalculationService::resolveFacilityName(id)` non nul : `kind=hangar`, `name` = ce nom ;
   - sinon inconnu : raison `assets_not_synced` si le personnage du job n'a aucun asset en cache ni sa corporation, sinon `location_unresolved`.
5. **Container du projet.** `ProjectContainerService::containerScopeOf(project)` renvoie les `itemId` des containers du nom du projet (même recherche que `verifyContainer`, sous-containers et contenu compris) ou une raison d'inconnu : `no_container_name`, `owner_without_corporation`, `no_director`, `corporation_assets_not_synced`, `no_visible_division`, `container_not_found`. `verifyContainer` garde son comportement.
6. **Statut d'un côté.** Lieu inconnu : `unknown` (raison de la règle 4). Hangar : `outside`, même si le container du projet est inconnu (une station n'est pas un container). Container : `inside` si son `itemId` est dans le périmètre, `outside` s'il n'y est pas, `unknown` (raison du périmètre) si le périmètre est inconnu.
7. **Front.** Onglet Contributions : tableau « Jobs ESI · containers d'entrée et de sortie » (un job par ligne : produit, pilote, entrée, sortie, contrôle), visible des seuls admins et propriétaire. Ligne « entrée → sortie » sous chaque job lié de la BOM (`JobsTable`). Contrôle : les deux côtés `inside` = ✓ ; entrée `outside` = avertissement « entrée hors du container du projet » ; sortie `outside` = « à déplacer » ; un côté `unknown` sans autre côté `outside` = « contrôle impossible » plus la raison traduite. Textes en fr et en.

## Exemples chiffrés

| blueprint | output | périmètre | contrôle |
|---|---|---|---|
| container du projet | sous-container du projet | connu | ✓ |
| container personnel « Perso » | container du projet | connu | entrée hors du container (avertissement) |
| container du projet | hangar de station | connu | à déplacer |
| container du projet | `null` | connu | contrôle impossible (`location_missing`) |
| container personnel | hangar de station | `no_director` | à déplacer (la sortie hors container prime sur l'entrée inconnue) |
| container personnel | container du projet | `no_director` | contrôle impossible (`no_director`) |

## Cas limites

- Plusieurs containers du même nom : tous sont dans le périmètre (comme `verifyContainer`).
- Un job livré ou annulé reste affiché avec le dernier lieu connu.
- Un job lié dont `CachedIndustryJob` a disparu : `locations = null` pour ce lien.

## Hors périmètre

- Alerte « déplacer N produits » du compagnon : aucun écran compagnon dans `frontend/src` à ce jour, lot partiel, l'issue reste ouverte.
- Questions de l'issue à vérifier en jeu (matériaux pris au lieu du blueprint ; `output_location_id` d'un job corpo) : on affiche ce que l'ESI renvoie.
- Aucun nouveau topic Mercure : pas d'action asynchrone ajoutée, la sync des jobs publie déjà `industry-jobs`.
