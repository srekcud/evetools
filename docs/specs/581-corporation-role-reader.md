# #581 — Lecteur commun des rôles de corporation

Mode nuit : spec validée en autonomie. Circuit m, niveau de technicité 2 (accès : qui lit les extractions, la taxe lunaire et la santé des structures).

## Contexte

Cinq services cherchent « quel personnage du compte détient le rôle R dans la corporation C » chacun à sa façon : `CorporationMiningExtractionSyncService`, `PlannedStructureHealthReader`, `MoonTaxReaderCandidates` (observers et journal wallet), `MoonTaxManagerAccess`, `MoonCompositionReplacementPolicy`. Pas d'ordre commun, pas de mémoire, `Director` accepté par un seul, un token invalide affiché comme « rôle requis », un personnage sans rôle affiché `Nom ()`.

## Règles

1. `App\Service\ESI\CorporationRoleReader` (final readonly) expose `findHolder(corporationId, characters, roles, scopes)` et `readAsHolder(corporationId, characters, roles, scopes, \Closure $read)`. Les deux renvoient un `CorporationRoleLookup` : `status` (enum `CorporationRoleStatus` : `Held`, `RoleAbsent`, `ReconnectRequired`, `EsiError`), `holder` (le personnage retenu), `value` (retour de `$read`), `rolesReadByCharacterName`, `unreadableCharacterNames`. `characters` est déjà restreint à la corporation par l'appelant.
2. Rôles acceptés = rôles exigés + `Director` (le Director détient implicitement tous les rôles en jeu). Si l'ESI refuse (403) la lecture à un Director sans le rôle exact, le lecteur passe au suivant comme pour tout 403 : aucune hypothèse sur l'ESI n'est figée.
3. Candidats : personnages à `isAuthValid()` et token portant tous les scopes exigés. Ordre : les personnages principaux (`isMain()`) d'abord, puis `eveCharacterId` croissant.
4. Mémoire : le personnage retenu est gardé dans le pool `corporation_role_holder.cache` (durée 15 min : un rôle retiré cesse d accorder l accès au plus tard après ce délai) par (corporation, rôles exigés). Tant qu'il est candidat, il est essayé en premier sans relire ses rôles ; `findHolder` le renvoie sans aucun appel ESI.
5. La mémoire est vidée si le personnage retenu n'est plus candidat (token invalide, scope retiré), si `$read` ou la lecture de ses rôles lève un 403, ou une `EveAuthRequiredException`. Une autre erreur ESI (5xx, réponse illisible) la conserve. Le lecteur essaie alors les autres candidats.
6. Statut en échec, dans cet ordre : une erreur ESI non 403 ou une réponse illisible → `EsiError` ; sinon un 403 ou aucun rôle trouvé → `RoleAbsent` ; sinon `EveAuthRequiredException`, ou un personnage de la liste qui ne peut pas lire (token invalide, scope manquant) → `ReconnectRequired`. Aucun personnage dans la liste → `RoleAbsent`.
7. Migration des cinq services vers le lecteur ; `MoonTaxReaderCandidates` est supprimé (remplacé, son comportement est repris par les règles 3 à 6) ; `MoonTaxReadOutcome` reste pour les syncs observers et wallet (mappage statut → `MoonTaxSyncFailure` : `RoleAbsent` → `NoAccountantToken`, `ReconnectRequired` → `ReconnectRequired`, `EsiError` → `EsiError`).
8. Extractions : `CorporationMiningSyncFailure` gagne `ReconnectRequired` (`reconnect_required`, colonne string existante, pas de migration) ; le provider d'extractions répond `access: 'reconnect'` ; le front ajoute `'reconnect'` au type `CorporationMiningAccess` et un libellé (en, fr) « Reconnect a character with the scopes the extractions need ».
9. `MoonTaxManagerAccess` : `Held` accorde l'accès ; `EsiError` → 503 (liste des personnages illisibles) ; `RoleAbsent` → 403 listant les rôles lus, un personnage sans rôle s'écrit `Nom (no role)` ; `ReconnectRequired` sans aucun rôle lu → 403 au message distinct « no character of this corporation has a valid token with the scope … : reconnect one ». Le filtre de corporation par lecture ESI de l'affiliation (`readCorporationId`) reste inchangé et précède le lecteur.
10. `MoonCompositionReplacementPolicy` : `Held` pour `Director` ou `Station_Manager` autorise ; `EsiError` ou `ReconnectRequired` → `CorporationRolesUnreadable` (503) ; `RoleAbsent` → refus (false).

## Exemples (tests rouges)

| # | Situation | Résultat |
|---|---|---|
| E1 | principal et alt tous deux Station_Manager, l'alt a l'id le plus petit | `holder` = principal, une seule lecture de rôles |
| E2 | deux `findHolder` successifs, même corporation et mêmes rôles | un seul appel ESI aux rôles au total |
| E3 | `readAsHolder` : le personnage en mémoire reçoit un 403 de `$read`, un second est capable | mémoire vidée, second retenu et mémorisé, `Held` |
| E4 | personnage en mémoire dont le token est devenu invalide | mémoire vidée, les autres essayés |
| E5 | personnage `Director` sans `Station_Manager`, rôle exigé `Station_Manager` | `Held` ; l'extraction se lit |
| E6 | aucun personnage avec les scopes (token invalide) | `ReconnectRequired`, pas `RoleAbsent` |
| E7 | rôles lus sans le rôle exigé, tokens valides | `RoleAbsent`, `rolesReadByCharacterName` rempli |
| E8 | roles endpoint en 500 pour le seul candidat | `EsiError`, mémoire conservée si le candidat était en mémoire |
| E9 | alt candidat, principal sans scope | alt retenu, `Held` |
| E10 | extractions : aucun token valide | `lastFailure = ReconnectRequired`, `access = 'reconnect'` |
| E11 | taxe lunaire : personnage sans rôle | 403 « Name (no role) » |

## Tests existants modifiés

- `MoonTaxManagerAccessTest::testRolesAreReadAgainAtEveryCall` : contredit par la règle 4, devient « les rôles sont lus une fois ».
- Les harnais des quatre autres services et des tests d'intégration `MoonObserverLedgerSyncTest`, `CorporationWalletJournalSyncTest` : le constructeur reçoit le `CorporationRoleReader` à la place de `MoonTaxReaderCandidates`, avec un `ArrayAdapter` ; leurs assertions de comportement restent.

## Hors périmètre

`CorporationService`, `AnsiblexSyncService`, `BlueprintSyncService`, `AssetsSyncService` (rôle Director déjà lu autrement), `RecordMoonCompositionProcessor`, tout retrait de réglage ou de scope, durée de mémoire configurable.

## Termes du glossaire

Aucun nouveau terme.
