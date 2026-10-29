# Spec #197 — Syncs à la demande (matching des jobs, compétences) : chaque personnage en échec est nommé

**Statut : validée en autonomie en mode nuit le 2026-10-29, à relire par Sylvain.**

Circuit : `circuit:m` (pas de note de câblage). Tests unitaires seulement, pas de base : `IndustryJobSyncService`, `IndustryJobMatcher`, `EsiClient`, `TokenManager`, dépôts et `EntityManagerInterface` doublés ; personnages, utilisateur et projet en vraies entités. Tests de composants front (Vitest, `en.json`/`fr.json`).

## Contexte

Deux opérations lancent une sync ESI à la demande, personnage par personnage, et rendent un compteur et un `warning` texte.

**« Matcher les jobs »** — `POST /api/industry/projects/{id}/match-jobs` (`ProjectResource.php:67-72`, sortie `MatchJobsResultResource`, processor `MatchJobsProcessor`).

- Boucle sur les personnages de l'utilisateur (`MatchJobsProcessor.php:54-74`), `syncCharacterJobs()` puis `$syncedCount++` (`:56-57`).
- Une `EsiApiException` 502/503/504 pose `warning` à un texte français en dur (`:59-60`, « ESI est actuellement en maintenance… »), **sans log** ; toute autre `EsiApiException` (401, 403, 404, 420, 429, 500, réseau = statut 0) et tout autre `\Throwable` (`EveAuthRequiredException`, `\UnexpectedValueException` de spec #211 R4) sont seulement journalisés (`:61-72`). La réponse est un 200 sans avertissement.
- Un personnage sans token : `syncCharacterJobs()` sort sans rien faire (`IndustryJobSyncService.php:49-52`) et le personnage est compté dans `syncedCharacters` (`MatchJobsProcessor.php:57`).
- `matchEsiJobs($project)` est lancé quoi qu'il arrive (`:76`), sur les jobs en cache de tous les personnages (`IndustryJobMatcher.php:44-79`).
- `MatchJobsResultResource` : `steps`, `jobsCost`, `int $syncedCharacters`, `?string $warning` (`MatchJobsResultResource.php:7-17`).

**« Synchroniser les compétences »** — `POST /api/industry/character-skills/sync` (`CharacterSkillResource.php:29-34`, sortie `CharacterSkillCollectionResource`, processor `SyncCharacterSkillsProcessor`).

- Même boucle (`SyncCharacterSkillsProcessor.php:54-76`), même `warning` en dur sur 502/503/504 (`:59-60`, « … Les skills seront synchronisés ultérieurement. »), mêmes autres erreurs seulement journalisées (`:61-72`).
- `syncCharacterSkills()` sort sans rien dire si le personnage n'a pas de token (`:87-90`) ou si le token n'a pas le scope `esi-skills.read_skills.v1` (`:92-94`) ; le personnage est compté dans `syncedCount` (`:57`).
- Chaque personnage reste dans `characters` avec ses compétences en cache (`:75-76`).
- `CharacterSkillCollectionResource` : `characters`, `int $syncedCount`, `?string $warning` (`CharacterSkillCollectionResource.php:7-15`).

**Front.**

- `matchJobs()` (`frontend/src/stores/industry/projects.ts:127-144`) type `syncedCharacters` et `warning`, ne rend que `data.warning` ; `StepsTab.vue` le range dans `matchJobsWarning` (`:29`, `:50-63`) et l'affiche brut dans un bandeau ambre refermable (`:142-160`).
- `syncCharacterSkills()` (`frontend/src/stores/industry/structures.ts:154-166`) rend `data.warning || null` ; `SkillsConfig.vue` le range dans `syncWarning` (`:12`, `:18-29`) et l'affiche brut (`:96-99`).
- `syncedCharacters` et `syncedCount` ne sont affichés nulle part.
- Le texte français en dur s'affiche tel quel en anglais.

**Modèle existant.** Spec #243 (`ScanLootSalesResultResource::$charactersToReconnect`, `$corporationsWithoutValidCharacter`) : champs structurés, textes dans `fr.json`/`en.json`, lien vers `/characters`. Les codes de raison traduits par le front existent aussi (`list<array{typeId, reason}>`, `useUnknownInstallCostReasons.ts`).

## Règles métier

- **R1 — Personnage non synchronisé.** Un personnage de l'utilisateur est **non synchronisé** par l'opération quand :
  - il n'a pas de token (`missing_token`) ;
  - pour les compétences seulement, son token n'a pas le scope `esi-skills.read_skills.v1` (`missing_scope`) ;
  - ou sa sync lève une exception.
  Il n'est jamais compté dans `syncedCharacters` / `syncedCount`. Ces compteurs ne comptent que les personnages dont la sync s'est terminée sans exception.
- **R2 — Raison de l'échec.** Chaque personnage non synchronisé porte une raison, prise dans cet ordre :

  | Raison | Cas |
  |---|---|
  | `missing_token` | `getEveToken() === null` ; aucune sync n'est lancée pour lui |
  | `missing_scope` | compétences : token sans `esi-skills.read_skills.v1` ; aucun appel `TokenManager` ni ESI |
  | `authentication_required` | `EveAuthRequiredException` (autorisation révoquée, spec #86) |
  | `esi_rate_limited` | `EsiApiException` 420 ou 429 (après la relance d'`EsiClient`) |
  | `esi_unavailable` | `EsiApiException` de statut ≥ 500, ou 0 (erreur réseau, `EsiClient.php:53` dans `get()`) |
  | `esi_error` | toute autre `EsiApiException` (401, 403, 404…) |
  | `sync_failed` | tout autre `\Throwable` (dont `\UnexpectedValueException` de spec #211 R4) |

- **R3 — Échecs dans la réponse.** Les deux réponses portent `failedCharacters`, une entrée par personnage non synchronisé, dans l'ordre des personnages de l'utilisateur : `characterName` (`Character::getName()`), `reason` (R2), `statusCode` (`EsiApiException::$statusCode` pour les trois raisons `esi_*`, `null` sinon). Liste vide quand tout a réussi.
- **R4 — Plus de texte en dur.** Le champ `warning` disparaît des deux réponses ; aucun texte destiné à l'utilisateur n'est produit par l'API. Le front traduit les raisons.
- **R5 — Matching lancé quand même.** « Matcher les jobs » lance `matchEsiJobs()` une fois, après la boucle, même si un ou tous les personnages sont non synchronisés : les jobs en cache d'un personnage non synchronisé sont ceux de sa dernière sync réussie. La réponse les signale par R3 (voir D3 et l'écart avec spec #208).
- **R6 — Statut HTTP.** 200 dans tous les cas couverts par R1, même si tous les personnages échouent : l'opération rend les étapes et les échecs. Utilisateur absent (401) et projet d'un autre (404) : inchangés.
- **R7 — Logs.** Chaque exception d'un personnage est journalisée en `warning` (`characterName`, `error`, plus `statusCode` pour une `EsiApiException`), **y compris** les 5xx, qui ne l'étaient pas. `missing_token` et `missing_scope` ne sont pas journalisés : c'est un état du personnage, affiché par R3.
- **R8 — Écran.**
  - `StepsTab` : `failedCharacters` non vide → bandeau ambre refermable (`data-testid="jobs-sync-failures"`). Titre `industry.syncFailures.jobsTitle` (pluriel par nombre). Une ligne par personnage : nom et raison traduite ; pour `esi_error`, la raison affiche `statusCode`.
  - `SkillsConfig` : même bandeau (`data-testid="skills-sync-failures"`), titre `industry.syncFailures.skillsTitle`.
  - Lien « Reconnecter des personnages » vers `/characters` quand au moins une raison est `missing_token`, `missing_scope` ou `authentication_required`.
  - Liste vide → aucun bandeau.
  - Le bandeau ne change rien au reste de l'écran : les étapes ou les compétences rendues sont affichées.

## Contrat des réponses

`MatchJobsResultResource` (`POST /api/industry/projects/{id}/match-jobs`) :

| Champ | Avant | Après |
|---|---|---|
| `steps`, `jobsCost` | — | inchangés |
| `syncedCharacters` | `int`, compte aussi les personnages sans token | `int`, personnages synchronisés sans exception (R1) |
| `warning` | `?string`, français en dur, seulement 502/503/504 | **supprimé** (R4) |
| `failedCharacters` | absent | `list<array{characterName: string, reason: string, statusCode: int\|null}>` (R3) |

`CharacterSkillCollectionResource` (`POST /api/industry/character-skills/sync`) :

| Champ | Avant | Après |
|---|---|---|
| `characters` | — | inchangé : tous les personnages, compétences en cache |
| `syncedCount` | `int`, compte aussi sans token / sans scope | `int`, personnages synchronisés (R1) |
| `warning` | `?string` | **supprimé** (R4) |
| `failedCharacters` | absent | même forme que ci-dessus |

`GET /api/industry/character-skills` rend aussi `CharacterSkillCollectionResource` (`CharacterSkillCollectionProvider`) : il porte désormais `failedCharacters: []` et plus de `warning` ; le front n'y lit que `characters`.

**Compatibilité front.** Le front est livré dans le même lot. Un front ancien encore en cache (service worker) lit `warning` → `undefined` : aucun bandeau, aucune erreur. Pas de consommateur externe connu de ces routes.

Type front (`frontend/src/stores/industry/types.ts`) :

```ts
export type CharacterSyncFailureReason = 'missing_token' | 'missing_scope' | 'authentication_required' | 'esi_rate_limited' | 'esi_unavailable' | 'esi_error' | 'sync_failed'
export interface CharacterSyncFailure { characterName: string, reason: CharacterSyncFailureReason, statusCode: number | null }
```

## Exemples chiffrés

Données fictives. Utilisateur U1, personnages dans cet ordre : P1 « Pilot One » (2112000001), P2 « Pilot Two » (2112000002), P3 « Pilot Three » (2112000003). Projet J1 de U1, sans étape.

« Matcher les jobs » (`syncCharacterJobs` doublé ; P1, P2, P3 ont un token sauf mention) :

| # | Situation | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | P1 OK, P2 `EsiApiException` 403, P3 OK | 200, `syncedCharacters` 2, `warning` null | `syncedCharacters` 2 ; `failedCharacters` = `[{Pilot Two, esi_error, 403}]` ; `matchEsiJobs(J1)` une fois |
| E2 | P2 `EsiApiException` 503 | `syncedCharacters` 2, `warning` « ESI est actuellement en maintenance… », pas de log | `syncedCharacters` 2 ; `[{Pilot Two, esi_unavailable, 503}]` ; un log `warning` ; pas de champ `warning` |
| E3 | P2 `EsiApiException` 420 | `warning` null | `[{Pilot Two, esi_rate_limited, 420}]` |
| E4 | P2 `EsiApiException` 429 | `warning` null | `[{Pilot Two, esi_rate_limited, 429}]` |
| E5 | P2 `EsiApiException` 500 | `warning` null, log | `[{Pilot Two, esi_unavailable, 500}]` |
| E6 | P2 `EsiApiException` statut 0 (réseau) | `warning` null | `[{Pilot Two, esi_unavailable, 0}]` |
| E7 | P2 `EveAuthRequiredException('2112000002')` | `warning` null | `[{Pilot Two, authentication_required, null}]` |
| E8 | P2 `\UnexpectedValueException` (spec #211 R4) | `warning` null | `[{Pilot Two, sync_failed, null}]` |
| E9 | P2 sans token | `syncedCharacters` 3 | `syncedCharacters` 2 ; `[{Pilot Two, missing_token, null}]` ; `syncCharacterJobs` appelé pour P1 et P3 seulement |
| E10 | P1 503, P2 401, P3 sans token | `syncedCharacters` 1, `warning` maintenance | `syncedCharacters` 0 ; `[{Pilot One, esi_unavailable, 503}, {Pilot Two, esi_error, 401}, {Pilot Three, missing_token, null}]` ; `matchEsiJobs(J1)` une fois ; HTTP 200 |
| E11 | P1, P2, P3 OK | `syncedCharacters` 3 | `syncedCharacters` 3 ; `failedCharacters` `[]` |

« Synchroniser les compétences » (`TokenManager`, `EsiClient`, dépôt doublés ; SDE sans compétence, liste `CachedCharacterSkill::INDUSTRY_SKILL_IDS` + `SLOT_SKILL_IDS`) :

| # | Situation | Aujourd'hui | Attendu |
|---|---|---|---|
| S1 | P1 scope OK, ESI OK ; P2 token sans `esi-skills.read_skills.v1` | `syncedCount` 2 | `syncedCount` 1 ; `[{Pilot Two, missing_scope, null}]` ; aucun `getValidAccessToken` ni `get` avec le token de P2 ; `characters` a 2 entrées |
| S2 | P1 OK ; P2 sans token | `syncedCount` 2 | `syncedCount` 1 ; `[{Pilot Two, missing_token, null}]` ; `characters` a 2 entrées |
| S3 | P2 : `getValidAccessToken` lève `EveAuthRequiredException` | `syncedCount` 1, `warning` null | `[{Pilot Two, authentication_required, null}]` |
| S4 | P2 : `get('/characters/2112000002/skills/')` lève 403 | `warning` null | `[{Pilot Two, esi_error, 403}]` |
| S5 | P2 : 502 | `warning` « … Les skills seront synchronisés ultérieurement. » | `[{Pilot Two, esi_unavailable, 502}]` |
| S6 | P1, P2 OK | `syncedCount` 2 | `syncedCount` 2 ; `[]` |

Écran (`en.json` / `fr.json`) :

| # | Données | Attendu |
|---|---|---|
| F1 | `StepsTab`, `[{Pilot Two, esi_error, 403}]` | « Jobs not synced for 1 character, matching used the last known jobs: » ; ligne « Pilot Two: ESI error 403 » ; pas de lien |
| F2 | `StepsTab`, `[{Pilot One, esi_unavailable, 503}, {Pilot Two, authentication_required, null}]` | « Jobs not synced for 2 characters, … » ; « Pilot One: ESI unavailable (maintenance or server error), retry later » ; « Pilot Two: authorization revoked or expired, reconnect the character » ; lien « Reconnect characters » vers `/characters` |
| F3 | `StepsTab`, `[]` | aucun `jobs-sync-failures` |
| F4 | `SkillsConfig`, `[{Pilot Two, missing_scope, null}]` | « Skills not synced for 1 character: » ; « Pilot Two: the token lacks a required ESI permission, reconnect the character » ; lien |
| F5 | `SkillsConfig`, `[{Pilot Three, esi_rate_limited, 420}]`, FR | « Compétences non synchronisées pour 1 personnage : » ; « Pilot Three : limite de requêtes ESI atteinte, réessayez plus tard » ; pas de lien |
| F6 | `StepsTab`, bandeau affiché puis bouton fermer | bandeau retiré ; le bouton a un nom accessible (`common.actions.close`) |

## Clés i18n

Nouveau bloc `industry.syncFailures` dans `fr.json` et `en.json` :

| Clé | FR | EN |
|---|---|---|
| `jobsTitle` | `Jobs non synchronisés pour {count} personnage, le matching a utilisé les derniers jobs connus : \| Jobs non synchronisés pour {count} personnages, le matching a utilisé les derniers jobs connus :` | `Jobs not synced for {count} character, matching used the last known jobs: \| Jobs not synced for {count} characters, matching used the last known jobs:` |
| `skillsTitle` | `Compétences non synchronisées pour {count} personnage : \| Compétences non synchronisées pour {count} personnages :` | `Skills not synced for {count} character: \| Skills not synced for {count} characters:` |
| `line` | `{name} : {reason}` | `{name}: {reason}` |
| `reasons.missing_token` | `aucun token ESI, reconnectez le personnage` | `no ESI token, reconnect the character` |
| `reasons.missing_scope` | `il manque une autorisation ESI au token, reconnectez le personnage` | `the token lacks a required ESI permission, reconnect the character` |
| `reasons.authentication_required` | `autorisation révoquée ou expirée, reconnectez le personnage` | `authorization revoked or expired, reconnect the character` |
| `reasons.esi_rate_limited` | `limite de requêtes ESI atteinte, réessayez plus tard` | `ESI request limit reached, retry later` |
| `reasons.esi_unavailable` | `ESI indisponible (maintenance ou erreur serveur), réessayez plus tard` | `ESI unavailable (maintenance or server error), retry later` |
| `reasons.esi_error` | `erreur ESI {status}` | `ESI error {status}` |
| `reasons.sync_failed` | `échec de la synchronisation` | `sync failed` |
| `reconnectLink` | `Reconnecter des personnages` | `Reconnect characters` |

`pve.reconnectCharactersLink` (spec #243) n'est pas réutilisée : elle appartient au namespace `pve`, même choix que #243 pour `dashboard.todo.*`.

## Cas limites

- **Tous les personnages en échec** : 200, compteur 0, matching lancé sur le cache (R5, E10). L'écran liste chaque personnage.
- **Utilisateur sans personnage** : boucle vide, `failedCharacters` `[]`, compteur 0, `matchEsiJobs` sort tôt (`IndustryJobMatcher.php:52-54`). Inchangé.
- **Sync partielle sans exception** (jobs) : 403 sur les jobs corpo (spec #122 R1), token sans le scope des jobs perso (spec #207) ou sans le scope corpo. `syncCharacterJobs()` ne lève pas, ne rend rien : le personnage est compté synchronisé et n'est pas listé. Hors périmètre, voir l'écart.
- **Personnage en autorisation révoquée** (jobs) : la sync appelle d'abord `getCharacterInfo()` (endpoint public, spec #211 R1), puis l'appel avec token lève `EveAuthRequiredException` → `authentication_required`. Pas de filtre préalable sur `isAuthValid()` dans le processor (spec #207 R4 : la règle vit dans le service).
- **`syncError` Mercure** : publié par `IndustryJobSyncService` pour chaque personnage en échec (`IndustryJobSyncService.php:240-244`), inchangé ; le bandeau ne dépend pas de Mercure.
- **Requête HTTP en échec** (5xx de l'API, réseau) : les stores gardent leur comportement (`error.value`, `matchJobs` rend `[]` au lieu de `null`, `syncCharacterSkills` relance) ; ce n'est pas un échec de personnage.
- **Même nom pour deux personnages** : impossible dans EVE, le nom sert de libellé.

## Écarts avec les specs #122, #208, #211, #207, #243

- **#122 R2 et #211 R3** écrivent que « `MatchJobsProcessor` et `MatchProjectJobsCommand` gèrent déjà l'exception par personnage ». C'est vrai pour l'absence de plantage, faux pour l'utilisateur : hors 502/503/504, l'exception était tue. #197 rend ces exceptions visibles (`esi_*`, `sync_failed`) sans changer les règles de #122 et #211.
- **#122 R1 et #207** : les syncs partielles (jobs corpo ou perso inconnus) ne lèvent pas d'exception, `syncCharacterJobs()` ne rend rien. Elles restent comptées comme réussies par « Matcher les jobs ». Ce trou reste ouvert ; le combler demande que le service rende un compte rendu de sync. À ouvrir en issue (voir Hors périmètre).
- **#208** : la sync recopie l'état des jobs matchés à chaque sync réussie, quel que soit le mode de l'étape (R1). Un match créé par R5 sur un job en cache périmé est donc corrigé à la prochaine sync réussie du personnage (planifiée toutes les 30 min ou à la demande). Les jobs matchés d'un personnage en échec ne sont pas rafraîchis par cet appel, et le bandeau le dit.
- **#207 décision 2** (pas de filtre par scope dans `MatchJobsProcessor`) : respectée. Le seul filtre ajouté est l'absence de token, pour lequel le service ne faisait déjà rien.
- **#243** : même principe (champs structurés, textes traduits, lien `/characters`). Mais une liste d'objets `{characterName, reason, statusCode}` remplace les listes de noms, parce que la raison varie par personnage (voir D2).

## Hors périmètre

- Compte rendu de sync partielle des jobs (jobs perso inconnus faute de scope, jobs corpo inconnus après 403) dans « Matcher les jobs » : suite à ouvrir en issue.
- `MatchProjectJobsCommand` : affiche déjà chaque échec en console (`MatchProjectJobsCommand.php:59-61`), mais compte encore un personnage sans token (`:56-57`). Pas d'écran, laissé tel quel.
- Syncs planifiées (Messenger) : leurs échecs passent par `syncError` et les retries.
- Autres syncs à la demande hors industrie (assets, ansiblex, PVE, minage).
- `GET /api/industry/character-skills` : aucun échec à y rapporter (lecture du cache seulement).

## Décisions retenues en autonomie

- **D1 — Suppression de `warning`** plutôt que de le garder à côté du nouveau champ : un seul canal, aucun texte en dur côté API (R4). Le front change dans le même lot ; un front en cache dégrade sans erreur.
- **D2 — `failedCharacters` = liste d'objets `{characterName, reason, statusCode}`**, et non des listes de noms par raison comme #243. Sept raisons donneraient sept champs ; le front groupe s'il le veut. `statusCode` reste pour nommer l'échec d'un 403 ou d'un 401 (critère de l'issue : « un avertissement nommant l'échec »).
- **D3 — Matching lancé quand même (R5)**. Le bloquer priverait les autres personnages de leurs jobs frais, et « Matcher les jobs » est le seul moyen de recréer les matchs `auto`. Un match fait sur le cache périmé n'est pas destructif : spec #208 le corrige à la prochaine sync réussie. L'utilisateur en est averti (titre `jobsTitle`).
- **D4 — HTTP 200 même si tout échoue (R6)**, comme #243 : la réponse contient des données utiles (étapes, compétences en cache) et les échecs structurés. Un 502 masquerait les étapes.
- **D5 — Classification partagée.** Une classe `App\Service\Sync\CharacterSyncFailure` (readonly) porte `characterName`, la raison (enum `App\Enum\CharacterSyncFailureReason`, backed string) et `statusCode`. Elle a trois fabriques, `missingToken(Character)`, `missingScope(Character)`, `fromThrowable(Character, \Throwable)` (R2, seuils en constantes), et `toArray()` pour la ressource. Elle vit dans `Service` : deptrac interdit `Enum → Exception`, et `ApiResource → Service`, d'où le tableau dans les ressources.
- **D6 — 5xx et réseau regroupés en `esi_unavailable`, 420 et 429 en `esi_rate_limited`.** Même conseil à l'utilisateur (réessayer plus tard). Le statut exact reste dans `statusCode`.
- **D7 — Un composant front partagé `CharacterSyncFailuresAlert.vue`.** Il affiche titre, lignes, lien et bouton fermer ; `StepsTab` et `SkillsConfig` lui passent le titre et la liste. `SkillsConfig` gagne le bouton fermer. Pas de maquette : on reprend le bandeau ambre de `StepsTab.vue:142-160`.
- **D8 — Logs étendus aux 5xx (R7)** ; pas de log pour `missing_token` / `missing_scope`.
- **D9 — `syncedCharacters` / `syncedCount` gardés** (corrigés par R1) bien que non affichés : ils font partie du contrat et coûtent une ligne.

## Fichiers de production à modifier

| Fichier | Changement |
|---|---|
| `src/Enum/CharacterSyncFailureReason.php` (nouveau) | sept cas backed string (R2) |
| `src/Service/Sync/CharacterSyncFailure.php` (nouveau) | fabriques et classification (R2, D5), `toArray()` |
| `src/ApiResource/Industry/MatchJobsResultResource.php` | retrait de `warning` ; `/** @var list<array{characterName: string, reason: string, statusCode: int\|null}> */ public array $failedCharacters = []` |
| `src/ApiResource/Industry/CharacterSkillCollectionResource.php` | idem |
| `src/State/Processor/Industry/MatchJobsProcessor.php` | personnage sans token → `missingToken`, pas d'appel au service ; `catch (\Throwable)` unique → log `warning` (R7) + `fromThrowable` ; compteur sur succès seulement ; `matchEsiJobs` inchangé (R5) |
| `src/State/Processor/Industry/SyncCharacterSkillsProcessor.php` | contrôles token et scope sortis de `syncCharacterSkills()` vers la boucle (`missingToken` / `missingScope`) ; même `catch` que ci-dessus ; `characters` inchangé |
| `frontend/src/stores/industry/types.ts` | `CharacterSyncFailureReason`, `CharacterSyncFailure` |
| `frontend/src/stores/industry/projects.ts` | `matchJobs()` : type de réponse (`failedCharacters`, sans `warning`), rend `CharacterSyncFailure[]` (`[]` dans le `catch`) |
| `frontend/src/stores/industry/structures.ts` | `syncCharacterSkills()` : rend `data.failedCharacters` |
| `frontend/src/components/industry/CharacterSyncFailuresAlert.vue` (nouveau) | bandeau (R8, D7), props `title`, `failures`, émet `dismiss` |
| `frontend/src/components/industry/StepsTab.vue` | `matchJobsWarning` → `matchJobsFailures: CharacterSyncFailure[]` (aussi dans `defineExpose`) ; bandeau remplacé par le composant |
| `frontend/src/components/industry/SkillsConfig.vue` | `syncWarning` → `syncFailures` ; bandeau remplacé par le composant |
| `frontend/src/i18n/locales/fr.json`, `en.json` | bloc `industry.syncFailures` |

Pas de migration, pas de message Messenger, pas de commande à lancer en production.

## Câblage des tests (tests unitaires)

**Tests existants appelés à casser** : aucun attendu.

- Aucun test unitaire de `MatchJobsProcessor` ni de `SyncCharacterSkillsProcessor`. `tests/Unit/Command/MatchProjectJobsCommandTest.php` ne touche pas au processor.
- Front : aucun test ne simule `matchJobs` ni `syncCharacterSkills` (`grep -rn "matchJobs\|syncCharacterSkills" frontend/tests` vide). `tests/views/Industry.spec.ts:44` remplace `SkillsConfig` par un stub, et `tests/a11y/iconButtonsHaveName.spec.ts:362` en fait autant pour `StepsTab`.
- Si `matchJobsWarning` est lu ailleurs que dans `StepsTab.vue` (`defineExpose`), la phase verte le renomme partout (`grep -rn matchJobsWarning frontend` ne trouve aujourd'hui que `StepsTab.vue`).

**Tests rouges attendus** (`#[Group('issue-197')]`) :

- `tests/Unit/State/Processor/Industry/MatchJobsProcessorTest.php` (nouveau) : E1 à E11.
  - Harnais : `Security` stub rendant un vrai `User` avec trois vrais `Character` (`setName`, `setEveToken` avec un `EveToken` stub, ou `null` pour E9) ; `IndustryProjectRepository` stub rendant un vrai `IndustryProject` (`setUser(U1)`, sans étape, donc `IndustryResourceMapper` stub jamais appelé).
  - `IndustryJobSyncService` en mock (`willReturnCallback` qui lève selon le personnage) ; `IndustryJobMatcher` en mock (`expects(once())->method('matchEsiJobs')`) ; `LoggerInterface` en mock pour R7 (E2).
  - `uriVariables['id']` = un UUID fixe.
- `tests/Unit/State/Processor/Industry/SyncCharacterSkillsProcessorTest.php` (nouveau) : S1 à S6.
  - Harnais : `TokenManager` et `EsiClient` en mock (classes non finales). `EsiClient::get` rend `['skills' => []]` ou lève, selon l'endpoint ; `expects(never())` pour le personnage sans scope ou sans token.
  - `CachedCharacterSkillRepository` stub (`findBy` → `[]`) ; `EntityManagerInterface` stub dont `getConnection()` rend un `Connection` stub (`fetchAllAssociative` → `[]`), comme `tests/Unit/Service/SimulatesJitaMarketEsi.php:85`.
  - `IndustryResourceMapper` réel, construit avec des stubs de `IndustryCalculationService`, `CachedIndustryJobRepository`, `InventionService` (`characterSkillsToResource()` ne les utilise pas).
  - `EveToken` stub : `hasScope()` selon le cas.
- Optionnel, si les mutants de la classification survivent : `tests/Unit/Service/Sync/CharacterSyncFailureTest.php` (bornes 420/429/499/500/0, `EveAuthRequiredException`, `\RuntimeException`).
- Front, `frontend/tests/components/industry/characterSyncFailuresAlert.spec.ts` : F1 à F6.
  - Montage de `StepsTab` comme `stepsTabFailedProducts.spec.ts` (Pinia réel, `useProjectsStore` dont `matchJobs` est remplacé par un `vi.fn` rendant la liste, clic sur « Sync jobs »).
  - Montage de `SkillsConfig` avec `useStructuresStore().syncCharacterSkills` remplacé, clic sur « Sync all ».
  - `en.json` pour F1 à F4 et F6, `fr.json` pour F5.

## Glossaire (proposé, à valider)

- Ajout : **Personnage non synchronisé** (Unsynced character). Personnage de l'utilisateur dont une sync à la demande (« Matcher les jobs », « Synchroniser les compétences ») n'a pas abouti, avec une raison : sans token, sans scope, autorisation révoquée, limite ESI, ESI indisponible, erreur ESI, échec. Il n'est pas compté comme synchronisé, la réponse le nomme et l'écran l'affiche (spec #197). Code : `CharacterSyncFailure`, `CharacterSyncFailureReason`, `failedCharacters`.
