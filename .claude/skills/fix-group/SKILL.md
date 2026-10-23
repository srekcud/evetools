---
name: fix-group
description: Traite dans un thread toutes les issues d'un label group:*, chacune selon son label circuit:* (léger, standard, complet), jusqu'à la fusion locale dans main.
disable-model-invocation: true
argument-hint: <label group:…>
---

Groupe : `$ARGUMENTS`. Un thread traite un groupe, dans son worktree. Les groupes partagent une cause racine ou les mêmes fichiers : le contexte se charge une fois pour tout le groupe.

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

## 4. Fusion de chaque lot

1. Rebase sur `main`, puis `/review` : c'est l'unique passage de la batterie complète.
2. `git -C /home/sdu/Documents/projects/perso/evetools merge --ff-only <branche>`.
3. Pas de push, pas de fermeture d'issue, pas de commentaire GitHub.

Les gates 🚦 de `/spec` et `/tdd-red` restent à Sylvain, sauf si le brief du thread indique le mode nuit.

## 5. Rapport final (10 lignes max, en français)

Une ligne par issue du groupe : fusionnée (commit), caduque (preuve), en attente de décision, bloquée (raison). Puis les actions prod (migration, commande console).
