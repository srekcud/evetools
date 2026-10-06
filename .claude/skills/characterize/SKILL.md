---
name: characterize
description: Écrit des tests de caractérisation qui figent le comportement actuel d'un service avant tout refactoring, y compris s'il est faux.
disable-model-invocation: true
argument-hint: <Service> (ex. IndustryTreeService)
---

Service à caractériser : $ARGUMENTS

But : poser un filet de sécurité **avant** tout refactoring. Un test de caractérisation décrit ce que le code **fait**, pas ce qu'il **devrait** faire.

1. Lis le service, ses appelants (`grep` sur le nom de la classe et des méthodes publiques), ses tests existants et `docs/glossary.md`.
2. Liste les comportements observables : méthodes publiques, entrées représentatives, cas limites. Couvre obligatoirement :
   - un produit à **plusieurs unités par run** ;
   - les arrondis (`ceil`), les ME et TE extrêmes (0 et max), les réactions ;
   - les données ESI ou SDE manquantes.
3. Délègue l'écriture à l'agent `test-writer`, dans `tests/` uniquement :
   - mocks seulement aux frontières (ESI, base, cache, horloge) ;
   - si un comportement est suspecté faux, le test le fige quand même. Son nom et un commentaire le signalent : `// CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #...`.
4. Lance les tests : `docker compose exec app php vendor/bin/phpunit --no-coverage --filter <Classe>`. Ils doivent tous passer sur le code actuel.
5. Mesure la couverture du service : `docker compose exec app php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text --filter <Classe>`.
6. Fais relire les tests par `test-skeptic` : quels tests passeraient même si le code était faux ?
7. Présente :
   - les tests ajoutés ;
   - la couverture avant et après ;
   - la liste des comportements suspectés faux, en propositions d'issues.

Ne modifie jamais `src/`.
