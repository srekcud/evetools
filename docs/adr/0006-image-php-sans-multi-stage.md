# ADR-0006 — Pas de multi-stage pour l'image PHP pour l'instant

- **Statut** : Acceptée (révisable sur données)
- **Date** : 2026-10-06

## Contexte

L'image PHP (`Dockerfile.base` + `Dockerfile`) sert au dev et à la prod. En dev, Composer est utilisé dans le conteneur. Un build multi-stage avec cibles `dev` et `prod` réduirait l'image de prod, mais ajoute de la complexité, sans mesure à ce stade.

## Décision

- Pas de multi-stage pour l'image PHP pour l'instant.
- On décide sur mesure en Phase 2 (`/docker-optimize`) :
  - **variante A** : Dockerfile actuel optimisé (`.dockerignore`, retrait des paquets `-dev`, OPcache prod…) ;
  - **variante B** : multi-stage.
  - Critères : taille, couches, CVE, temps de build, smoke test.
- Le proxy garde son multi-stage.
- Les outils de dev (pcov) passent par un `Dockerfile.dev` séparé, utilisé uniquement par `docker-compose.override.yml`. L'image de prod ne change pas.

## Conséquences

- B n'est retenue que si l'écart mesuré justifie la complexité. Sinon, cette ADR reste en vigueur.
