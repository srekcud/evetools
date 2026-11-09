# Lot 4 Group Industry : phase verte

Backend : suite Unit complète verte (13155 tests, 0 échec, notices et incomplet inchangés). PHPStan : seul reste le bruit `Uuid|null` connu (`GroupIndustryContainerService::buildResource`). Deptrac : 0 violation.
Frontend : 928 tests verts, lint 0 erreur, build vert (dist et tsbuildinfo restaurés).

## Changements

- `CachedAssetRepository::hasCorporationAssets()` : requête LIMIT 1 sur la corporation.
- `GroupIndustryContainerService` : constructeur à 4 arguments, ordre R4, contenu par descendance (map `locationId` vers assets issue de `VisibleCorporationAssetsFinder`, chaque asset compté une fois), statuts `missing` / `container_not_found` / `unknown` + motif, `containerQuantity` `?int`.
- `GroupContainerVerificationResource::$containerQuantity` en `?int`; commentaire périmé `'verified' | 'partial' | 'unchecked'` supprimé (règle : aucun commentaire).
- `GroupIndustryProjectService::buildBom` : refus du projet terminé avant le plan, plan et prix avant toute modification, lignes du plan réconciliées avec les existantes (clé, rang par runs décroissants, contribution d'abord à égalité), mise à jour sur place, création, suppression des seules lignes sans contribution. `GroupIndustryProject::removeBomItem()` ajouté.
- Frontend : erreurs par ressource dans `distribution.ts` (efface la sienne, vide la ressource en échec, `openProject` efface les trois), `SalesTable`/`MemberTable`/`DistributionTable` branchés (spinners sur le flag de leur ressource), la vue ne rappelle plus `fetchSales` au changement d'onglet, `BomTable` (verified/partial/missing en i18n), `ContainerBanner` (container introuvable / non vérifiable + motif), clés en.json et fr.json (`unchecked` retiré).
- Glossaire : « Régénération de la BOM », « Vérification du container ».

## Point à relire

Les specs `groupDistributionErrors.spec.ts` (2 tests) et `distributionTablesErrors.spec.ts` (1 test) créent `recording = store.recordSale(...)`, font échouer la requête puis `await flushPromises()` avant `expect(recording).rejects` : la promesse rejetée n'a pas de gestionnaire à ce moment. Vitest signale 3 « Unhandled Rejection » et sort en code 1 alors que les 928 tests passent. Aucune implémentation ne peut l'éviter (`recordSale` doit rejeter, le test l'exige). Correction côté test : attacher `expect(recording).rejects...` avant `await fail(...)`.
