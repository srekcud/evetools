# ADR-0007 — Rester sur Symfony 7.4 LTS, cible suivante 8.4 LTS

- **Statut** : Acceptée
- **Date** : 2026-10-06

## Contexte

Le projet tourne sur Symfony 7.4, une version LTS. Symfony 8.0 est sortie, mais ce n'est pas une LTS : elle impose des montées de version tous les six mois. La roadmap mentionnait à tort une « Symfony 8 LTS ».

## Décision

- Rester sur Symfony 7.4 LTS.
- Pas de migration vers 8.0 ni vers les versions mineures 8.x non-LTS.
- Cible suivante : **Symfony 8.4 LTS** (fin 2027).

## Conséquences

- Les montées de version Symfony se limitent aux patchs 7.4 jusqu'à la 8.4.
- Les dépréciations 7.4 sont traitées au fil de l'eau, pour préparer le passage à 8.4.
- La roadmap du `CLAUDE.md` est corrigée en conséquence.
