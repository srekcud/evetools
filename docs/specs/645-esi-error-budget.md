# Spec #645 : budget d'erreurs ESI partagé et compteurs 420/429 sur la page admin

Circuit M, mode nuit, sans sous-agent. Alimente #642 (bandeau de statut, absent de origin/main : l'API expose `level`, le bandeau le lira).

## Règles

1. `EsiErrorBudget` (`src/Service/ESI/`) range dans un pool de cache Redis dédié (`esi_error_budget.cache`) la dernière observation de `x-esi-error-limit-remain` et `x-esi-error-limit-reset` vue par n'importe quel processus, avec son horodatage. `EsiClient` la lit des en-têtes de chaque réponse qui porte les deux (`processRateLimitHeaders`). Un en-tête absent n'écrit rien.
2. Une observation identique (même `remain`) à celle déjà écrite par ce processus depuis moins de 30 s n'est pas réécrite ; un `remain` différent l'est toujours.
3. Chaque réponse 420 ou 429 reçue est comptée une seule fois (première réponse, rejeu, clé de lot, page d'une liste paginée, POST), par heure UTC et par famille d'endpoint. Famille = chemin sans query, segments numériques remplacés par `{id}` (`/characters/123/assets/?page=2` donne `/characters/{id}/assets/`).
4. `snapshot()` rend : `remain`, `resetSeconds`, `observedAt`, `windowEndsAt` (observedAt + reset), `level`, les compteurs 420/429 des 24 dernières heures (24 tranches horaires, la tranche courante incluse), et les compteurs par famille sur ces 24 h (tri décroissant sur le total).
5. `level` : `unknown` sans observation ou quand la fenêtre de reset est écoulée (le budget est reconstitué, la valeur ne dit plus rien) ; sinon `critical` si `remain` < 5, `low` si `remain` < 20, `ok` sinon. Les seuils sont ceux déjà utilisés par la limitation de `EsiClient` (constantes partagées), qui ne change pas.
6. `GET /api/admin/stats` expose `esiErrorBudget` ; la page admin affiche une carte : budget restant, niveau, dernier reset, 420/429 sur 24 h, tranches horaires, familles.
7. Une panne du cache ne casse jamais un appel ESI : elle est journalisée en warning.

## Exemples

| Entrée | Résultat |
|---|---|
| observe(100, 60) | level `ok` |
| observe(20, 60) / (19, 60) / (5, 60) / (4, 60) | `ok` / `low` / `low` / `critical` |
| observe(3, 60) puis 61 s | level `unknown`, `remain` 3 conservé |
| 420 sur `/characters/1/assets/`, 429 sur `/characters/2/assets/?page=2` | famille `/characters/{id}/assets/` : 420=1, 429=1 |
| 420 il y a 23 h / 25 h | compté / hors des 24 h |

## Hors périmètre

- Le comportement de limitation de `EsiClient` (pauses, rejeu).
- Le bandeau de statut (#642). Compteurs atomiques (le cache PSR ne fait pas d'incrément atomique : deux workers simultanés peuvent perdre un compte, acceptable pour un indicateur).

## Décisions

- Pool de cache dédié plutôt que `esi.cache` (celui-ci est purgé par tag et par `cache:clear` admin).
- `level` `unknown` après la fenêtre de reset plutôt qu'une valeur `ok` supposée.
