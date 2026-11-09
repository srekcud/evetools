# #298 vert : suppression de code mort

- Supprimé `GroupIndustryContributionRepository::countByMember` et `CachedCharacterSkillRepository::findIndustrySkillsForCharacter`.
- Imports : `Character` et `GroupIndustryProjectMember` restent utilisés par d'autres méthodes, rien à retirer.
- `CachedCharacterSkill::INDUSTRY_SKILL_IDS` reste utilisée (SyncCharacterSkillsProcessor, TimePerRunCalculator).
- Docs : `grep` dans docs/, CLAUDE.md, .claude/skills, .claude/agents : aucune mention, rien à mettre à jour.
- Unit : 14504 tests OK (296 notices PHPUnit, 1 notice, 1 incomplete, non liés).
- phpstan sur les deux fichiers : aucune erreur.
- deptrac : 0 violation, 41 ignorées (skip_violations existantes).
