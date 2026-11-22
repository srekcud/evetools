# Spec — Appliquer le decryptor choisi à la ligne d'invention du projet (#1205)

## Contexte

L'onglet Invention (`ProjectInvention.vue`, `GET /industry/projects/{projectId}/invention`) compare les decryptors d'un produit inventé du projet mais ne retient rien : le pied de page dit « Lecture seule : rien n'est enregistré ». La ligne `invention` du BOM (`IndustryProjectService::replaceT2BlueprintCopiesByInventions`) garde `tentatives = ⌈runs à inventer / (runs du BPC de base × probabilité de base)⌉`, sans decryptor, et ne compte aucun decryptor parmi les matériaux.

## Note de câblage (faite par le thread, pas d'agent `architect` : l'exploration a déjà tout localisé)

- Stockage : colonne `invention_decryptor_type_id` (int, nullable) sur `industry_project_products` (le choix est par produit final du projet, comme le comparateur qui n'itère que `$project->getItems()`).
- Calcul : un objet de domaine pur `InventionAttempts` (`App\Industry\Domain`) rend le nombre de tentatives entières à partir de `InventionChance::of(base, 0, 0, 0, $decryptor)` et `InventionOutcome::of($baseRuns, $decryptor)`. Il remplace la formule en ligne du service.
- BOM : `IndustryProjectService` reçoit un `Decryptors` (dernier argument du constructeur, obligatoire ; les 7 tests qui le construisent passent un double qui rend `[]`). `replaceT2BlueprintCopiesByInventions` lit le choix par produit et ajoute la ligne matériau du decryptor.
- Écriture : champ `inventionDecryptors` de `UpdateProjectInput` (PATCH existant `merge-patch`), traité par `UpdateProjectProcessor::changeTheInventionDecryptors` qui délègue à `ProjectInventionDecryptorChoices` (même patron que `ProjectWorkStationChoices` : valide, pose, `buildBom`, restaure et lève `\InvalidArgumentException` si le BOM refuse).
- Lecture : `ProjectInventionComparator::entryOf` ajoute `selectedDecryptorTypeId` au produit.
- Front : `ProjectInvention.vue` (bouton par ligne d'option, badge « utilisé »), `useGroupInventionStore` (`applyDecryptor`), type `ProjectInventionProduct`, i18n FR/EN.

## Règles

- **R1.** `InventionAttempts::needed(Runs $runsToInvent, int $baseRuns, float $baseProbability, ?Decryptor $decryptor): int` = `⌈runsToInvent / (probabilité × runs du BPC inventé)⌉` avec probabilité = `InventionChance::of($baseProbability, 0, 0, 0, $decryptor)->value` (plafonnée à 1) et runs = `InventionOutcome::of(new Runs($baseRuns), $decryptor)->runs->value`. Sans decryptor, le résultat est identique à la formule actuelle. Les compétences de l'inventeur ne sont pas prises en compte, comme aujourd'hui (le BOM suppose les niveaux 0).
- **R2.** `IndustryProjectProduct` gagne `inventionDecryptorTypeId: ?int` (getter/setter). Défaut null = pas de decryptor. `IndustryProject::inventionDecryptorsByProductTypeId(): array<int,int>` rend les choix non nuls par type de produit.
- **R3.** Dans le BOM, pour chaque produit inventé dont le projet a un choix non nul : tentatives par R1 avec ce decryptor ; la copie T1 (`copy:invention`) suit les tentatives, comme aujourd'hui ; une ligne matériau (non job) du decryptor avec une quantité de **1 par tentative** s'ajoute aux datacores (cumul si le même type est déjà dans la liste). Nom du type lu du SDE (`sdeTypeNames`). Sans choix, aucune ligne decryptor et tentatives inchangées.
- **R4.** Le choix ne joue que si l'invention est dans le plan : projet avec `excludesInvention`, produit couvert par des copies déclarées (`runsToInvent` = 0) ou produit sans invention : le choix reste stocké, aucun effet sur le BOM, aucune ligne decryptor.
- **R5.** `PATCH /industry/projects/{id}` accepte `inventionDecryptors: array<string, int|null>` (clé = type id du produit final, valeur = type id du decryptor ou null pour retirer le choix ; au plus 100 entrées ; valeurs entières positives). Réservé au propriétaire et aux admins du projet (`assertAdminOrOwner` existant). Projet terminé : 400 « The invention decryptor of a completed project cannot be changed ».
- **R6.** Validation (422, rien n'est modifié) : la clé n'est pas un produit final du projet ; le produit n'a pas d'invention (`InventionCatalog::inventionOf` null) ; la valeur n'est pas un decryptor du SDE (`Decryptors::all()`). Un choix identique à l'existant est un no-op (pas de `buildBom`).
- **R7.** Après changement : `buildBom($project, $owner, refuseToDropWorkedLines: statut ≠ brouillon)`. Si le BOM refuse (une ligne déjà travaillée disparaîtrait, trop de lignes), les choix précédents sont restaurés et la réponse est 422 avec le message du BOM.
- **R8.** `GET .../invention` : chaque produit gagne `selectedDecryptorTypeId: int|null`. Les options et leurs montants ne changent pas.
- **R9.** Front : sur chaque ligne d'option du tableau, un bouton « Utiliser ce decryptor » (« Use this decryptor »), la ligne sans decryptor s'appelle « Utiliser sans decryptor » (« Use no decryptor »). La ligne du choix enregistré porte le badge « Utilisé dans le plan » (« Used in the plan ») et son bouton disparaît. Boutons visibles et actifs seulement pour le propriétaire et les admins, et projet non terminé ; sinon le badge seul. Le clic appelle le PATCH, recharge la comparaison et le projet (BOM) ; l'erreur est affichée dans la section (`role="alert"`). Données manquantes : une option au coût inconnu reste choisissable (la quantité ne dépend d'aucun prix, R10 du domaine).
- **R10.** Pied de page FR/EN remplacé : « Le choix du decryptor est enregistré sur le projet : les tentatives et les matériaux de la ligne d'invention du plan le suivent. Probabilité, runs, ME et TE viennent du SDE et des compétences de l'inventeur choisi ; le plan, lui, compte les tentatives sans compétences. » (et l'équivalent anglais).
- **R11.** Aide : `frontend/src/help/{fr,en}/playbooks/t2-project.md` (ligne « Invention and T1 copy ») et `limits/known-limits.md` précisent que le plan suit le decryptor enregistré depuis l'onglet Invention, sans compétences. Glossaire : « Decryptor » mentionne le choix enregistré par produit et la ligne matériau.
- **R12.** Migration réversible `Version20261122220000` : `ALTER TABLE industry_project_products ADD invention_decryptor_type_id INT DEFAULT NULL` ; `down` supprime la colonne. Aucune donnée à transformer.

## Exemples chiffrés

Recette : BPC inventé de base 10 runs, probabilité de base 0,34, 2 datacores par tentative. Produit à 200 runs.

| # | Choix | Probabilité | Runs du BPC | Tentatives | Lignes matériau en plus |
|---|---|---|---|---|---|
| E1 | aucun | 0,34 | 10 | ⌈200 / 3,4⌉ = 59 | aucune |
| E2 | Attainment (×1,8, +4 runs) | 0,612 | 14 | ⌈200 / 8,568⌉ = 24 | Attainment × 24, datacores 2 × 24 |
| E3 | Augmentation (×0,6, +9 runs) | 0,204 | 19 | ⌈200 / 3,876⌉ = 52 | Augmentation × 52 |
| E4 | Optimized Attainment (×1,9) avec base 0,6 | min(1, 1,14) = 1 | 12 | ⌈200 / 12⌉ = 17 | decryptor × 17 |
| E5 | retrait du choix (null) | 0,34 | 10 | 59 | la ligne decryptor disparaît |

## Cas limites

- Deux produits inventés avec le même decryptor : une seule ligne matériau, quantités additionnées.
- Decryptor déjà présent comme matériau d'une autre source : quantités additionnées (même règle que les datacores).
- Projet en cours avec une contribution sur la ligne decryptor : le retrait du choix est refusé (R7).
- Probabilité de base absente du SDE : le comportement actuel (`InventionProbabilityMissingException`) ne change pas, avant tout calcul de decryptor.
- Un projet créé depuis un modèle : les modèles ne portent pas le choix (hors périmètre).

## Hors périmètre

- Compétences de l'inventeur dans le BOM ; ME/TE du BPC inventé reportés sur les produits du projet (les ME/TE déclarés restent ceux du projet) ; choix pour un produit inventé qui n'est pas un produit final du projet ; modèles de projet ; compagnon.

## Décisions prises

- Choix par produit final du projet, colonne sur `industry_project_products` (plutôt qu'une table ou un JSON : une valeur par produit, migration minimale).
- Le BOM reste sans compétences : l'écart avec la comparaison (qui en tient compte) est dit dans le pied de page.
- Un decryptor consommé = 1 par tentative (règle du jeu), ligne matériau comme les datacores, achetable comme eux.
- Pas de nouveau topic Mercure : l'action est synchrone, la réponse du PATCH et le rechargement suffisent (comme les autres champs du projet).
