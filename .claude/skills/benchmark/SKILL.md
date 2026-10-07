---
name: benchmark
description: Compare un module d'evetools aux outils de la communauté EVE et en tire les meilleures idées, classées par valeur et effort, sous forme de propositions d'issues.
disable-model-invocation: true
argument-hint: <module ou question> (ex. industry, market, « suivi PI »)
---

Sujet : $ARGUMENTS

1. Délègue à l'agent `product-analyst`, avec :
   - le sujet et les questions précises de Sylvain s'il y en a ;
   - les outils à comparer en priorité (par défaut, ceux qui couvrent le sujet parmi Ravworks, EVE Forge, Fuzzwork, Janice, Adam4EVE, jEveAssets, EVE Tycoon) ;
   - les issues et benchmarks déjà existants (`.claude/tasks/benchmarks/`, `.claude/tasks/industry-core/`) pour ne pas refaire le travail.
2. Vérifie le rapport :
   - chaque idée retenue a une source, et chaque « déjà fait » un `fichier:ligne` ;
   - aucune idée déjà couverte par une issue ouverte n'est reproposée comme nouvelle ;
   - aucun code AGPL/GPL n'est repris ;
   - aucun nom de joueur ou de corpo, aucune attribution à une IA.
3. Présente à Sylvain le top 5, les propositions d'issues et les questions. Ne crée les issues qu'après son accord, et ne commite rien.
