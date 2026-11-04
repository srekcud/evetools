#!/usr/bin/env bash
set -uo pipefail

readonly hook="$(cd "$(dirname "$0")" && pwd)/tdd-guard.sh"
project_dir="$(mktemp -d)"
trap 'rm -rf "$project_dir"' EXIT
mkdir -p "$project_dir/.claude/state"
echo green > "$project_dir/.claude/state/tdd-phase"
failures=0

run_bash() {
  jq -n --arg command "$1" '{tool_name: "Bash", tool_input: {command: $command}}' \
    | CLAUDE_PROJECT_DIR="$project_dir" "$hook" 2>/dev/null
}

expect() {
  local expected="$1" command="$2" actual
  run_bash "$command"
  actual=$?
  if [[ "$actual" -ne "$expected" ]]; then
    echo "FAIL (attendu $expected, obtenu $actual) : $command"
    failures=$((failures + 1))
  fi
}

expect 0 "grep -rn 'Currently' tests/Unit"
expect 0 "sed -n 1,40p tests/Unit/FooTest.php"
expect 0 "cat tests/Unit/FooTest.php 2>/dev/null | head -5"
expect 0 "git show HEAD:tests/Unit/FooTest.php"
expect 0 "git diff main -- tests/"
expect 0 "git add -A tests/ && git commit -m 'test: x'"
expect 0 "npm --prefix frontend run test -- frontend/tests/unit/foo.spec.ts"
expect 0 "echo '[#1] lu tests/Unit/FooTest.php' >> /tmp/journal.md"
expect 2 "echo x > tests/Unit/FooTest.php"
expect 2 "sed -i 's/a/b/' tests/Unit/FooTest.php"
expect 2 "perl -pi -e 's/a/b/' tests/Unit/FooTest.php"
expect 2 "cp /tmp/x tests/Unit/FooTest.php"
expect 2 "rm tests/Unit/FooTest.php"
expect 2 "cat /tmp/x | tee tests/Unit/FooTest.php"
expect 2 "git checkout main -- tests/Unit/FooTest.php"
expect 2 "git restore tests/"
expect 2 "python3 -c 'open(\"tests/Unit/FooTest.php\",\"w\")'"
expect 2 "printf x >tests/Unit/FooTest.php"
expect 2 "echo x >> \"$project_dir/tests/Unit/FooTest.php\""
expect 2 "find tests/ -name '*.php' -delete"

[[ "$failures" -eq 0 ]] && echo "tdd-guard : tous les cas passent" || exit 1
