# Spec #609 : onglet Invention du projet (comparaison des décrypteurs)

Maquette retenue : `industry-project-v3.html` page 24 (« Invention · choix du décrypteur »). Mode nuit : spec validée en autonomie. Recommandation de l'issue retenue par Sylvain (lancement explicite du 2026-11-11) : lecture seule, rien de persisté, plan du projet inchangé (décision du 2026-11-11 sur #338 : le prévu reste recalculé à la lecture).

## Contexte vérifié sur origin/main

- Comparaison existante par type de produit : `ProfitMarginService::analyze` (#344, #383) rend `invention.options` (une option sans décrypteur puis une par décrypteur : `me`, `te`, `runs`, `probability`, `expectedAttempts`, `manufacturingJobs`, `decryptorCost`, `inventionCost`, `totalProductionCost`, `costPerUnit`, `costPerUnitDelta`), `baseProbability`, `unknownReason`, `datacores`, `datacoresCostPerAttempt`, `inventor`. Le coût passe par `ProductionCostCalculator`, la probabilité par `InventorSkills::levelsFor`.
- Produits d'un projet : `IndustryProject::getItems()` (`IndustryProjectProduct` : `typeId`, `typeName`, `runs`, `meLevel`, `teLevel`). Un produit est T2 quand `InventionCatalog::inventionOf($typeId)` n'est pas nul.
- Coûts, structures et système favori d'un projet sont ceux du propriétaire (`ProjectResearchProvider`, `ProjectStructuresProvider`) ; l'accès est `ProjectAccessChecker::assertAcceptedMember`.
- Onglet : `COMING_SOON_TABS` (`projectDetailTabs.ts`) vaut `['invention']`, `ProjectTabBar.vue` l'affiche désactivé avec « À venir ».

## Règles

- **R1 — Une comparaison par produit T2.** `ProjectInventionComparator::compare(project, inventor)` rend une entrée par produit du projet dont `InventionCatalog::inventionOf` n'est pas nul, dans l'ordre du projet. Un produit non T2 est ignoré et n'appelle jamais `analyze`.
- **R2 — Mêmes chiffres que l'analyse de marge.** L'entrée appelle `ProfitMarginService::analyze` avec le `typeId`, les `runs`, le ME et le TE du produit du projet, le propriétaire du projet comme utilisateur (ses structures, son système favori, son blacklist utilisateur), l'inventeur choisi, sans frais de vente (aucune marge n'est affichée). Aucune sélection de décrypteur persistée : `decryptorTypeId` toujours nul.
- **R3 — Champs rendus.** Par produit : `typeId`, `typeName`, `runs`, `me`, `te`, et `invention` limité à `baseProbability`, `unknownReason`, `datacores`, `datacoresCostPerAttempt`, `inventor`, `options`. Chaque option garde les champs de R-contexte sans `bestMargin`. Les valeurs inconnues restent `null` (probabilité, tentatives, coûts) : jamais 0.
- **R4 — Inventeur.** Paramètre de requête optionnel `inventorCharacterId` (UUID d'un personnage **de l'utilisateur connecté**, pas du propriétaire) : UUID invalide ou personnage d'un autre utilisateur = 404 « Character not found ». Sans inventeur, la probabilité est celle de base (`unknownReason: missing_inventor_skills` si le personnage n'a pas de compétences en cache, déjà géré par `analyze`).
- **R5 — Ressource.** `GET /api/industry/projects/{projectId}/invention` (`ProjectInventionResource`, `skip_null_values: false`, `uriVariables` avec `Link`), `ProjectInventionProvider`, projet inconnu ou UUID invalide 404 « Project not found » avant tout, `assertAcceptedMember` (403 sinon, 401 sans session). Champs : `id` (le projet), `products` (liste, vide si aucun produit T2). Lecture seule : aucune écriture, aucun message, donc aucun topic Mercure.
- **R6 — Écran.** Onglet « Invention » actif (`DETAIL_TABS`, plus de « À venir » ; `COMING_SOON_TABS` devient vide, la constante reste). `ProjectInvention.vue` + store `group-industry/invention.ts` (`fetchInvention(projectId, inventorCharacterId)`). Par produit : titre, puces « N unités », « ME x · TE y » du projet, probabilité de base, datacores, coût des datacores par tentative, sélecteur d'inventeur (« Sans compétences » + personnages de l'utilisateur, le principal par défaut, un changement relance la requête), tableau des options : décrypteur, probabilité, runs par BPC, ME/TE du BPC, tentatives, jobs, invention, coût total de production, coût par unité, écart. Le moins cher à coût par unité connu porte « Le moins cher ». Inconnu : libellé « inconnu » (clé `industry.inventionUnknown.label`) et motif `industry.inventionUnknown.<unknownReason>`, jamais 0.
- **R7 — Vide et erreur.** Projet sans produit T2 : une phrase explicative, pas de tableau. Chargement et erreur de requête affichés (`role="alert"`), jamais de tableau périmé.

## Exemples chiffrés (garde de non-régression)

| Cas | Attendu |
|---|---|
| Projet 50 × Hammerhead II (ME 2, TE 4), inventeur principal | 1 entrée `runs 50`, `me 2`, `te 4`, 5 options (aucun décrypteur + 4) lues de `analyze` |
| Projet Ishtar T2 + Tritanium (matériau) | 1 entrée, les matériaux sont ignorés |
| Projet d'un seul produit T1 | `products: []`, `analyze` jamais appelé |
| Probabilité inconnue (compétences non synchronisées) | `probability: null`, `expectedAttempts: null`, `unknownReason: missing_inventor_skills` |

## Hors périmètre

Choix persisté d'un décrypteur, inventeur et décrypteur dans la BOM, produits intermédiaires T2 (seuls les produits finaux), frais de vente et marges, blacklist du projet (le calcul suit l'analyse de marge), personnages des autres participants, modification de `ProfitMarginService`, retrait d'un réglage ou d'un écran existant.

## Décisions prises en autonomie

- Réutiliser `ProfitMarginService::analyze` plutôt que dupliquer la construction des options : le surcoût est la lecture du carnet de vente par produit, acceptable pour un projet à un ou quelques produits finaux.
- `COMING_SOON_TABS` reste exporté, vide : aucune constante retirée sans accord.
