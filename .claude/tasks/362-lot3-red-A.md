# #362 lot 3, sous-lot A, phase rouge

## Contrat que le vert doit respecter (non deductible de la spec)
- Libelles anglais exacts : `Publish`, `Start` (boutons de sortie de draft), `Match jobs` (bouton JobsTable), badge `Private` (span de texte exact, ProjectCard et en-tete du detail). FR : Publier, Demarrer, Associer les jobs, Prive.
- data-testid : `project-private` (case NewProjectModal, cochee par defaut), `edit-project-private` (case de la modale de modification), `matched-runs`, `job-match` (un par jobMatch), `purchased-quantity` (BomTable, absent si le champ est absent).
- JobsTable garde 7 colonnes (jobsTableUnknownCost lit td[6]) : matchedRuns et jobs lies vont dans les cellules existantes.
- JobsTable lit le proprietaire dans `useGroupProjectStore().currentProject` (`myRole === 'owner'`, `id`), pas de prop. Admin et membre : pas de bouton.
- match-jobs : POST `/industry/projects/{id}/match-jobs` body `'{}'`, puis un GET `/industry/projects/{id}/bom`; texte de `failedCharacters` : characterName et reason visibles.
- `nextProjectStatuses(status)` : draft [published, in_progress], published [in_progress], in_progress [selling], selling [completed], completed [].
- Types : GroupProject +private, +notes (string|null), +acceptsOutsideFavoriteSystem, +minimumOutsideSaving ; BomItem +jobMatches, +matchedRuns, +purchases, +purchasedQuantity ; type JobMatch aligne sur ProjectJobMatchResource. Fixtures existantes deja mises a jour avec ces champs.
- PATCH du statut depuis le detail : corps exactement `{status: 'in_progress'}`.

## Fichiers
Mis a jour (URL `/group-industry/...` vers `/industry/...`, assertions inchangees) : groupDistribution{AnotherProject,Errors,SaleCorrection,SaleSuggestions}, groupIndustryDetail{UnknownSellerFees,SalesFetch,SaleSuggestions}, groupIndustryListJoin, contributeModal{JobInstallValue,UnknownMaterialPrice}, distributionTablesErrors, salesTable{Seller,WalletLink}, saleSuggestionsPanel, newProjectModalBlacklist.
- newProjectModalBlacklist : le corps attendu gagne `private: true` ; le test des dix categories exclut la case privee.
- nextProjectStatus.spec : reecrit sur nextProjectStatuses.
- Fixtures typees completees (private/notes/acceptsOutside.../minimumOutsideSaving ; jobMatches/matchedRuns/purchases/purchasedQuantity) : 13 fichiers dont a11y/iconButtonsHaveName.

## Non touches (sous-lot B)
- tests/layouts/mainLayoutHarness.ts : stub fetch `/api/group-industry/projects` -> `[]` et `/api/industry/projects` -> `{projects: []}`. A B de le passer a `/api/industry/projects` -> `[]` quand l'onglet solo disparait.
- tests/components/common/commandPaletteHarness.ts : fixture `GroupProject` typee, a completer par B (erreur TS tant que les types ne changent pas, puis champs obligatoires manquants).
