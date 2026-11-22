# #1299 Une étape n'est proposée qu'aux membres qui peuvent la lancer dans sa station

Circuit m, chaîne B, mode nuit. Décision de Sylvain (2026-11-22, commentaire de #1298) : droit d'amarrage = droit d'utiliser l'usine, pas de contrôle de l'ACL industrie ; `/universe/structures/{id}` résolu avec le jeton du personnage : 200 = accès, 403 = refusé ; un job déjà lancé dans la structure = accès ; sinon inconnu. Inconnu = étape proposée avec un badge, seul un refus confirmé la retire. Le propriétaire voit par station combien de membres peuvent lancer, sont refusés ou inconnus. Base : `PlacedAction` / `LaunchJobActions` (#1300).

## Règles

R1. **Statut.** Énumération `StructureAccessStatus` : `granted`, `denied`, `unknown`. Source de la preuve : `StructureAccessSource` `esi` ou `job`. Table `character_structure_access` (migration réversible, rien à transformer) : `id` uuid, `character_id` (FK `characters`, cascade), `structure_id` bigint, `status` (`granted` ou `denied` seulement), `source`, `checked_at`, unique `(character_id, structure_id)`. Pas de ligne = `unknown`.

R2. **Stations NPC.** Un `locationId` inférieur à 1 000 000 000 000 est une station NPC : toujours `granted`, aucune sonde, aucune ligne stockée.

R3. **Lecture d'un couple** (`CharacterStructureAccessReader::statusOf(Character, int $structureId)`) : la preuve la plus récente gagne. Preuve de job = job `CachedIndustryJob` du personnage avec `stationId = structureId`, daté de sa `endDate`. Preuve stockée = la ligne, datée de `checked_at`. Job plus récent que la ligne `denied` (ou sans ligne) : `granted`. Ligne `denied` plus récente que tous les jobs : `denied`. Ligne `granted` : `granted`. Aucune preuve : `unknown`. Aucun appel ESI en lecture.

R4. **Sonde ESI** (`StructureAccessProbe::probe(Character, int)` renvoie un statut) : `GET /universe/structures/{id}/` avec le jeton du personnage. 200 : `granted`. 403 et jeton avec le scope `esi-universe.read_structures.v1` : `denied`. Un jeton sans le scope n'est jamais sondé. Tout le reste (401, 404, 420, 429, 5xx, réseau, corps illisible, 403 d'un autre endpoint que la structure, échec du rafraîchissement du jeton, personnage sans jeton ou avec auth à refaire) : `unknown`, rien n'est stocké. Aucune exception avalée sans retour `unknown`.

R5. **Rafraîchissement** (`StructureAccessRefresher::refreshProject(IndustryProject): StructureAccessRefreshReport`). Couples : personnages de chaque membre accepté (propriétaire compris) × structures du plan (stations distinctes des lignes, résolues dans les configurations du propriétaire comme `ActionPlaceResolver::ofStep`, `locationId` ≥ 10^12 seulement). Par couple, dans l'ordre : preuve de job présente : ligne `granted`/`job` posée ou mise à jour, aucune sonde ; ligne `denied` de moins de 24 h ou `granted`/`esi` de moins de 7 jours : rien ; sinon sonde (R4), le résultat `granted`/`denied` remplace la ligne. Au plus 20 sondes par passage ; arrêt dès que `EsiErrorBudget::snapshot()['level']` vaut `low` ou `critical` (`unknown` = fenêtre écoulée, budget reconstitué) ; le rapport dit `probed`, `skipped`, `stoppedForBudget`, `capped`. Un couple sondé `unknown` n'écrit rien et sera repris au passage suivant.

R6. **Déclenchement asynchrone.** `POST /industry/projects/{projectId}/structure-access/refresh` (`input: EmptyInput`, gestionnaire = `IndustryProject::isManagedBy`, 403 sinon, 404 projet inconnu ou uuid invalide) répond 202 et poste `RefreshProjectStructureAccess(projectId)` ; le handler appelle le rafraîchisseur puis publie `structure_access_changed` via `publishGroupProjectEvent` (topic `/group-project/{id}/events`, déjà déclaré), données `{probed, skipped, stoppedForBudget, capped}`. Projet disparu entre-temps : le handler ne fait rien. Pas de tâche planifiée, pas de hook à l'acceptation d'un membre (les écrans qui lisent l'accès le déclenchent).

R7. **Règle d'offre.** Statut d'un membre pour une structure (`MemberStructureAccess::of(User, int)`) sur ses personnages : `granted` si l'un est `granted` ; `denied` si au moins un personnage et tous `denied` ; sinon `unknown` (aucun personnage compris). `LaunchJobActions` n'émet pas l'action d'une étape dont la station est une structure et le statut du lecteur `denied`. `granted` ou `unknown` : émise. `PlacedAction` gagne `access` (`StructureAccessStatus|null`) : `granted`/`unknown` pour une étape en structure, `null` pour station NPC, lieu inconnu (`station_unresolved`) ou autre action. Une étape sans lieu résolu reste proposée, `access = null`.

R8. **Ressource.** `PlacedActionResource` expose `access` (`'granted'`, `'unknown'` ou `null`, jamais `'denied'`). Aucun autre changement de sortie.

R9. **Vue du propriétaire.** `GET /industry/projects/{projectId}/station-access` (`ProjectStationAccessResource`, `uriVariables` + `Link`, gestionnaire seulement : 403 sinon, 404 inconnu) : une entrée par station du plan (distinctes, résolues comme en R5, NPC comprises) : `locationId` (identifiant), `name`, `solarSystemId`, `canLaunch`, `denied`, `unknown` : nombres de membres acceptés (propriétaire compris) par statut R7 ; une station NPC compte tous les membres en `canLaunch`. Jamais de nom de personnage ni d'identifiant d'un autre utilisateur. Aucune écriture, aucun appel ESI.

R10. **Front.** Type `PlacedAction.access` (`'granted' | 'unknown' | null`) dans `stores/group-industry/types.ts` ; la ligne d'action de la page À faire (`TodoPlaceGroup.vue`) montre un badge « Accès inconnu » quand `access === 'unknown'`, aucun badge sinon. Libellés FR et EN. Jamais de badge « refusé » (l'action n'existe pas).

R11. **Glossaire.** Terme « Accès à la station » (droit d'amarrage d'un personnage, trois statuts, preuves, fraîcheurs) ajouté dans la même livraison.

## Exemples (tests rouges)

Projet P in_progress, propriétaire U (personnage C1), membre accepté V (personnage C2), étape « Composant X » en structure S (10^12 + 5).
- C1 sans ligne ni job : `unknown` ; action émise, `access = unknown`.
- C1 avec un job terminé en S : `granted`. Ligne `denied` posée avant la fin du job : `granted`. Ligne `denied` après la fin du job : `denied`.
- U avec C1 `denied` et C3 `unknown` : `unknown` (action émise). C1 `denied` seul : `denied` (action absente). C1 `denied`, C3 `granted` : `granted`.
- Station NPC (60003760) : `access = null`, aucune sonde.
- Sonde : 200 → granted ; 403 + scope → denied ; 403 sans scope → unknown ; 404, 420, 429 → unknown ; personnage sans jeton → unknown ; rien stocké sur `unknown`.
- Rafraîchissement : 2 membres × 1 structure, aucune preuve : 2 sondes, 2 lignes. Ligne `denied` de 23 h : 0 sonde ; de 25 h : 1 sonde. Ligne `granted`/`esi` de 6 jours : 0 sonde ; de 8 jours : 1. 45 couples : 20 sondes, `capped`. Niveau de budget `low` : 0 sonde, `stoppedForBudget` ; niveau `unknown` : la sonde a lieu. Preuve de job : 0 sonde, ligne `granted`/`job`.
- API vue : U propriétaire (`unknown`), V `granted`, W `denied`, X `unknown` sur S : `canLaunch 1`, `denied 1`, `unknown 2` ; 403 pour un membre simple ; 404 pour un uuid inconnu.
- API refresh : 202 + message posté (gestionnaire), 403 membre simple, 404.

## Hors périmètre

ACL industrie ; tâche planifiée ; hook à l'acceptation d'un membre ; badge ailleurs que la page À faire (compagnon #1304, page projet #1307, choix de stations #1289 : ils liront `access` et `station-access`) ; retrait d'une action déjà émise côté compagnon ; test ESI réel du 403 (Sylvain, avant la fusion finale).

## Décisions

D1 : statut calculé à la lecture (job + ligne), pas de synchronisation des jobs vers la table. D2 : une étape sans lieu résolu reste proposée sans badge. D3 : plafond 20 sondes (chaque refus est une erreur du budget partagé de l'ESI, revue ESI), arrêt sur les niveaux `low` et `critical` seulement ; cooldown de 5 minutes par projet sur le POST de rafraîchissement (429, revue sécurité). D4 : le propriétaire et les administrateurs voient les comptes, pas les noms.
