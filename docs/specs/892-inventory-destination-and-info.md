# #892 — Destination et info en jeu depuis l'inventaire

Existant : `OpenInGameButton` (`market`, `info`, `contract`), `useOpenWindow`, `OpenWindowProcessor` (jeton du personnage principal, repli sur un alt), `OpenWindowService`, `AssetLocationGroup`. Absent : le scope `esi-ui.write_waypoint.v1` (seul `esi-ui.open_window.v1` est dans `AuthenticationService::REQUIRED_SCOPES`, 30 scopes), l'appel `POST /ui/autopilot/waypoint/`.

## Règles

1. **Scope.** `EveConstants::UI_WAYPOINT_SCOPE = 'esi-ui.write_waypoint.v1'` rejoint `REQUIRED_SCOPES` (30 devient 31) et l'URL d'autorisation. Les personnages déjà liés apparaissent « à reconnecter » (providers existants qui comparent aux scopes requis). Docs à passer à 31 : `CLAUDE.md`, `README.md`, `.claude/skills/esi-api/SKILL.md`.
2. **Service.** `OpenWindowService::setWaypoint(EveToken, int $destinationId)` : `POST /ui/autopilot/waypoint/?add_to_beginning=false&clear_other_waypoints=true&destination_id=<id>` (la destination remplace la route).
3. **API.** `POST /me/open-window/waypoint`, corps `{destinationId: int}`, réponse `{success, error}` comme les autres opérations de `OpenWindowResource`.
4. **Scope absent.** Si le jeton choisi par le processeur n'a pas `UI_WAYPOINT_SCOPE` : `success=false`, `error` explicite (scope manquant, reconnexion), aucun appel ESI. Pas de valeur par défaut plausible.
5. **Erreur ESI.** `EsiApiException` : `success=false`, `error` = message, journalisé (comportement des autres fenêtres, inchangé).
6. **Front, destination.** `OpenInGameButton` accepte `type="waypoint"` ; `useOpenWindow.openWaypoint(destinationId)` poste sur `/me/open-window/waypoint`. L'en-tête de chaque groupe de localisation de l'inventaire affiche ce bouton (infobulle « Set as destination » / « Definir comme destination ») avec `locationId` quand `locationType` du groupe est `station`, `solar_system` ou `other` (structure). Pas de bouton pour `item` (l'identifiant n'est pas un lieu) ni quand `locationType` est absent.
7. **Front, info.** Chaque conteneur de l'inventaire (ligne dont `isContainer`) affiche `OpenInGameButton type="info"` avec `itemId` ; les autres lignes n'en ont pas. Le clic sur le bouton ne replie ni ne déplie le conteneur ou la localisation (le bouton arrête déjà la propagation).
8. `Assets.vue` renseigne `locationType` du groupe depuis le premier actif.

## Cas limites

- Groupe « Asset Safety » : son identifiant est un objet, `locationType` vaut `item`, donc pas de bouton.
- Aucun jeton : `AccessDeniedHttpException` (comportement existant).

## Hors périmètre

Autres écrans (projet, minage), choix du personnage cible du waypoint (jeton du principal, comme les autres fenêtres), ajout de waypoint sans effacer la route.

## Décisions ouvertes

- ESI documente `information` pour personnage, corporation et alliance : l'ouverture d'un `itemId` de conteneur est demandée par l'issue, à vérifier en jeu après le déploiement.
- Utilisateurs à reconnecter pour le nouveau scope (cf. règle 1).
