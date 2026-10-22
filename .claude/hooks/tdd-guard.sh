#!/usr/bin/env bash
# Bloque toute modification de tests/ pendant la phase verte du TDD (ADR-0004).
# Couvre les outils d'édition (Edit, Write, MultiEdit, NotebookEdit) et Bash,
# pour fermer le contournement par sed, heredoc, cp ou mv.
# La phase est lue dans le dépôt visé (dépôt principal ou worktree evetools-wt-*),
# pour verrouiller un worktree en phase verte sans bloquer les phases rouges des autres.
set -euo pipefail

input="$(cat)"
tool_name="$(jq -r '.tool_name // empty' <<< "$input")"
command="$(jq -r '.tool_input.command // empty' <<< "$input")"
file_path="$(jq -r '.tool_input.file_path // .tool_input.notebook_path // empty' <<< "$input")"

resolve_project_dir() {
  local worktree
  if [[ "$tool_name" == "Bash" ]]; then
    worktree="$(grep -oE '/[^[:space:]]*/evetools-wt-[[:alnum:]]+' <<< "$command" | head -n1 || true)"
    if [[ -n "$worktree" ]]; then
      echo "$worktree"
      return
    fi
  elif [[ "$file_path" == /* ]]; then
    git -C "$(dirname "$file_path")" rev-parse --show-toplevel 2>/dev/null && return
  fi
  echo "${CLAUDE_PROJECT_DIR:-$(pwd)}"
}

project_dir="$(resolve_project_dir)"
phase_file="$project_dir/.claude/state/tdd-phase"

if [[ ! -f "$phase_file" || "$(tr -d '[:space:]' < "$phase_file")" != "green" ]]; then
  exit 0
fi

block() {
  echo "Phase verte du TDD : $1 interdit. Corrige le code, pas le test. Si le test est faux, arrête-toi et signale-le." >&2
  exit 2
}

if [[ "$tool_name" == "Bash" ]]; then
  # Lancer les tests ou Infection reste autorisé, même avec un chemin sous tests/.
  if [[ "$command" =~ ^[[:space:]]*(docker\ compose\ exec\ (-T\ )?app|[^[:space:]]*wt-php\.sh\ [^[:space:]]+\ (unit|integration))\ php\ vendor/bin/(phpunit|infection)([[:space:]]|$) \
     || "$command" =~ ^[[:space:]]*[^[:space:]]*wt-php\.sh\ [^[:space:]]+\ (unit|integration)\ vendor/bin/(phpunit|infection)([[:space:]]|$) ]] \
     && [[ ! "$command" =~ [\;\&\|\>\`] && ! "$command" =~ \$\( ]]; then
    exit 0
  fi
  if [[ "$command" =~ (^|[^[:alnum:]_./-]|\./)(frontend/)?tests/ || "$command" == *"$project_dir/tests/"* || "$command" == *"$project_dir/frontend/tests/"* ]]; then
    block "une commande Bash qui touche tests/"
  fi
  exit 0
fi

case "$file_path" in
  "$project_dir"/tests/*|"$project_dir"/frontend/tests/*|tests/*|./tests/*|frontend/tests/*)
    block "la modification de $file_path"
    ;;
esac
exit 0
