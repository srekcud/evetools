---
name: mtp
description: Mise en prod. Rédige la section de la version dans les release notes in-app (frontend/public/RELEASE_NOTES.txt), la fusionne sur main par PR, puis pose et pousse le tag V0.x qui lance .github/workflows/release.yml.
disable-model-invocation: true
argument-hint: [V0.x] (défaut : version mineure suivante du dernier tag)
---

Version visée : `$ARGUMENTS`. Le déroulé du déploiement est dans `docs/deploy.md`. `gh` se lance via `zsh -ic '…'` (token dans `~/.zshrc`).

1. **Base et version.** `git fetch origin --tags`. Dernier tag : `git tag --list 'V*' --sort=-v:refname | head -1`. Sans argument, la version est la mineure suivante (`V0.15` → `V0.16`) ; un correctif (`V0.15.1`) se passe en argument. Arrêt si le tag existe déjà ou si `git log <dernier tag>..origin/main` est vide.
2. **Contenu.** Liste les PR fusionnées depuis le dernier tag : `git log --format='%h %s' <dernier tag>..origin/main`, labels lus avec `gh pr view <n> --json title,labels,body`. Ne garde que ce qu'un joueur voit (`feat`, `fix`, labels `feature`, `enhancement`, `bug`, `ux`, `perf` visible). Écarte `ci`, `chore`, `test`, `refactor`, `docs`, `build`, l'infra sans effet visible. Une section `Version <x> (` déjà présente dans `RELEASE_NOTES.txt` de `origin/main` (ajoutée par une PR de feature) est complétée, pas dupliquée.
3. **Rédaction.** Même format que les sections précédentes du fichier :
   - bandeau de tirets (80 caractères), `Version 0.16 (AAAA-MM-JJ)` (date du jour, sans le `V`), bandeau ;
   - français sans accents, rubriques `### <module>` (Pont, Industrie, Minage, Commerce, PVE, Assets, Alertes de prix, Notifications, Compte…), puces courtes, lignes de 80 caractères au plus, suite de puce indentée de deux espaces ;
   - écrit pour un joueur : ce qui change à l'écran, pas le nom d'une classe ni d'une issue ;
   - rubrique `### A lire avant tout` en tête si une donnée est effacée ou une action du joueur est requise.
   Insère la section (dans le worktree de l'étape 4) juste après l'en-tête du fichier, au-dessus de la version précédente. Montre-la à Sylvain et attends son accord ou ses corrections avant de continuer.
4. **PR.** Le checkout principal reste détaché sur `origin/main` (cron `sync-local-main.sh`) : travaille dans un worktree, `git worktree add --no-track -b docs/release-notes-<version> ~/.t3/worktrees/evetools/release-notes-<version> origin/main`, retiré après la fusion. Commit `docs(release): add the <version> in-app release notes`, push de la branche, `gh pr create` (titre identique, corps : la liste des PR couvertes, labels `docs`). Attends la CI verte (`gh pr checks`), puis `gh pr merge --squash --delete-branch`. Rien d'autre dans cette PR.
5. **Vérification avant tag.** `git fetch origin`, puis :
   - `git show origin/main:frontend/public/RELEASE_NOTES.txt | grep -q "^Version ${VERSION#V} ("` (le job `release-notes` fait le même contrôle) ;
   - CI de `origin/main` verte : `gh run list --branch main --workflow ci.yml --limit 1` ;
   - aucune PR fusionnée depuis l'étape 2 sans être dans les notes ; sinon retour à l'étape 3.
6. **Tag.** Tag léger sur `origin/main`, comme les précédents : `git tag <version> origin/main`, puis `git push origin <version>`. Sylvain autorise ce push sans confirmation (2026-11-12) : `git-push-guard.sh` laisse passer un seul tag `V0.x` poussé sur `origin`, toute autre forme (`--tags`, plusieurs tags, suppression) reste demandée. Ne pousse le tag qu'une fois les contrôles de l'étape 5 passés : ce push déploie en production.
7. **Suivi.** Donne le lien du run (`gh run list --workflow release.yml --limit 1`) et laisse Sylvain le suivre ; ne relance rien en cas d'échec, rapporte le job et l'erreur. La GitHub Release est créée par le workflow après le déploiement, ne la crée pas à la main.
8. **Rappel post-déploiement.** Liste les actions à faire en prod que le déploiement ne fait pas seul : commande console, seed, fixture, import SDE, variable d'env nouvelle (nom et rôle, jamais la valeur), cherchées dans le diff `<dernier tag>..<version>` (`src/Command/`, `.env`, `compose*.yaml`, corps des PR). Les migrations Doctrine sont jouées par `bin/deploy`, pas besoin de les citer. Ne lance aucune commande en prod.
