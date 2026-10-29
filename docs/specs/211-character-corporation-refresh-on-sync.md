# Spec #211 — Corporation du personnage relue au début de chaque sync des jobs

**Statut : validée en autonomie en mode nuit le 2026-10-29, à relire par Sylvain.**

Tests unitaires seulement (pas de base la nuit) : doublure de `CharacterService` dans `IndustryJobSyncServiceTest` et `SyncCharacterIndustryJobsHandlerTest`, `EsiClient` mocké pour `CharacterServiceTest`. Aucun test d'intégration.

## Contexte

- `Character::$corporationId` (`src/Entity/Character.php:30-31`, `bigint` non nullable), `$corporationName`, `$allianceId`, `$allianceName` ne sont écrits qu'à la connexion EVE SSO : `AuthenticationService::createCharacter()` (`:256-273`) et `updateCharacterInfo()` (`:275-282`), appelée par `findOrCreateCharacter()` seulement. L'ajout d'un personnage déjà lié (`addCharacterToUser()`, `:206-213`) ne relit même pas la corporation.
- La source utilisée est `CharacterService::getCharacterInfo(int $characterId)` (`src/Service/ESI/CharacterService.php:17-43`) : `GET /characters/{id}/` (public, `EsiClient::getWithCache()`), puis `GET /corporations/{corporation_id}/` pour le nom, puis `GET /alliances/{alliance_id}/` si le personnage a une alliance. Elle rend un `CharacterInfoDto` (`corporationId: int`, `corporationName: string`, `allianceId: ?int`, `allianceName: ?string`). Un `corporation_id` absent de la réponse n'est pas contrôlé : clé indéfinie puis `TypeError` à la construction du DTO.
- `getWithCache()` sert la réponse depuis `esi.cache` jusqu'à l'`Expires` de l'ESI, revalide ensuite par ETag, et rend la copie précédente si l'ESI est injoignable (erreur réseau seulement) (`EsiClient.php:172-206`).
- `IndustryJobSyncService::syncCharacterJobs()` lit les jobs corpo de `$character->getCorporationId()` (`IndustryJobSyncService.php:78-92`, cache `industry_corporation_jobs_{corporationId}`, spec #213). Après un changement de corporation en jeu, la sync interroge l'ancienne corporation : 403 (plus de rôles) → jobs corpo inconnus, aucun job marqué livré (spec #122 R1/R3), à chaque sync, sans fin ; les jobs de la nouvelle corporation ne sont jamais lus.

## Règles métier

- **R1 — Relecture au début de la sync.** Au début de `syncCharacterJobs()`, après le contrôle du token (personnage sans token : rien, comme aujourd'hui) et après `syncStarted`, la sync appelle `CharacterService::getCharacterInfo($character->getEveCharacterId())` **exactement une fois**, avant toute lecture de jobs (perso ou corpo). Elle a lieu que le token porte ou non le scope des jobs corpo : la corporation sert aussi aux autres modules (accès Group Industry, structures corpo partagées, escalations, assets corpo, topics Mercure).
- **R2 — Mise à jour de l'affiliation.** Le personnage reçoit la corporation (identifiant et nom) et l'alliance (identifiant et nom, `null` sans alliance) lues. La lecture des jobs corpo de cette sync, et la clé de cache #213, utilisent la corporation lue. La valeur est écrite en base au flush de fin de sync, avec les jobs.
- **R3 — Échec de lecture : l'erreur remonte, rien n'est deviné.** Toute exception de `getCharacterInfo()` (une des trois lectures : 404, 420 après la relance d'`EsiClient`, 5xx, réseau sans copie en cache, réponse invalide) remonte de `syncCharacterJobs()`, comme l'échec de lecture des jobs corpo de spec #122 R2 : `syncError` publié sur Mercure, aucun job lu, aucun flush, rien marqué livré, l'affiliation enregistrée reste l'ancienne. Aucun repli sur la corporation enregistrée pour continuer la sync : elle peut être fausse, c'est le bug. Messenger refait la tentative ; `MatchJobsProcessor` et `MatchProjectJobsCommand` gèrent déjà l'exception par personnage.
- **R4 — Corporation absente de la réponse.** Dans EVE, un personnage appartient toujours à une corporation (une corporation PNJ quand il quitte une corporation joueur) : il n'y a pas d'état « sans corporation ». Une réponse `GET /characters/{id}/` sans `corporation_id` entier est invalide : `getCharacterInfo()` lève `\UnexpectedValueException` qui nomme l'endpoint (même forme que `EsiClient.php:91`), puis R3. `Character::$corporationId` reste non nullable.
- **R5 — Corporation inchangée.** Corporation lue égale à la corporation enregistrée : comportement d'aujourd'hui, specs #122, #207, #210 et #213 inchangées.
- **R6 — Ancienne corporation jamais relue.** Après un changement A → B, la sync ne lit ni l'endpoint corpo de A ni l'entrée de cache de A. Le 403 de A disparaît ; les jobs de B suivent spec #122 (403 de B si le personnage n'y a pas les rôles) et spec #213 (cache de B).
- **R7 — Une relecture par sync, coût ESI borné par le cache.** Une sync de jobs d'un personnage = un appel à `getCharacterInfo()`. Les appels ESI réels sont bornés par `getWithCache()` : dans la durée `Expires` de l'ESI, aucun appel réseau.

## Exemples chiffrés

Personnage 12345 (utilisateur U1), enregistré en corporation 98000001 « Old Corp », sans alliance, token avec les scopes jobs perso et jobs corpo. Nouvelle corporation 98000002 « New Corp ». Job corpo 710 installé par 12345 dans 98000002 (`active`). Job perso 100 en base (`active`, fin passée), absent de l'ESI.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | ESI place 12345 en 98000002 « New Corp » ; lecture corpo de 98000002 OK, contient 710 | lit `/corporations/98000001/industry/jobs/…` | `corporationId = 98000002`, `corporationName = "New Corp"` ; `getPaginatedWithExpiry` appelé une fois, avec `/corporations/98000002/industry/jobs/?include_completed=true` ; jamais avec 98000001 ; 710 enregistré, attribué à 12345 ; 100 marqué `delivered` ; un flush |
| E2 | ESI place 12345 en 98000001 (inchangée) | lit 98000001 | inchangé : lit 98000001 (ou son cache) ; affiliation inchangée ; `getCharacterInfo` appelé une fois |
| E3 | `getCharacterInfo` lève `EsiApiException` 503 | — | l'exception remonte ; jobs perso et corpo non lus ; aucun flush ; `corporationId = 98000001` ; `syncError` publié pour U1 ; 100 reste `active` |
| E4 | `getCharacterInfo` lève `EsiApiException` 420 (après la relance du client) | — | idem E3 |
| E5 | Réponse `GET /characters/12345/` sans `corporation_id` | `TypeError` (clé indéfinie) | `\UnexpectedValueException` levée par `getCharacterInfo()`, puis idem E3 |
| E6 | Enregistré 98000001, ESI dit 98000002 ; 12345 n'a plus de rôles dans 98000001 | 403 sur 98000001, warning, 100 reste `active`, à chaque sync | aucun appel à 98000001 ; lecture de 98000002 ; 100 marqué `delivered` (idem E1) |
| E7 | ESI dit 98000002 ; lecture corpo de 98000002 en 403 (pas de rôles dans B) | 403 sur 98000001 | `corporationId = 98000002` écrit (flush) ; spec #122 R1/R3 sur 98000002 : warning, aucun job marqué livré |
| E8 | ESI dit 98000002 ; lecture corpo de 98000002 en 503 | — | l'exception remonte, aucun flush dans cette sync (spec #122 R2) |
| E9 | ESI dit 98000002, alliance 99000001 « New Alliance » | alliance inchangée | `allianceId = 99000001`, `allianceName = "New Alliance"` |
| E10 | Enregistré avec alliance 99000001, ESI sans alliance | alliance inchangée | `allianceId = null`, `allianceName = null` |
| E11 | Token sans scope jobs corpo, ESI dit 98000002 | — | `corporationId = 98000002` ; aucune lecture corpo (spec #122 R4) |
| E12 | Personnage sans token | aucun appel | inchangé : `getCharacterInfo` jamais appelé |
| E13 | Cache #213 chaud pour 98000001, ESI dit 98000002 | sert le cache de 98000001 | cache de 98000001 non lu ; lecture (ou cache) de 98000002 |

## Cas limites

- **Délai de l'ESI.** `GET /characters/{id}/` est mis en cache par l'ESI [TO CONFIRM: durée exacte ; 1 jour d'après CCP sur le forum ESI, porté un temps à 7 jours]. Tant que l'ESI rend l'ancienne corporation, R5 s'applique (403 éventuel sur l'ancienne, spec #122) : le changement est pris au plus tard à l'expiration côté ESI, au lieu de jamais.
- **Copie périmée sur erreur réseau.** `getWithCache()` rend la dernière réponse ESI quand l'ESI est injoignable (comportement existant du client). C'est une valeur lue à l'ESI, pas un repli inventé : elle est appliquée comme une lecture réussie.
- **Alt dans une autre corporation.** Chaque message `SyncCharacterIndustryJobs` relit l'affiliation de son seul personnage ; l'attribution des jobs (`installerLookup`) ne dépend pas de la corporation.
- **Main changé de corporation.** `User::getCorporationId()` (corporation du main) suit le main dès la sync de ses jobs : accès Group Industry (`GroupProjectAccessChecker`, `JoinGroupProjectProcessor`), structures corpo partagées (`CorporationStructureProvider`), escalations corpo et topics Mercure (`TokenProvider`) basculent sur B.
- **Cache `corp_*`.** Les entrées `corp_projects_{corporationId}`, `corp_project_contributors_*`, `corp_project_details_*` (`ScanLootSalesProcessor.php:513-573`) et `industry_corporation_jobs_{corporationId}` sont indexées par corporation : rien à purger. Celles de A ne sont plus lues par ce personnage et expirent seules ; aucune donnée de A n'est servie au nom de B.
- **Structures corpo partagées.** Les `IndustryStructureConfig` gardent la `corporationId` posée à leur création (`CreateStructureProcessor.php:76-81`) : celles créées en A restent partagées avec A. Pas de migration (hors périmètre).
- **Échec de la lecture corpo après une relecture réussie (E8).** L'affiliation modifiée en mémoire n'est pas flushée par cette sync ; un flush ultérieur dans la même requête (`MatchJobsProcessor` qui continue avec le personnage suivant) peut l'écrire. Les deux sont acceptables : c'est la valeur lue à l'ESI. Le test ne vérifie que l'absence de flush dans la sync en échec.

## Hors périmètre

- `POST /characters/affiliation/` (affiliations en lot, cache ESI plus court [TO CONFIRM: 1 h d'après un joueur sur le forum ESI]) : réduirait le délai du premier cas limite, mais ajoute un appel et une méthode client ; à ouvrir en issue si le délai gêne.
- Relecture de l'affiliation par les autres syncs (assets, ansiblex, PVE, minage) et à l'ajout d'un personnage déjà lié (`addCharacterToUser()`).
- Mise à jour du nom du personnage (`setName`) hors connexion.
- Structures corpo partagées créées dans l'ancienne corporation, escalations déjà créées (`Escalation::$corporationId`).
- Budget d'erreurs ESI suivi par processus.

## Décisions retenues en autonomie

- **D1 — Source : `CharacterService::getCharacterInfo()`**, celle de la connexion, plutôt qu'un nouvel appel ou `POST /characters/affiliation/` : aucune méthode client nouvelle, même DTO, cache ETag existant. Coût : le délai du cache ESI (cas limites).
- **D2 — Relecture au début de chaque sync de jobs, scope corpo ou non** : une seule règle, et la corporation sert à d'autres modules. Une sync = un appel (R7), pas de suivi « déjà relu ce cycle » : le cache `getWithCache()` borne les appels réseau.
- **D3 — Échec : l'exception remonte (R3)**, pas de sync dégradée « jobs corpo inconnus » avec l'ancienne corporation. Option la plus simple, cohérente avec spec #122 R2, sans valeur plausible ; elle bloque aussi les jobs perso de cette sync, accepté vu que les lectures sont en cache et que Messenger relance.
- **D4 — `corporation_id` absent : `\UnexpectedValueException` dans `getCharacterInfo()` (R4)**, plutôt qu'un `corporationId` nullable : l'ESI garantit une corporation, une réponse sans elle est invalide. La connexion en profite (erreur explicite au lieu d'un `TypeError`).
- **D5 — Une seule écriture de l'affiliation.** Nouvelle méthode d'entité `Character::updateAffiliation(CharacterInfoDto $info)` (corporation et alliance, identifiants et noms) ; `AuthenticationService::updateCharacterInfo()` l'appelle après `setName()` au lieu de ses quatre setters. Entity → Dto est autorisé par `deptrac.yaml`.
- **D6 — Termes du glossaire proposés, non écrits** dans `docs/glossary.md` avant validation de Sylvain (voir dernière section).

## Fichiers de production à modifier

| Fichier | Changement |
|---|---|
| `src/Service/Sync/IndustryJobSyncService.php` | Nouvelle dépendance `CharacterService` (constructeur) ; dans le `try`, avant les jobs perso : `$character->updateAffiliation($this->characterService->getCharacterInfo($character->getEveCharacterId()))` ; la lecture corpo utilise ensuite `$character->getCorporationId()` (inchangé) |
| `src/Entity/Character.php` | `updateAffiliation(CharacterInfoDto $info): static` (R2, D5) |
| `src/Service/ESI/AuthenticationService.php` | `updateCharacterInfo()` : `setName()` + `updateAffiliation()` (D5) ; comportement de connexion inchangé |
| `src/Service/ESI/CharacterService.php` | `corporation_id` absent ou non entier → `\UnexpectedValueException` nommant l'endpoint (R4) |

Aucune migration, aucun message Messenger, aucune route API modifiés.

## Câblage des tests (tests unitaires)

- `tests/Unit/Service/Sync/IndustryJobSyncServiceTest.php` : **casse** au `setUp()` (`new IndustryJobSyncService(...)`, l.162, argument manquant). Le harnais ajoute une doublure `CharacterService` dont `getCharacterInfo(int)` rend un `CharacterInfoDto` avec la corporation du personnage (par défaut celle passée à `createCharacterWithUser()`, `$corporationId`) : un stub sans retour configuré échoue, `CharacterInfoDto` étant `final`. Les personnages de ce fichier sont des stubs (`createStub(Character::class)`) : `updateAffiliation()` n'y a pas d'effet et `getCorporationId()` reste figé, donc E1, E6, E7, E9, E10, E11, E13 exigent un vrai `Character` (avec `User`, `EveToken` stub, `setCorporationId(98000001)`). Les attentes `esiClient->expects($this->never())->method('get')` (l.331, 1177, 1192) ne cassent pas : la relecture passe par `CharacterService`.
- `tests/Unit/MessageHandler/SyncCharacterIndustryJobsHandlerTest.php` : **casse** l.119 (`new IndustryJobSyncService(...)`, test #213) ; même doublure, corporation `self::CORPORATION_ID`.
- `tests/Unit/Command/MatchProjectJobsCommandTest.php`, les autres tests de `SyncCharacterIndustryJobsHandlerTest.php` (stubs/mocks de `IndustryJobSyncService`) et `tests/Integration/MessageHandler/ScheduledCharacterSyncFanOutTest.php` (fan-out, ne lance pas la sync) : pas d'impact attendu.
- `tests/Unit/Service/ESI/AuthenticationServiceTest.php` : doublure de `CharacterService` existante (l.94-102) ; D5 ne change pas le résultat, rien ne doit casser.
- Nouveau `tests/Unit/Service/ESI/CharacterServiceTest.php` pour E5 (`EsiClient` mocké, `getWithCache('/characters/12345/')` sans `corporation_id`).

## Glossaire (proposé, à valider)

- Ajout : **Affiliation** (Affiliation) — corporation et alliance actuelles d'un personnage selon l'ESI (`GET /characters/{id}/`), relues à la connexion EVE SSO et au début de chaque sync des jobs du personnage (spec #211). Un échec de lecture fait échouer la sync ; l'affiliation enregistrée n'est jamais présumée à jour. Code : `Character::updateAffiliation()`, `CharacterService::getCharacterInfo()`, `CharacterInfoDto`.
- Modification de **Job corpo** : « la corporation lue est celle de l'affiliation relue au début de la sync (spec #211) ».
