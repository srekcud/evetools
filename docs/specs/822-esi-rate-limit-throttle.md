# Spec #822 : ralentir sur les headers `X-Ratelimit-*` de l'ESI avant la 429

Circuit m, mode nuit (questions tranchées sur la recommandation, voir « Décisions »).

## Sources vérifiées (2026-11-12)

- Doc CCP, « Rate limiting » : https://developers.eveonline.com/docs/services/esi/rate-limiting/
- Les routes sont rangées en **groupes** ; chaque groupe a un bucket par clé : routes authentifiées `<applicationID>:<characterID>` ; routes publiques `<IP source>` (ou `<IP>:<applicationID>` si un token est envoyé).
- Fenêtre **flottante** : les tokens consommés par une requête reviennent au bucket une fois la durée de la fenêtre écoulée (`X-Ratelimit-Limit: 150/15m` = 150 tokens par fenêtre de 15 minutes, unités `m` et `h`).
- Coût par statut : 2xx = 2 tokens, 3xx = 1, 4xx = 5 (sauf 429), 5xx = 0.
- Headers : `X-Ratelimit-Group`, `X-Ratelimit-Limit`, `X-Ratelimit-Remaining`, `X-Ratelimit-Used` (coût de la requête). `Retry-After` (secondes) n'est envoyé que sur une 429 : délai avant d'avoir assez de tokens.
- Tous les groupes ne sont pas encore limités ; certaines routes répondent 429 sans ces headers (autre limiteur côté serveur EVE).
- Observation prod de Sylvain (curl sans token, `GET /latest/status/`, 200) : `group=status`, `limit=600/15m`, `remaining=598`, `used=2`, et **aucun** `x-esi-error-limit-*` sur cette 2xx. Elle recoupe la doc : 2 tokens par 2xx, limite propre à chaque groupe, budget d'erreurs parfois absent.

## Contexte

`EsiClient` ne lit que les headers d'erreurs (`x-esi-error-limit-*`) et ne réagit qu'après une 429 (sleep `Retry-After`, un seul rejeu, puis `EsiApiException(429)`). Les syncs en lot (assets, assets corpo, marché) peuvent vider un groupe. Terme du glossaire : **budget ESI** (nouveau, voir fin).

## Règles

1. **Classe** `App\Service\ESI\EsiRateLimitBudget`, pool Redis `esi_error_budget.cache` (partagé entre workers, clés préfixées `esi_rl_`), constructeur `(CacheItemPoolInterface $cache, LoggerInterface $logger = NullLogger, ClockInterface $clock = NativeClock)`. `EsiClient` la reçoit avec un défaut `new EsiRateLimitBudget(new ArrayAdapter())` (même style que `EsiErrorBudget`), `AdminStatsProvider` aussi.
2. **Bucket** : clé = groupe + portée. Portée = UUID du personnage du token pour une route authentifiée, `public` sans token. Un token sans personnage n'est ni lu ni enregistré (jamais de portée par défaut).
3. **Route → groupe** : la famille de la route (`EsiErrorBudget::familyOf()`, rendue publique, ex. `/characters/{id}/assets/`) est associée au groupe lu sur une réponse portant `X-Ratelimit-Group` (TTL 7 jours). Tant qu'une famille n'a pas de groupe connu, aucun ralentissement (première requête, ou groupe non limité).
4. **Lecture** : sur chaque réponse (toutes les voies qui appellent déjà `processRateLimitHeaders()`, 429 et rejeux compris) avec `X-Ratelimit-Group`, `Limit` (`<n>/<k><m|h>`) et `Remaining` valides : le bucket est enregistré `{limit, windowSeconds, remaining, observedAt}`, TTL = durée de la fenêtre. Un header manquant ou illisible : rien n'est enregistré ni supposé (pas de valeur par défaut) ; le comportement actuel sur `x-esi-error-limit-*` ne change pas (il reste facultatif sur une 2xx).
5. **429** : le bucket du groupe est mis à `remaining = 0` avec `retryUntil = observedAt + Retry-After` (valeur brute, non plafonnée). Le rejeu de `EsiClient` garde son sleep actuel (`Retry-After` plafonné à 60 s) : comportement de la 429 inchangé. Une 429 sans `X-Ratelimit-Group` ne touche à aucun bucket.
6. **Restant estimé** à l'instant t = `min(limit, remaining + (t − observedAt) × limit / windowSeconds)` : hypothèse (la doc ne donne pas le moment du retour des tokens) que les tokens reviennent au rythme moyen de la fenêtre ; toute nouvelle réponse écrase l'estimation par la valeur réelle. Tant que `t < retryUntil`, le restant est 0.
7. **Seuils** (constantes nommées de `EsiRateLimitBudget`) : `LOW_BELOW_RATIO = 0.20`, `CRITICAL_BELOW_RATIO = 0.05`, `SLOW_DOWN_MAX_DELAY_MS = 1000`, `MAX_PAUSE_SECONDS = 60`, `TOKENS_PER_SUCCESS = 2`. Niveau : `critical` si restant estimé < 5 % de `limit`, `low` si < 20 %, sinon `ok`.
8. **Avant une requête** (là où `throttleIfNeeded()` s'applique déjà, famille au groupe connu) : `low` → `clock->sleep` de `SLOW_DOWN_MAX_DELAY_MS × (1 − restant / seuilLow)` ms ; `critical` ou `retryUntil` dans le futur → pause jusqu'au retour estimé au seuil `low` (ou `retryUntil`), plafonnée à `MAX_PAUSE_SECONDS`. Le contrôle d'erreurs 420 existant (`throttleIfNeeded`) est inchangé et s'exécute d'abord.
9. **Avant un lot** (`getBatch()`, `getScalarBatch()`) : requêtes regroupées par (groupe, portée) des familles connues ; la dépense prévue d'un bucket est `nombre de requêtes × TOKENS_PER_SUCCESS`. Si `restant estimé − dépense prévue` tombe sous le seuil `critical`, pause jusqu'à ce qu'il revienne au seuil `critical` (plafonnée à `MAX_PAUSE_SECONDS`) ; au seuil `low`, un ralentissement unique (règle 8) avant le lot. Le lot est lancé ensuite en entier : le plafond évite un worker bloqué, la 429 reste rejouée comme aujourd'hui. Le rejeu d'une 420/429 (`throttle: false`) ne repasse pas par le contrôle.
10. **Log** : un `warning` à chaque pause, un `info` à chaque ralentissement, avec groupe, restant estimé et durée. Aucune portée (identifiant de personnage) dans les logs ni dans l'admin.
11. **Admin** : `EsiRateLimitBudget::snapshot()` renvoie par groupe le **plus bas** restant observé dans la fenêtre courante : `{group, remaining, limit, windowSeconds, observedAt, level}`, triés par ratio restant/limite croissant. Le plus bas d'un groupe est remplacé quand une nouvelle observation est ≤ ou quand la précédente est plus vieille que sa fenêtre. `level = unknown` quand l'observation a plus d'une fenêtre. `AdminStatsResource::$esiRateLimits` (`EsiRateLimitGroupDto[]`, `EsiErrorBudgetDto` inchangé) ; l'endpoint reste réservé aux admins. Front : composant `EsiRateLimitCard.vue` (tableau HUD compact : groupe, restant / limite, niveau) placé sous `EsiErrorBudgetCard` dans `Admin.vue`, composant séparé pour ne pas entrer en conflit avec #813.
12. **Redis en panne** : lecture et écriture protégées comme `EsiErrorBudget` (log `warning`, aucune exception, aucun ralentissement). Ce n'est pas une donnée métier.
13. **Skill `esi-api`** : section « Rate Limiting » mise à jour (nouvelle sous-section sur les groupes, seuils, hypothèse de retour des tokens, `EsiRateLimitBudget`).

## Exemples chiffrés (tests rouges)

| Cas | Entrée | Sortie |
|---|---|---|
| Lecture | headers `Group=assets`, `Limit=150/15m`, `Remaining=140` | bucket `{limit 150, window 900 s, remaining 140}` |
| Limite en heures | `Limit=600/1h` | `windowSeconds = 3600` |
| Header illisible | `Limit=abc` | rien enregistré, aucune exception |
| Header absent | pas de `X-Ratelimit-Group` | aucun bucket, aucune association famille |
| Ok | 150 tokens, restant 100 | aucun sleep |
| Low | limit 150, restant 15 (seuil low 30) | sleep 500 ms |
| Critical | limit 150, restant 3, restant estimé sous 7,5 | pause > 0 s et ≤ 60 s |
| Retour estimé | limit 150, fenêtre 900 s, restant 0, 9 s après | estimé 1,5 → toujours critical |
| 429 | `Retry-After: 120`, groupe connu | `retryUntil = observedAt + 120`, restant 0 ; pause avant requête suivante = 60 s (plafond) ; sleep du rejeu = 60 s (inchangé) |
| Lot | 40 requêtes d'un groupe `limit 150`, restant estimé 50 (dépense 80) | pause avant le lot, puis les 40 requêtes sont lancées |
| Lot sans groupe connu | première requête | aucune pause |
| Portées | 2 personnages, même groupe | 2 buckets distincts ; la route publique a la portée `public` |
| Admin | buckets `assets` (restant 20 puis 5), `market` (restant 100/150) | `assets` restant 5 en premier, `critical` |

## Cas limites

- `Limit` à `0`, `Remaining` négatif ou supérieur à `limit` : observation ignorée (log `warning`).
- `Remaining` lu par des réponses concurrentes d'un lot dans le désordre : la plus récente par `observedAt` gagne pour le bucket, la plus basse gagne pour l'admin.
- Pas de sleep réel dans les tests : `MockClock` injecté dans `EsiClient` et `EsiRateLimitBudget`.
- `getPaginated*` : le contrôle s'applique à chaque page, comme `throttleIfNeeded()` aujourd'hui (via `request()`).

## Accès et fraîcheur

Pas de nouvel endpoint ESI ni de scope. `esiRateLimits` est servi par `GET /admin/stats` déjà réservé aux admins ; ni identifiant de personnage ni de corporation n'y apparaît. Fraîcheur : observation datée, `level = unknown` au-delà de la fenêtre.

## Tests existants modifiés ou contredits

Aucun attendu : `EsiClientTest` et `EsiClientErrorBudgetTest` n'ont pas de bucket connu (pas de groupe lu avant la requête testée) ; les 429 du harnais portent `Remaining: 0` sur un groupe `esi-test` : le test rouge vérifie que le sleep de rejeu reste celui de `Retry-After`. Si un test existant échoue, c'est le comportement de la règle 5 qui est en cause.

## Hors périmètre

Découpage d'un lot en sous-lots ; limite par IP côté routes publiques (une seule IP serveur, portée `public`) ; notifications Mercure du budget (#813, reste compatible : `esiErrorBudget` inchangé) ; file d'attente par groupe côté Messenger.

## Décisions (mode nuit)

- D1 : pool `esi_error_budget.cache` réutilisé (aucune nouvelle infrastructure Redis, clés préfixées `esi_rl_`).
- D2 : seuils en ratio de `limit` (les limites varient de 150 à 600 selon le groupe) et non en valeur absolue.
- D3 : hypothèse prudente écrite, retour des tokens au rythme moyen `limit / fenêtre` (la doc ne donne pas le moment du retour) ; corrigée par chaque réponse.
- D4 : plafond de pause à 60 s pour ne jamais bloquer un worker (même plafond que `Retry-After` à la 429).
- D5 : portée publique `public` (une IP serveur), pas d'identifiant d'application.
- D6 : aucun réglage ni constante existants retirés ; `errorLimitRemain` et `EsiErrorBudget` inchangés.

## Glossaire

Ajout : **Budget ESI de groupe** / *ESI group budget* : jetons restants d'un groupe de routes de l'ESI pour une portée (personnage ou public), lus sur `X-Ratelimit-*`, partagés entre workers (`EsiRateLimitBudget`). Distinct du **budget d'erreurs** (420, `EsiErrorBudget`).
