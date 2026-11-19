Tu es un thread de la boucle de nuit de Sylvain. **Mode nuit** : gates validés en autonomie. **Thread Centre d'aide : les 16 issues `docs(help)` du label `group:help-center`, enchaînées dans UNE seule branche et UNE seule PR** (consigne explicite de Sylvain du 2026-11-14, elle remplace « une PR par issue » et « arrêt après 4 issues » du brief commun).

Issues, dans cet ordre : #936 FAQ ; playbooks #937, #938, #939, #940, #941, #942, #943 ; calculs #944, #945, #946, #947, #948, #949 ; #950 données, fraîcheur et vie privée ; #951 limites connues. Hors périmètre : #934 (route `/help`, rendu `marked`) et #935 (tableaux générés), qui sont des `feat`.

Branche dans ton worktree : `git fetch origin && git switch --no-track -c docs/night-help-center origin/main`.

À lire d'abord (et seulement cela) :
1. `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-thread-brief.md` (brief commun, sauf les points remplacés ici) ; journal : `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/loop-2026-11-14.md` (préfixe `[#N]`, ajout uniquement `>>`).
2. `/home/sdu/Documents/projects/perso/evetools/.claude/tasks/help-center-plan-2026-11-13.md` (décisions de Sylvain, arborescence, premier jet de la FAQ et des playbooks).
3. Par issue : `zsh -ic 'gh issue view N --json title,body,comments'` (gh n'est authentifié que via `zsh -ic`).

Prérequis : #934 (route `/help`, rendu `marked`, déménagement de `docs/user/` vers `frontend/src/help/`) est en cours dans un autre thread, branche locale `fix/night-934` (worktree `/home/sdu/.t3/worktrees/evetools/fix-night-934-7e399469ebcfa003`). Avant la première page, lis en lecture seule sa convention (`git log origin/main..fix/night-934 --oneline`, `git show fix/night-934 --stat`, puis le composant/loader qui fait `import.meta.glob` sur `frontend/src/help/`) : nommage des fichiers, sections, frontmatter éventuel, index. Adopte-la exactement. Si #934 n'a encore rien fixé, prends une convention simple (`frontend/src/help/<lang>/<section>-<slug>.md`, section dans faq, playbook, calculation, data, limits, même slug anglais dans les deux langues), écris-la sous « Décisions » du journal et dans la PR. Tu ne touches ni à `docs/user/` ni au code de #934, et tu ne pars pas de sa branche : ta branche part d'origin/main, ta PR dit « merge #934 first ».

Déroulé par issue (circuit:s docs) :
- **Un seul sous-agent à la fois** : `doc-writer` avec `model: sonnet`, une issue par lancement, brief = corps de l'issue + chemins des fichiers de code à vérifier (repérés par toi avec `grep -n` seulement) + convention de nommage + glossaire `docs/glossary.md`. Pages calcul (#944 à #949) : `doc-writer` en `model: opus`, formules vérifiées dans le code (`docs/modules/industry.md` pour l'industrie).
- Règles des issues non négociables : chaque affirmation vérifiée dans le code ; aucun chiffre que le code ou un outil n'a pas donné (exemples chiffrés marqués comme exemples, valeurs rondes) ; donnée manquante décrite comme inconnue, jamais zéro ; aucun vrai personnage, corporation ou alliance (dépôt public) ; pas de capture d'écran cette nuit ; terme absent du glossaire proposé dans le glossaire, pas inventé ; FR et EN dans le même commit.
- Tu relis le diff de la page contre l'issue (pas de `security-reviewer`), puis **un commit par issue** : `docs(help): <titre de l'issue> (#N)`, en anglais, sans attribution IA. Journal : une ligne `[#N]` avec les tokens rapportés par le `doc-writer`.
- #950 dépend de #935 : écris la page sans recopier à la main les tableaux scopes et fréquences (renvoi « tableau généré, #935 »), et pas de `Closes #950` ; commentaire sur l'issue qui dit ce qui reste.
- Une issue bloquée (décision produit, fait introuvable dans le code) : Blocage dans le journal (options + recommandation), commentaire sur l'issue, pas de `Closes`, issue suivante. N'arrête pas la chaîne pour elle.
- Gates : aucun code touché, donc pas de PHPUnit ni PHPStan. Si un fichier hors `frontend/src/help/` et `docs/glossary.md` change, arrête-toi et journalise.

Gestion du contexte : 16 pages, donc ne lis jamais une page produite en entier (diff `--stat` puis `sed -n` ciblé) et ne garde pas les rapports d'agents au-delà de leur conclusion. Si le contexte devient trop chargé avant la fin, pousse la branche, ouvre ou mets à jour la PR avec les issues faites, liste les restantes dans le journal et termine ; l'orchestrateur relancera un thread sur la même branche.

Push et PR : après la première issue commitée, `git push origin docs/night-help-center` (autorisé sans demande, jamais `main`, jamais de force ni de tag), puis ouvre la PR en brouillon : `zsh -ic 'gh pr create --draft --base main --head docs/night-help-center --title "docs(help): help center pages (FAQ, playbooks, calculations, data, limits)" --body "..."'`, liée avec `link_pull_request`. Après chaque issue : push, et mise à jour du corps (`gh pr edit --body`) avec une ligne `Closes #N` par issue complète, la convention de nommage, la dépendance à #934 (merge #934 first) et « Sylvain reviews the calculation pages before merge ». Corps en anglais, sans attribution IA. Fin de chaîne : `gh pr ready`. Tu ne fermes aucune issue. Push impossible : 3 essais au plus puis `BLOCAGE environnement` au journal et fin.

Limite d'usage (« 5-hour limit, resets HH:MM ») : `[help] LIMITE jusqu'à HH:MM` au journal, push de ce qui est commité, fin du tour sans boucler.

Journal : une ligne `[help] rétro :` (frictions, preuve) avant le rapport.

Rapport final (français, 10 lignes max) : URL de la PR et dernier SHA, issues fermables (Closes), partielles ou bloquées avec raison, tokens totaux, décisions prises (convention de nommage, termes ajoutés au glossaire).
