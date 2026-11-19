## Contexte

Issue #24 : le client ESI gérait mal les limites de débit.
- Un 420 sur un POST était rejoué en GET.
- Le retry d'un GET perdait les en-têtes additionnels.
- Les pages de `getPaginated()` n'étaient jamais rejouées.
- Un 429 échouait immédiatement, sans tenir compte de `Retry-After`.

`EsiClient` n'avait aucun test (#43).

## Changements

- Toutes les requêtes passent par un helper unique `request()`. `requestWithRetry()` rejoue **une seule fois** la même requête (même méthode, même body JSON, mêmes en-têtes, `Authorization` recalculé) :
  - après l'attente de la fenêtre d'error-limit, pour un 420 ;
  - après `Retry-After`, plafonné à 60 s, pour un 429. Si l'en-tête est absent ou invalide, on retombe sur l'attente d'error-limit.
- Le retry s'applique à `get()`, `post()`, chaque page de `getPaginated()` et `getWithCache()`, y compris la requête conditionnelle avec ETag. `getScalar()` et `postEmpty()` restent sans retry, comme avant.
- Le retry ne refait pas de throttling : la fenêtre vient d'être attendue, donc on évite la double attente.
- Comportements corrigés au passage :
  - `getWithCache()` sans cache rejoue maintenant avec le token (`main` le perdait) ;
  - la requête conditionnelle rejoue avec `If-None-Match` et le token (`main` rejouait sans token).

## À relire

- C'est un refactor du cœur réseau. Toutes les syncs passent par ce client.
- Le plafond de 60 s et la durée des attentes ne sont pas testables tant que `sleep()` est appelé en direct. Une horloge injectable (`Symfony\Component\Clock`) tuerait les mutants restants sur les durées. Je la propose en suivi, pas dans cette PR.
- Revue `esi-reviewer` : aucun écart bloquant. Le seul point moyen (pas de retry de la requête conditionnelle) est corrigé dans le 2ᵉ commit, avec un test.

## Vérifications

- `EsiClientTest` : nouveau fichier, 15 tests (verrous sur 420 et 429, POST, pagination, ETag/304, en-têtes).
- Suite complète : 838 tests, 0 échec.
- PHPStan : 63 erreurs, comme sur `main`. deptrac : 35 violations, comme sur `main`.
- MSI de `EsiClient.php` : environ 2 % (dossier `src/Service/ESI` sur `main`) → 59 %.

Fixes #24. Avance #43.
