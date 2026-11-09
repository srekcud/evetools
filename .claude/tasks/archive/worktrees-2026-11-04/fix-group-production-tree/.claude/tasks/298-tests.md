# #298 : préparation des tests (phase tests seuls)

## Vérification des appelants
`grep -rn "countByMember\|findIndustrySkillsForCharacter"` (php, yaml, md, ts, vue, hors vendor) : seules les définitions dans
`src/Repository/GroupIndustryContributionRepository.php:96` et `src/Repository/CachedCharacterSkillRepository.php:27`.
Aucun appelant en `src/`, aucune référence restante en `tests/` ni `frontend/tests/` après ce lot.

## KickGroupMemberProcessorTest
`KickGroupMemberProcessor` a 6 arguments de constructeur (security, projectRepository, memberRepository,
entityManager, accessChecker, mercurePublisher) et ne touche jamais aux contributions : la conservation des
contributions repose sur le soft remove (statut `removed`, pas de `remove()`, la ligne membre reste, donc pas de CASCADE).

- Retiré : 7e argument `$this->contributionRepository`, propriété, mock et import `GroupIndustryContributionRepository`.
- `testOwnerSoftRemovesMemberWithoutContribution`, `testAdminSoftRemovesMemberWithoutContribution` : stub `countByMember(0)` retiré ;
  aucun objet contribution créé, le nom reste exact.
- `testOwnerSoftRemovesMemberWithOneContribution` : stub `countByMember(1)` remplacé par une vraie `GroupIndustryContribution`
  rattachée au membre (Material, quantity 1200, 4 500 000 ISK, approved). Après le kick : même membre, statut, type,
  quantity et valeur inchangés.
- `testAdminSoftRemovesMemberWithContributions` : stub `countByMember(3)` remplacé par trois contributions distinctes
  (Material 350 / 1 750 000 approved, JobInstall 1 / 820 000 pending, Bpc 10 / 2 300 000 rejected). Après le kick :
  chacune garde membre, statut, type, quantity et valeur.
- Helper `contributionOf()` ajouté.
Aucun scénario supprimé, aucune couverture perdue (16 tests, 54 assertions).

## IndustryProjectFactoryTest
Stub `findIndustrySkillsForCharacter` retiré de `skillRepositoryOfTheCharacters()` ; la factory utilise
`findAllSkillsForCharacter`, toujours stubbé.

## Tests qui ne tenaient que par ces méthodes
Aucun. Les stubs étaient inertes (jamais appelés) ; les deux classes passent sans eux.

## Exécution
- `--filter KickGroupMemberProcessorTest` : OK (16 tests, 54 assertions).
- `--filter 'KickGroupMemberProcessorTest|IndustryProjectFactoryTest'` : OK (69 tests, 146 assertions) ; 53 PHPUnit Notices
  préexistantes dans IndustryProjectFactoryTest (mocks sans attente IndustryTreeService/IndustryBlacklistService), hors périmètre.
- Suite Unit : OK, 14504 tests, 25710 assertions, 1 Incomplete préexistant (InstallCostTest D11a).

## Pour l'implementer
Supprimer les deux méthodes de `src/Repository/`, puis PHPStan et suite verte. Retirer l'import `GroupIndustryProjectMember`
de `GroupIndustryContributionRepository` s'il devient inutilisé (à vérifier).
