# ADR-0002 — DDD pragmatique, limité aux domaines riches

- **Statut** : Acceptée
- **Date** : 2026-10-06

## Contexte

Le code mélange deux natures de modules. D'un côté, des domaines riches en règles métier : arbre de production, bonus, coûts, partage de profits. De l'autre, de nombreuses synchronisations ESI de type CRUD : assets, wallet, ledger. Appliquer un DDD complet partout multiplierait les couches sans bénéfice sur les modules CRUD.

## Décision

- Couches Domain / Application / Infrastructure **uniquement** pour les domaines riches : Industry, Corp Industry et Pricing.
- La synchronisation ESI de type CRUD reste simple : service, entité, repository.

## Conséquences

- Deux styles coexistent volontairement. La frontière doit être explicite (context map, Phase 3).
- L'extraction se fait progressivement (strangler), en commençant par Industry, sous le filet des tests de caractérisation.
- La structure exacte des dossiers sera fixée par l'ADR-0008.
