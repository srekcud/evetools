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
   - si un comportement est suspecté faux, le test le fige quand même. Il est signalé par son nom (`…Currently…IssueN`) et par `#[Group('issue-N')]`, sans commentaire ;
   - **copie mot pour mot dans le prompt les exigences a-e ci-dessous**. Le writer les vérifie lui-même avant de rendre la main, et son rapport dit, pour chacune, comment elle est tenue :
     a. **Un bug connu, un test.** Un bug connu (B1/#2 runs lus comme quantité, etc.) n'apparaît que dans les attendus des tests qui le visent (`…IssueN`, groupe `issue-N`). Les autres tests choisissent des entrées où ce bug ne change pas le résultat (par exemple 1 run, ou un seul niveau), pour qu'ils ne cassent pas à la migration pour une raison qui n'est pas la leur ;
     b. **Des valeurs distinctes.** Un prix, une quantité et un bonus différents par typeId, sans multiples simples entre eux. Jamais « toutes les feuilles à 100 ISK » ni deux types de même prix et même quantité : une valeur attachée au mauvais type doit changer un attendu ;
     c. **Toutes les sorties clés assertées**, en valeurs exactes : chaque champ que lit un appelant (cf. le `grep` de l'étape 1). Pas d'affirmation dans un nom de test ou un rapport que ne prouve pas une assertion ;
     d. **Un snapshot manquant fait échouer le test.** Il ne s'écrit qu'avec `UPDATE_SNAPSHOTS=1` (modèle : `ProjectProviderGoldenTreeCharacterizationTest`). Jamais de `markTestIncomplete` ni de `markTestSkipped` sur une donnée absente ;
     e. **Tout comportement figé et suspecté faux** porte `Currently` dans son nom, un numéro d'issue et le groupe `issue-N`. Sans issue, le writer le liste en proposition d'issue.
4. Lance les tests : `docker compose exec app php vendor/bin/phpunit --no-coverage --fail-on-incomplete --fail-on-skipped --filter <Classe>`. Ils doivent tous passer sur le code actuel. Les tests d'intégration, lancés dans un worktree, passent par `.claude/scripts/wt-php.sh <worktree> integration …` (cf. `lessons.md`), jamais par le seul mode unitaire.
5. Mesure la couverture du service : `docker compose exec app php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text --filter <Classe>`.
6. **Relecture obligatoire avant fusion** : `test-skeptic`, avec la grille a-e et son mandat habituel. Son verdict est `suffisant` ou `insuffisant`. Si c'est `insuffisant`, renvoie ses points au même `test-writer` (SendMessage, contexte conservé), puis relance la relecture. **Aucune fusion sans un verdict `suffisant`**, noté dans `.claude/tasks/todo.md` avec le MSI de caractérisation.
7. Présente :
   - les tests ajoutés ;
   - la couverture avant et après ;
   - le verdict `test-skeptic` et le MSI de caractérisation ;
   - la liste des comportements suspectés faux, en propositions d'issues.

Ne modifie jamais `src/`.
