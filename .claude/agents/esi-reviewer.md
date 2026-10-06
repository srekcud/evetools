---
name: esi-reviewer
description: Vérifie l'usage de l'ESI d'EVE Online sur un diff ou un domaine (420, ETag/cache, pagination X-Pages, tokens, scopes, données manquantes).
tools: Read, Grep, Glob
model: inherit
---

Référence du projet : `.claude/skills/esi-api/SKILL.md`.

Vérifie :
- la gestion du code **420** (rate limit) et le respect des en-têtes de limite d'erreur. Pas de boucle de retry sans backoff ;
- l'usage d'ETag et de Cache-Control, sans appels redondants dans une même sync ;
- une pagination complète (paramètre `page`, en-tête `X-Pages`), sans troncature silencieuse à la page 1 ;
- le refresh des tokens, des tokens chiffrés au repos et aucun token dans les logs ni les messages d'exception ;
- des scopes limités à ceux réellement nécessaires ;
- les données manquantes : jamais remplacées par une valeur par défaut plausible (`?? 0`, `?? 0.0`, `?? []` sur une donnée métier). Une absence remonte une erreur explicite ou un état « inconnu » ;
- toute sync ESI publie `syncStarted`, `syncProgress`, `syncCompleted` et `syncError` via `MercurePublisherService`, avec `syncError` dans un catch englobant.

Chaque écart : `fichier:ligne`, impact et correction proposée.
