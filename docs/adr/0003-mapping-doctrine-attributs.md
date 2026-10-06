# ADR-0003 — Mapping Doctrine par attributs dans le Domain

- **Statut** : Acceptée
- **Date** : 2026-10-06

## Contexte

Un Domain « pur » imposerait un mapping XML séparé des entités. Le projet utilise exclusivement les attributs PHP 8 (convention Symfony du projet), et maintenir deux représentations d'une même entité est une source de dérive.

## Décision

- Le mapping Doctrine reste en attributs, dans les entités du Domain.
- Exception assumée : le Domain peut dépendre des attributs `Doctrine\ORM\Mapping`, et de **rien d'autre** côté Doctrine. Pas d'`EntityManager`, pas de repository concret, pas de `Doctrine\ORM\*` hors `Mapping`.

## Conséquences

- Une seule source de vérité pour le mapping.
- La règle sera vérifiée mécaniquement par deptrac en mode strict (Phase 3).
- Les repositories du Domain sont des interfaces. Leurs implémentations Doctrine vivent en Infrastructure.
