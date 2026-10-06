#!/usr/bin/env bash
# Bloque toute modification de tests/ pendant la phase verte du TDD (ADR-0004).
# Couvre les outils d'édition (Edit, Write, MultiEdit, NotebookEdit) et Bash,
# pour fermer le contournement par sed, heredoc, cp ou mv.
set -euo pipefail

project_dir="${CLAUDE_PROJECT_DIR:-$(pwd)}"
phase_file="$project_dir/.claude/state/tdd-phase"

if [[ ! -f "$phase_file" || "$(tr -d '[:space:]' < "$phase_file")" != "green" ]]; then
  exit 0
fi

input="$(cat)"
tool_name="$(jq -r '.tool_name // empty' <<< "$input")"

block() {
  echo "Phase verte du TDD : $1 interdit. Corrige le code, pas le test. Si le test est faux, arrête-toi et signale-le." >&2
  exit 2
}

if [[ "$tool_name" == "Bash" ]]; then
  command="$(jq -r '.tool_input.command // empty' <<< "$input")"
  # Lancer les tests ou Infection reste autorisé, même avec un chemin sous tests/.
  if [[ "$command" =~ ^[[:space:]]*docker\ compose\ exec\ (-T\ )?app\ php\ vendor/bin/(phpunit|infection)([[:space:]]|$) ]] \
     && [[ ! "$command" =~ [\;\&\|\>\`] && ! "$command" =~ \$\( ]]; then
    exit 0
  fi
  if [[ "$command" =~ (^|[^[:alnum:]_./-]|\./)tests/ || "$command" == *"$project_dir/tests/"* ]]; then
    block "une commande Bash qui touche tests/"
  fi
  exit 0
fi

file_path="$(jq -r '.tool_input.file_path // .tool_input.notebook_path // empty' <<< "$input")"
case "$file_path" in
  "$project_dir"/tests/*|tests/*|./tests/*)
    block "la modification de $file_path"
    ;;
esac
exit 0
