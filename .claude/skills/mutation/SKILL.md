---
name: mutation
description: Lance Infection sur un périmètre, analyse les mutants survivants et propose les tests qui les tueraient ; applique le cliquet de MSI.
disable-model-invocation: true
argument-hint: <périmètre> (ex. src/Service/Industry)
---

Périmètre : $ARGUMENTS

1. Lis le MSI de référence du périmètre dans `docs/quality/baseline.md` et le seuil courant dans `infection.json5`, s'il existe.
2. Lance : `docker compose exec app php vendor/bin/infection --threads=max --filter=$ARGUMENTS`. Note le MSI, le covered MSI et le nombre de mutants tués, survivants et non couverts.
3. Délègue l'analyse des mutants survivants à `test-skeptic`. Pour chacun :
   - vrai trou de test ou mutant équivalent ?
   - quel test le tuerait ?
4. Après accord de Sylvain, délègue l'écriture des tests à `test-writer` (`tests/` uniquement), puis relance Infection.
5. **Cliquet** (ADR-0005) : propose de fixer le seuil du périmètre à la valeur atteinte. Le MSI ne doit jamais baisser. Si la valeur est inférieure à la baseline, arrête-toi et explique.
6. Présente un tableau avant / après (MSI, covered MSI, mutants), les tests ajoutés et les mutants équivalents écartés avec leur justification.

Ne modifie jamais `src/`.
