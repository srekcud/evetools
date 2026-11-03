---
name: fix-group
description: Traite dans un thread toutes les issues d'un label group:*, chacune selon son label circuit:* (léger, standard, complet), jusqu'à la fusion locale dans main.
disable-model-invocation: true
argument-hint: <label group:…>
---

Groupe : `$ARGUMENTS`. Les issues d'un groupe partagent une cause racine ou les mêmes fichiers. **Un thread traite UNE issue du groupe** (la première non traitée, dans l'ordre du tri), puis s'arrête après la fusion, la fermeture et le journal : le contexte d'un thread grossit à chaque agent lancé et se relit à chaque tour, donc l'issue suivante part dans un nouveau thread, qui relit le journal et `docs/specs/` pour retrouver l'état. Exception : plusieurs issues sur une même règle (étape 1.5) restent dans le même thread.

## 1. Tri du groupe

1. `zsh -ic 'gh issue list --state open --label "$ARGUMENTS" --json number,title,labels'`.
2. Écarte les issues `circuit:decision` : elles attendent Sylvain. Note-les dans le journal.
3. Lis le dernier tri `.claude/tasks/caduc-sweep-*/` : une issue `corrigé` ou `caduc` n'est pas traitée, sa preuve va dans le journal. Une issue `partiel` ne garde que ce qui reste.
4. Ordre : d'abord l'issue qui porte la cause racine (souvent `circuit:l`), puis les autres. Après chaque fusion, vérifie si les suivantes sont devenues caduques (test ou `fichier::méthode` à l'appui).
5. Plusieurs issues sur une même règle : une seule spec `docs/specs/<n1>-<n2>-<slug>.md`, un seul lot rouge puis vert, chaque numéro cité dans les commits.

## 2. Circuit de chaque issue

| Label | Spec | Note de câblage (`architect`) | Rouge | Vert | Revue |
|---|---|---|---|---|---|
| `circuit:s` | aucune, l'issue suffit | non | selon le cas ci-dessous | selon le cas | `security-reviewer` + gates de `/review` |
| `circuit:m` | `/spec` | non (étape 1b de `/tdd-red` sautée) | `/tdd-red` | `/tdd-green` | `/review` |
| `circuit:l` | `/spec` | oui | `/tdd-red` | `/tdd-green` | `/review` |

`circuit:s`, trois cas :
- sans logique métier (CSS, accessibilité, i18n, docs, code mort) : un seul agent, `frontend-developer` (front), `implementer` (suppression backend) ou `doc-writer` (docs), lancé avec `model: sonnet` ;
- tests seuls (mutants, caractérisation) : `test-writer`, puis `test-skeptic` ;
- petite logique : `test-writer` puis `implementer`, sans fichier de spec ni ligne dans `red-queue.md`.

Un fix reste un rouge puis un vert : jamais `backend-developer` pour un fix, quel que soit le circuit. Si l'issue s'avère plus grosse que son label, change de circuit et note-le dans le journal.

## 3. Modèles

- Ce thread orchestre en Sonnet. Il ne lit pas `src/` ni `tests/` au-delà d'un `grep -n` pour localiser, et passe aux agents des pointeurs (fichier, méthode, `docs/modules/industry.md`).
- `test-writer`, `architect`, `auditor` : Opus. `implementer`, relecteurs, `test-skeptic` : Sonnet. Les modèles sont fixés dans les fichiers d'agent.
- Un `implementer` qui bute deux fois sur le même échec : relance le lot avec `model: opus` plutôt que de boucler.

## 4. Technicité du lot (autonomie de push et de fermeture)

Elle se calcule sur le diff de la branche (`git diff main...HEAD`), jamais sur l'impression. Le niveau est le plus haut des critères atteints. Un label `tech:3` posé par Sylvain sur l'issue force le niveau 3.

| Niveau | Critères (un seul suffit pour monter) | Push de `main` | Issue |
|---|---|---|---|
| 1 routine | aucun critère des niveaux 2 et 3 : correctif ou fonctionnalité contenus dans un module, tests et gates verts | seul, si le brief du thread l'autorise | fermée seule |
| 2 sensible | migration ; nouveau endpoint ESI, scope ou sync planifiée ; nouvelle ressource API exposant des données d'un utilisateur ou d'une corporation ; montant affiché modifié ; reste volontairement ouvert (lot partiel) ; défaut hors comportement corrigé par l'étape 3b de `/tdd-green` (liste exhaustive, lint, conflit de rebase), fichier et raison au journal | seul, si le brief l'autorise, après une revue `security-reviewer` sans constat bloquant | reste ouverte avec un commentaire qui dit ce qui reste, sauf si Sylvain l'a dit fermable |
| 3 critique | code d'authentification, voter ou scoping ; suppression ou purge de données ; nouvelle dépendance ; code Industry ; Makefile, `.docker/`, CI ou déploiement ; verrou de phase levé hors de l'étape 3b de `/tdd-green` ; gate rouge non expliqué ; assertion d'un test verrouillé modifiée (`git diff main...HEAD -U0 -- tests frontend/tests` ajoute ou retire une ligne `assert*`, `expect(` ou `#[DataProvider]`) | jamais : fusion locale seulement, push à Sylvain | jamais fermée seule, commentaire en attente |

- Sans mention dans le brief du thread, le push reste à Sylvain quel que soit le niveau (règle globale).
- Le niveau et ses critères atteints vont dans le journal avant la fusion (`niveau 2 : migration, nouvel endpoint ESI`), puis dans le rapport final.
- Un doute entre deux niveaux : le plus haut.

## 5. Fusion de chaque lot

1. Rebase sur `main`, puis `/review` : c'est l'unique passage de la batterie complète.
2. Fusion fast-forward dans `main`. Si le checkout principal est sur `main` : `git -C /home/sdu/Documents/projects/perso/evetools merge --ff-only <branche>`. Sinon (autre branche courante, constaté le 2026-11-03), n'y touche pas : depuis ton worktree, `git fetch . <branche>:main` (refus automatique si ce n'est pas un fast-forward).
3. Après la fusion et les gates verts, **si la technicité du lot (section 4) l'autorise** : `git push origin main` (fast-forward seulement, jamais `--force`, jamais de tag). Sinon, laisse `main` local et note-le dans le journal. Si le push est rejeté, ne résous rien : note-le dans le journal et dans le rapport.
4. Si la technicité l'autorise, ferme chaque issue dont le correctif est entièrement dans `main` : `zsh -ic 'gh issue close <n> --reason completed --comment "Corrigé dans main (<sha>) : <une ligne>."'` (commentaire en français, sans attribution IA). Une issue partielle, ou dont une décision reste ouverte, reste ouverte avec un commentaire qui dit ce qui reste. Vérifie l'état avec `gh issue view <n> --json state` avant et après (le push d'un autre thread ou une fermeture manuelle est possible).

Les gates 🚦 de `/spec` et `/tdd-red` restent à Sylvain, sauf si le brief du thread indique le mode nuit.

## 4b. Budget de tokens

Décision de Sylvain (2026-11-04) : une issue coûtait environ 1,2 M de tokens de sous-agents (spec 200 à 280 k, rouge 330 à 440 k, vert 350 à 410 k, revue 80 à 130 k).
- **Spec : 120 lignes au plus** (règles numérotées, tableau d'exemples chiffrés, cas limites, hors périmètre, décisions ouvertes). Ni tableau de clés i18n, ni liste d'endpoints détaillée, ni annexe harnais : l'implementer tranche. Pas de nouvelle maquette quand une maquette retenue existe.
- **Tests : proportionnés** (voir `/tdd-red` étape 2). `test-writer` en Sonnet par défaut ; `model: opus` seulement pour un calcul métier pur (répartition, dette, quantité, prix).
- **Revue sécurité : une par groupe**, pas une par issue (voir `/review`).
- Le rapport de chaque sous-agent donne ses tokens ; le thread les reporte par phase dans le journal pour mesurer l'écart.

## 6. Rapport final (10 lignes max, en français)

Une ligne par issue du groupe : fermée (commit), caduque (preuve), en attente de décision, bloquée (raison). Dis si `main` est poussé (dernier commit). Puis les actions prod (migration, commande console).
