# Spec #319 : containers d'entrée et de sortie des jobs ESI d'un projet

Maquette : `industry-project-v3.html` (écrans « Jobs terminés hors container » et vérification du container). Décision de Sylvain du 2026-11-09 sur l'issue : garder tel quel (module unifié #362). Dépend de #318 (jobs ESI liés aux lignes de BOM, livré).

Révision du 2026-11-10 (issue rouverte) : un blueprint peut être n'importe où dans la station et n'est le plus souvent pas avec les matériaux. Le lieu d'entrée reste affiché, il ne déclenche plus aucun avertissement. Seule la sortie (`output_location_id` d'un job corpo désigne bien le container) est contrôlée.

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
7. **Front.** Onglet Contributions : tableau « Jobs ESI · containers d'entrée et de sortie » (un job par ligne : produit, pilote, entrée, sortie, contrôle), visible des seuls admins et propriétaire. Ligne « entrée → sortie » sous chaque job lié de la BOM (`JobsTable`). Textes en fr et en.
8. **Contrôle** (`jobLocationCheck`, front), dans cet ordre : sortie `outside` = « à déplacer » (`output-outside`, ambre) ; un côté `unknown` = « contrôle impossible » plus la raison traduite (`unknown`, gris) ; sinon ✓ (`ok`). Le statut `inside` / `outside` de l'entrée ne compte pas : un blueprint `outside` ne rend pas le contrôle ambre ni « à vérifier ». L'entrée est affichée en neutre (nom, division), grise seulement si inconnue. Un lieu `unknown` ne devient jamais `ok`.
9. **API.** `locations.blueprint.status` reste calculé et renvoyé tel quel (la règle 6 ne change pas) ; seul le front l'ignore pour le contrôle.

## Exemples chiffrés

| blueprint | output | périmètre | contrôle |
|---|---|---|---|
| container du projet | sous-container du projet | connu | ✓ |
| container personnel « Perso » (`outside`) | container du projet | connu | ✓ (l'entrée ne compte pas) |
| container du projet | hangar de station | connu | à déplacer |
| container du projet | `null` | connu | contrôle impossible (`location_missing`) |
| container personnel | hangar de station | `no_director` | à déplacer (la sortie hors container prime sur l'entrée inconnue) |
| container personnel | container du projet | `no_director` | contrôle impossible (`no_director`) |
| container personnel (`outside`) | `null` | connu | contrôle impossible (la sortie inconnue prime sur l'entrée hors container) |

## Cas limites

- Plusieurs containers du même nom : tous sont dans le périmètre (comme `verifyContainer`).
- Un job livré ou annulé reste affiché avec le dernier lieu connu.
- Un job lié dont `CachedIndustryJob` a disparu : `locations = null` pour ce lien.

## Hors périmètre

- Alerte « déplacer N produits » du compagnon : livrée par #506 (`docs/specs/506-companion-move-products-alert.md`), elle lit la sortie seule.
- Questions de l'issue tranchées par Sylvain le 2026-11-10 : le blueprint n'est pas avec les matériaux (entrée sans avertissement), `output_location_id` d'un job corpo désigne bien le container.
- Aucun nouveau topic Mercure : pas d'action asynchrone ajoutée, la sync des jobs publie déjà `industry-jobs`.
