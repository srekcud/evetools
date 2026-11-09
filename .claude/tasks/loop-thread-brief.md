# Brief commun — thread de la boucle de résolution d'issues

Tu es un thread de la boucle de résolution d'issues lancée par Sylvain. **Une issue d'un groupe (label `group:*`) par thread** (la première non traitée de l'ordre du groupe ; décision de Sylvain du 2026-11-04 pour limiter les tokens, voir `/fix-group` section 4b), dans ton worktree, en Sonnet. Si Sylvain l'indique au lancement, tu travailles en **mode nuit** (spec et phase rouge validées en autonomie) ; sinon, les gates 🚦 restent à lui.

## À lire d'abord
- `CLAUDE.md` et l'index mémoire sont déjà chargés. Ne relis pas le glossaire, les ADR ni les skills d'avance : les agents lisent ce qui les concerne, et une skill se charge quand tu l'invoques.
- `.claude/tasks/lessons.md`, section worktrees seulement (`wt-php.sh <worktree> unit|integration`, jamais `git stash`, garde d'empreinte bloquante `&&`, base d'intégration isolée si migration).

## Déroulé
Suis `/fix-group <label du groupe>` : tri du groupe (dont le tri des caduques `.claude/tasks/caduc-sweep-*/`), circuit de chaque issue selon son label `circuit:*`, modèles par rôle, fusion locale de chaque lot.

## Arrêts obligatoires
- Une décision produit/structurelle non couverte par le brief ou l'issue (nouvelle dépendance, changement de montant affiché non prévu, suppression de donnée) : n'implémente pas, écris-la dans le journal sous « Blocages » avec options + recommandation, et passe à l'issue suivante du groupe.
- Un refus de permission ne se contourne pas. Aucun secret lu ni affiché.
- Pas de commentaire dans le code. Commits Conventional Commits en anglais, sans aucune attribution IA ni Co-Authored-By.

## Push et issues
Une PR par issue, quel que soit le niveau de technicité : push de la branche `fix/night-N` (jamais `main`, jamais de force ni de tag), PR avec `Closes #N`, liée par `link_pull_request`. Le thread ne ferme aucune issue, Sylvain relit et fusionne. Niveau de technicité (section 4 de `/fix-group`) noté dans le corps de la PR. Issue partielle ou décision ouverte : pas de `Closes`, commentaire sur l'issue.

## Journal
Ajoute des lignes courtes (horodatage `date +%H:%M`, jamais estimé) dans le fichier de journal indiqué au lancement (`.claude/tasks/loop-<date>.md`), sections Journal / Décisions / Blocages / Actions Sylvain (migration ou commande console à lancer en prod). Ajout uniquement (`>>` ou Edit ciblé), ne réécris jamais le fichier.

## Budget de tokens
Spec de 120 lignes au plus, tests proportionnés (`test-writer` en Sonnet sauf calcul métier pur), revue sécurité une fois par groupe, tokens par phase notés dans le journal.

## Rapport final (10 lignes max, en français)
Le rapport de `/fix-group` : l'issue traitée, les issues restantes du groupe, puis les actions prod.

## Rétrospective des skills
Pas de `/skill-retro` dans le thread : une ligne `[#N] rétro :` dans le journal (friction, preuve). La rétro est faite une fois par boucle par la session principale.
