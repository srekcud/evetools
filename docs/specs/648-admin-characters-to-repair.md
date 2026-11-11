# #648 — Admin: characters with a token or scopes to repair (lot 1)

Module : infra (page admin). Circuit m. Lot partiel : la liste. Le lien vers la fiche utilisateur (#647) et le bandeau d'état (#642) n'existent pas sur origin/main.

## Règles

1. `GET /api/admin/stats` expose `charactersToRepair` : `count` et `characters[]`. Admin seulement (déjà garanti par le provider).
2. Un personnage avec un token entre dans la liste dans deux cas :
   - `invalid` : son autorisation est invalide (`AuthStatus::Invalid`, **autorisation révoquée** du glossaire) ;
   - `missing_scopes` : son autorisation est valide mais le token ne porte pas tous les scopes de `AuthenticationService::getRequiredScopes()`.
3. Un personnage invalide qui manque aussi de scopes reste `invalid` et liste ses scopes manquants. Un personnage valide et complet n'est pas listé. Un personnage sans token n'est pas listé.
4. `missingScopes` : scopes requis absents du token, dans l'ordre de `getRequiredScopes()`. Un scope en trop sur le token n'est jamais listé.
5. Chaque ligne : `eveCharacterId`, `characterName`, `userId` (null si le personnage n'a pas d'utilisateur), `userLastLoginAt` (ISO 8601 ou null, jamais 0), `state`, `missingScopes`, `sinceAt` (dernière mise à jour du token, ISO 8601).
6. Tri : `invalid` avant `missing_scopes`, puis `sinceAt` croissant (le plus ancien d'abord), puis nom.
7. `count` = nombre de lignes. Liste vide : `count` 0, `characters` vide, état « rien à réparer » à l'écran.
8. Page admin : carte `CharactersToRepairCard` (compteur, tableau personnage / dernière connexion / état / scopes manquants / depuis, états traduits en FR et EN), sous la carte Mercure. Pas de lien de ligne (fiche inexistante).

## Cas limites
- `userLastLoginAt` null : affiché « jamais » (clé i18n), jamais une date par défaut.
- Beaucoup de scopes manquants (nouveau scope ajouté) : la ligne les liste tous, la cellule retourne à la ligne.

## Hors périmètre
- Notifier le joueur ; le lien vers la fiche (#647) ; l'alimentation du bandeau (#642) ; l'état « expire sous 24 h » (aucune donnée de refresh en cours, voir journal).
