# #202 Q1 - phase verte

- `IndustryProject::refusalToDeleteStep($step, \Closure $materialTypeIdsOf)` : O1 apres R3 ; etapes de materiau = etapes de production restantes dont le produit est un materiau de l'etape supprimee ; refus si aucune autre etape de production ne le consomme. Copie jamais refusee.
- Nouveau `App\Service\Industry\StepMaterialTypeIdsReader` : lit `findMaterialEntitiesForBlueprints()` une fois pour les cles de blueprint manquantes du projet, memo par cle (`blueprintKeyOf`). Injecte dans `DeleteStepProcessor` et `IndustryResourceMapper` (`deletable`).
- Docs : carte du module (ligne suppression), glossaire (Etape orpheline, Etape supprimable).
- Validation : Unit 14 422 tests verts (notices et InstallCostTest D11a preexistants), phpstan 4 fichiers sans erreur, deptrac 0 violation.
