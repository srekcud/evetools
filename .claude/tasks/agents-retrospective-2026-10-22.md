# Autocritique des agents — boucle du 2026-10-22 (00:20 → ~02:00)

Bilan : 12 lots fusionnés en local (#120, #18 vente, #8 ×5, #7 L4+L1a, #7 L1b ×3) + 3 lots de tests tueurs. Aucune proposition n'est appliquée : diffs à valider par Sylvain.

## Constats

| # | Fait observé | Coût | Agent / outil |
|---|---|---|---|
| C1 | Specs vitest avec `.at()` refusées par `vue-tsc` : build de `main` cassé une fois (e1288ca → b93098d), bloqué une fois en phase verte (#8 Profit Margins) | 2 allers-retours | test-writer |
| C2 | Fixture d'un spec existant sans le nouveau champ obligatoire (`missingPriceTypeIds`) : build en échec en phase verte | 1 aller-retour | test-writer |
| C3 | Un test de forme exacte (`assertSame` sur le tableau complet) hors du fichier visé non mis à jour en rouge : #120 (`StockpileStatusProviderTest`), #7 L1a (`EsiAdjustedPricesTest`, test « CARACTÉRISATION … issue #8 ») | 2 arrêts de l'implementer (corrects) | test-writer |
| C4 | Le hook `tdd-guard` lit la phase dans le dépôt principal (`CLAUDE_PROJECT_DIR`) et ne bloque que `tests/` relatif ou du dépôt principal : en worktree, `tests/` n'est pas verrouillé mécaniquement. J'ai remplacé le verrou par une empreinte vérifiée avant commit | risque, pas d'incident | hook |
| C5 | L'implementer n'a pas lancé `npm run build` quand le brief ne le demandait pas ; `implementer.md` ne mentionne ni le frontend ni `wt-php.sh` | 1 build cassé détecté tard | implementer |
| C6 | Horodatages de mon journal estimés au lieu de lus (`date`) : 11 entrées fausses, corrigées | confiance | moi |
| C7 | L'auditor ne peut pas écrire son rapport (pas d'outil d'écriture) alors que le brief le demandait : contenu recopié par moi | faible | brief |

Ce qui a bien marché : arrêts « test faux » des implementers (C3), empreinte avant commit (aucun écart), briefs verts avec contraintes de revue (zéro relance pour duplication), test-skeptic sur Sonnet (4 verdicts utiles pour ~80 k), lots parallèles sur fichiers disjoints sans conflit de fusion.

## Diffs proposés

### test-writer.md (C1, C2, C3)

```diff
 - Lance les tests que tu écris et montre la sortie :
@@
+- Frontend : si tu touches `frontend/tests/`, lance aussi `npm --prefix <racine>/frontend run build` (type-check des specs). Pas de `Array.prototype.at()` (lib TS du projet) ; toute fixture typée existante reçoit les nouveaux champs obligatoires.
+- Avant de rendre la main en phase rouge, cherche les autres tests qui figent la forme ou la valeur touchée : `grep -rn "<clé ou méthode modifiée>" tests frontend/tests` et `grep -rn "Currently.*Issue<N>\|CARACTÉRISATION" tests`. Mets-les à jour dans le même lot et liste-les.
```

### implementer.md (C5)

```diff
 Gates avant de rendre la main, avec la sortie de chaque commande :
+0. Dans un worktree, remplace `docker compose exec app` par `.claude/scripts/wt-php.sh <worktree> unit` ;
@@
 4. `docker compose exec app php vendor/bin/infection --threads=max --filter=<périmètre touché>` : MSI non décroissant.
+5. Si `frontend/` est touché : `npm --prefix frontend run lint`, `run test`, `run build` ; restaurer `frontend/dist` et `frontend/tsconfig.tsbuildinfo` ensuite.
```

### tdd-guard.sh (C4)

Lire la phase dans le dépôt du fichier visé plutôt que dans `CLAUDE_PROJECT_DIR`, pour qu'un worktree en phase verte soit verrouillé sans bloquer les phases rouges des autres worktrees :

```diff
-project_dir="${CLAUDE_PROJECT_DIR:-$(pwd)}"
-phase_file="$project_dir/.claude/state/tdd-phase"
+input="$(cat)"
+target="$(jq -r '.tool_input.file_path // .tool_input.notebook_path // empty' <<< "$input")"
+project_dir="$(git -C "$(dirname "${target:-$PWD}")" rev-parse --show-toplevel 2>/dev/null || echo "${CLAUDE_PROJECT_DIR:-$PWD}")"
+phase_file="$project_dir/.claude/state/tdd-phase"
```
(Le cas Bash reste approximatif : une commande ne nomme pas toujours son dossier. À discuter : exiger `cd <worktree> &&` en tête des commandes d'agent et lire ce chemin.)

### Briefs (C6, C7) — pour moi, pas de diff de fichier
- Toujours horodater avec `date +%H:%M`.
- Ne demander un fichier qu'aux agents qui ont Write (auditor : contenu dans la réponse).
