# Plan de création des issues — audit moteur industrie 2026-10-22

Sources : `1-core.md`, `2-legacy.md`, `3-api.md`, `4-group.md`, `5-esi.md`, `7-tests.md`, et le rapport frontend `6-front.md`. Notation `core#N` = item N du fichier.
Format du corps (comme #121) : `## Constat`, `## Preuve` (liste `fichier:ligne`), `## Impact`, `## Test qui le prouverait`, éventuellement `## Lien` (issues liées), puis `_Source : audit du moteur industrie du 2026-10-22._`. En français. Aucun pseudo, aucune mention d'outil ou d'assistant.

## Hors tracker public
- SÉCURITÉ api S1, S2, group SÉCURITÉ → `.claude/tasks/security-private.md`.
- core#1 (suivi `red-queue.md` qui déclare le rang 1 fusionné à tort) et esi#15 (SKILL.md périmé) → signalés à Sylvain.

## Lot A — noyau, tests, dette transverse (agent 1)
A1 core#1 + legacy#21 + tests#18 + group#6 : « Noyau Industry : aucun écran ne l'utilise et deptrac interdit d'y déléguer » [tech-debt, industry] — inclut code mort du noyau, double formule sans test d'équivalence, BOM de groupe à migrer. Liens #2 #7 #8 #69.
A2 core#2 [bug, industry]
A3 core#3 [bug, industry] — lien #18
A4 core#4 [bug, tech-debt, industry] — inclut la taxe non saisie = 0 %
A5 core#5 [bug, market, industry]
A6 core#6 [tech-debt, industry]
A7 core#7 + core#12 : « Choix de structure : D3/D3b contradictoires et critère absent pour copie et invention » [investigation, industry]
A8 core#8 [perf, industry]
A9 core#9 + tests#19 : MSI sans cliquet, deptrac non bloquant, pas de CI, goldens dupliqués [tech-debt, industry]
A10 core#10 [docs, industry]
A11 core#11 [tech-debt, industry]
A12 tests#11 + tests#12 : « Noyau : mutants survivants sur la résolution du système et le stock d'intermédiaire » [tech-debt, industry]
A13 tests#3 [tech-debt, industry] — lien #18
A14 tests#4 [tech-debt, industry]
A15 tests#7 + tests#8 + tests#9 + tests#10 : « Tests de caractérisation Industry : étiquetage faux, bugs figés à plusieurs, noms contraires à la spec » [tech-debt, industry]
A16 tests#13 + #14 + #15 + #16 + « autres vrais trous » : « Services Industry : mutants survivants sur valeurs absentes, bornes et boucles » [tech-debt, industry] — liens #8 #119
A17 legacy#19 + tests#5 : JobSplitCalculationTest et IndustryCalculationTest testent une copie [tech-debt, industry] — #24 fermée mais problème intact

## Lot B — services de calcul historiques (agent 2)
B1 legacy#1 + tests#2 + front#22 : Pivot Advisor profit × runs [bug, industry]
B2 legacy#2 [bug, industry] — lien #18
B3 legacy#3 + api#2 [bug, industry]
B4 legacy#4 [bug, industry]
B5 legacy#5 [bug, industry]
B6 legacy#6 [bug, industry] — lien #99
B7 legacy#7 [bug, industry] — liens #105 #7
B8 legacy#8 [bug, industry] — lien #121
B9 legacy#9 [bug, industry]
B10 legacy#10 [bug, ux, industry]
B11 legacy#11 [bug, tech-debt, industry] — lien #7
B12 legacy#12 [bug, tech-debt, industry]
B13 legacy#13 + api#17 : prix des matériaux, trois définitions et trois copies du meilleur prix [tech-debt, market, industry] — liens #8 #51 #50
B14 legacy#14 [bug, industry] — liens #104 #117 #108
B15 legacy#15 + api#18 : unités par run `?? 1` [bug, tech-debt, industry]
B16 legacy#18 + front#19 + api#22 (fonctions de store sans appelant : updateStepStructure, cibles de stockpile) : code mort industrie backend et frontend [tech-debt, industry] — liens #44 #45 #46
B17 legacy#20 [investigation, industry]
B18 esi#8 : `?? 0.0` sur prix ajustés et index dans le legacy [bug, tech-debt, industry] — liens #7 #8

## Lot C — API Platform + ESI (agent 3)
C1 api#1 [bug, industry]
C2 api#3 [bug, industry] — lien #8
C3 api#5 [bug, ux, industry]
C4 api#6 [bug, industry] — lien #58
C5 api#7 [bug, industry] — lien #4
C6 api#8 [bug, industry] — lien #15
C7 api#9 [bug, industry] — lien #2
C8 api#10 [bug, ux, industry] — lien #109
C9 api#11 + front#7 : structure de marché et coût de transport en dur dans la liste d'achats [bug, industry]
C10 api#12 [bug, tech-debt, industry] — lien #44
C11 api#13 + esi#4 : syncs à la demande (match jobs, skills) taisent leurs échecs [bug, industry] — lien #21
C12 api#14 [bug, industry]
C13 api#15 + tests#17 [bug, industry]
C14 api#16 [bug, industry]
C15 api#19 [tech-debt, perf, industry]
C16 api#20 [perf, industry]
C17 api#21 [bug, industry]
C18 api#22 (conventions, formats, remove dans provider, tests/Functional vide) + tests#6 : couche API Industry sans tests HTTP [tech-debt, industry] — liens #44 #46
C19 esi#1 [bug, industry]
C20 esi#2 [bug, industry] — liens #58 #59
C21 esi#3 [bug, industry] — lien #4
C22 esi#5 [bug, industry, corp-industry]
C23 esi#6 [bug, corp-industry] — spec #122
C24 esi#7 [bug, industry] — lien #7
C25 esi#9 [perf, corp-industry]
C26 esi#10 [tech-debt, industry] — lien #7
C27 esi#11 + front#18 : temps réel de la sync des jobs (un cycle par personnage, écrans non abonnés) [bug, ux, industry]
C28 esi#12 [bug, industry]
C29 esi#13 + legacy#16 [bug, perf, market]
C30 esi#14 [bug, industry]

## Lot D — Group Industry + frontend (agent 4)
D1 group#1 [bug, corp-industry]
D2 group#2 [bug, corp-industry]
D3 group#3 [bug, corp-industry]
D4 group#4 [bug, ux, corp-industry]
D5 group#5 [bug, corp-industry] — lien #53
D6 group#7 [bug, market, corp-industry] — lien #8
D7 group#8 + front#17 (totaux partiels, marge ?? 0, avancement) [bug, ux, corp-industry] — lien #8
D8 group#9 [bug, perf, corp-industry]
D9 group#10 [tech-debt, corp-industry]
D10 group#11 [tech-debt, corp-industry] — spec #87
D11 group#12 [bug, corp-industry]
D12 group#13 [perf, corp-industry] — lien #39
D13 group#14 + tests#1 [tech-debt, corp-industry]
D14 group mineurs [tech-debt, corp-industry] — lien #45
D15 front#1 [bug, industry]
D16 front#2 [bug, industry]
D17 front#3 [bug, ux, industry]
D18 front#4 [bug, industry]
D19 front#5 [bug, industry] — lien #18
D20 front#6 [bug, industry]
D21 front#8 [bug, ux, industry] — lien #108
D22 front#9 [bug, industry] — lien #19
D23 front#10 + api#4 : trois totaux de profit différents [bug, industry]
D24 front#11 [bug, tech-debt, industry]
D25 front#12 [bug, ux, industry] — lien #69
D26 front#13 [bug, ux, industry]
D27 front#14 [bug, ux, industry]
D28 front#15 [bug, industry] — lien #18
D29 front#16 [bug, industry] — lien #8
D30 front#20 [ux, industry]
D31 front#21 + textes en dur Group Industry de front#17 : i18n [ux, industry, corp-industry]

## Commentaires sur issues existantes (agent 3, en fin de lot)
- #2 : appelants non cités, `StockpileStartingStockReducer.php:31,69` (runs puis unités dans le même paramètre) et `CreateStepProcessor.php:87,130,186`.
- #44 : `regenerate-steps` et `rig-options` n'existent plus dans `src/ApiResource` (issue en partie périmée) ; `apply-stock`/`adapt-stock` n'ont pas d'appelant de store.
