# Décisions à trancher avant la mise en prod (2026-11-07)

Document de décision unique. Réponse attendue sous la forme « ok sur tout sauf #X (option b), #Y (…) ». Chaque recommandation est une proposition, pas une décision prise.

Sources : issues GitHub lues le 2026-11-07, journaux `loop-2026-11-06-night.md` et `loop-2026-11-07-night.md`, grep sur `origin/main` (tête `42a0505a`). Un fait non vérifié est marqué `[À CONFIRMER : …]`.

Vocabulaire : « capitale » = structure de marché préférée de l'utilisateur (glossaire, `Marché de vente`). « Le noyau » = `src/Industry/` (plan, `ProductionCostCalculator`).

## Tableau récapitulatif

### A. Décisions bloquantes (17)

| Issue | Question | Recommandation |
|---|---|---|
| #254 | Supprimer `IndustryBonusService` et `IndustryTreeService` (code mort) ? | Supprimer avec leurs tests, #254 devient caduque |
| #6 | Issue « trois règles de bonus de structure » : fermer comme caduque ? | Fermer (caduque, #362 a supprimé les deux autres chemins) |
| #384 reliquat | Supprimer `InventionService::calculateInventionCost`, `buildDecryptorOptions` (aucun appelant) ? | Supprimer, une issue de nettoyage |
| #334 | Quelle région pour les contrats de la capitale ? | Région de la structure de marché préférée, dérivée de `CachedStructure` |
| #349 | Alerte sous le seuil : attendre quoi ? | Livrer bandeau cockpit + ligne de file après le seuil de #346, compagnon exclu |
| #352 | Surplus vers le Stockpile : que livrer ? | Surplus de matières affiché en lecture seule, sans écriture Stockpile |
| #354 | Projet de référence : périmètre ? | Mono-produit, projets de l'utilisateur, sans retard ni doctrine |
| #342 | Santé des structures : seuil, personnage lecteur, fraîcheur | Lecture ESI à l'ouverture de l'onglet, seuil à fixer par toi |
| #394 | Garder `materialCost` et `jobInstallCost` des options d'invention ? | Retirer |
| #421 | Récompense du courrier : poste de coût ou apport dérivé ? | Apport dérivé du propriétaire |
| #248 | PostgreSQL 18 : quand ? | Confirmer le report après le big bang |
| #299 | Champs jamais lus : supprimer les colonnes ? | Confirmer le report après le big bang |
| #302 | Commandes console orphelines : trier ? | Confirmer le report après le big bang |
| #313 | Répartition des mineurs (exploratoire) | Fermer, rouvrir si #267 est livré |
| #372 | Suppression d'un scan d'un autre participant | Auteur, propriétaire et admins ; masquer sans recalculer |
| #410 | Recherche ME/TE proposée seulement si rentable | Seuil simple (A), horizon = runs du projet |
| #419 | Batch scan en timeout sur une structure | Option B (`StructureTradedVolumeService`) |

### B. Questions laissées par des threads (6)

| Issue | Question | Recommandation |
|---|---|---|
| #353 | Masquer la proposition si un projet produit déjà la cible ? | Oui, nouvelle issue ciblée |
| #360 | Profit industrie net ajouté à des revenus PVE/minage bruts ? | Garder l'addition, afficher le libellé « net » |
| #256 | Marges inconnues affichées malgré un minimum ? | Oui, ligne signalée « marge inconnue » |
| #355 | Structures d'un modèle = politique seulement ? | Oui, confirmer |
| #416 | Garder la normalisation défensive du store `chain.ts` ? | Garder |
| #361 | Calcul serveur des virements + référence de projet ? | Reporter, rattaché à #324 |

### C. Reliquats d'issues partiellement livrées (7)

| Issue | Reste | Recommandation |
|---|---|---|
| #335 | Avatars de l'équipe | Fermer, issue ciblée si voulu |
| #336 | Onglets Invention et Journal | Garder ouverte, dépend de #321 |
| #338 | Bonus de structure, plan figé, persos des autres | Nouvelle issue (bonus de structure) ; le reste en question |
| #341 | Bouton « Autoriser pour ce projet », santé des structures | Fermer, bouton en issue ciblée, santé = #342 |
| #343 | Bouton Acheter / Fabriquer (A ou B) | Option A |
| #345 | Coût par unité, valeur du surplus | Fermer, fusionner dans #343 |
| #346 | Seuil de rentabilité, usage personnel | Seuil A, mono-produit ; usage personnel B |

### D. Group Industry face au module unifié (14)

| Issue | Verdict | Recommandation |
|---|---|---|
| #318 | À réécrire | Réécrire sur les apports dérivés |
| #319 | Valide | Garder |
| #320 | À réécrire | Réécrire sur la ligne du plan |
| #321 | Valide | Garder (débloque l'onglet Journal) |
| #322 | Valide, gros | Reporter après la mise en prod |
| #323 | Valide | Garder |
| #324 | Valide en partie | Réduire aux virements |
| #325 | Valide | Garder |
| #327 | Valide | Garder |
| #328 | Valide | Garder |
| #329 | Valide en partie | Garder, recadrer |
| #330 | Valide | Garder |
| #339 | Valide | Garder |
| #340 | À réécrire | Réécrire après #318 |

### E. Investigations (3)

| Issue | État | Recommandation |
|---|---|---|
| #139 | Décision prise le 2026-10-22, à implémenter | Retirer le label `investigation` |
| #219 | Vérifié en jeu le 2026-11-05 | Lever le `markTestIncomplete`, fermer |
| #276 | Vérifié en jeu le 2026-11-05 | Retirer le label, passer en spec |

Aucune vérification en jeu ne reste à faire pour les trois.

---

## A. Décisions bloquantes

### #254 Structure ou sécurité inconnue remplacée par un bonus plausible (IndustryBonusService)

**Contexte.** `IndustryBonusService` n'a aucun appelant dans `src/`, `config/`, `templates/`, `frontend/src` (grep sur origin/main). Son seul consommateur, `IndustryTreeService`, n'en a pas non plus. Le noyau lève déjà `UnexpectedValueException` sur un type de structure ou une sécurité inconnus (commentaire de la boucle du 2026-11-07, `ConfiguredProductionStructures`). Tests concernés : `IndustryBonusServiceTest`, `IndustryTreeServiceTest`, `StackedRigsMaterialQuantityTest`, `TataraAndRaitaruBonusService`, `RigOptionsProviderTest`, `IndustryStructureConfigTest`, plus deux fixtures Golden qui citent `IndustryTreeService`. `[À CONFIRMER : lesquels de ces tests dépendent réellement des deux classes]`.

**Options.**
- a) Supprimer les deux services et leurs tests (et la mention dans `SdeBlueprintCatalog`).
- b) Durcir le code mort (erreur explicite sur type et sécurité inconnus).

**Recommandation : a.** Le durcissement n'a aucun effet visible, la migration vers le noyau est déjà faite.

**Conséquence boucle.** Ferme #254 et #6 (voir ci-dessous) ; le thread supprime les classes en circuit m, niveau 3.

### #6 Trois règles différentes pour le bonus de structure (arbre, étapes, liste d'achats)

**Contexte.** Le thread du 2026-11-07 (22:00) a constaté sur origin/main que `IndustryProjectFactory` et `IndustryShoppingListBuilder` n'existent plus (retirés par #362) et que le plan passe par une seule règle (`StructureMaterialModifiers::choiceFor` vers `StructureSelector::select`). Il propose de fermer.

**Options.**
- a) Fermer comme caduque.
- b) Garder ouverte pour contrôler qu'une seule règle reste (test).

**Recommandation : a.** Il ne reste qu'un chemin de calcul.

**Conséquence boucle.** Issue à fermer ; liée à la suppression décidée dans #254.

### #384 (fermée) reliquat : code mort d'invention

**Contexte.** #384 a été fermée comme caduque (not planned). Le thread a noté que `InventionService::DECRYPTORS`, `calculateInventionCost` et `buildDecryptorOptions` n'ont plus d'appelant (seul `buildDecryptorOptions` appelle `calculateInventionCost`). Vérifié : sur origin/main les deux méthodes ne sont référencées que dans `InventionService.php`. `DECRYPTORS` : `[À CONFIRMER : encore lu par ProfitMarginService ?]`.

**Options.**
- a) Supprimer ces méthodes et leurs tests, dans une issue de nettoyage.
- b) Les garder.

**Recommandation : a.** Même logique que #254 : du code sans appelant garde des tests qui figent un comportement inutilisé.

**Conséquence boucle.** Nouvelle issue de nettoyage (circuit s, `group:cleanup`).

### #334 Quoi produire : estimer les ventes par contrat

**Contexte.** Commentaire de Sylvain du 2026-11-03 : version minimale, seuls les contrats publics de la capitale, région « à confirmer », 90 jours, enchères ignorées. `SyncPublicContractsHandler` ne relève que The Forge (`EveConstants::THE_FORGE_REGION_ID`). Le code sait déjà résoudre la région d'une structure : `BatchProfitScannerService::resolveStructureRegionId` (`CachedStructure` vers `MapSolarSystem::getRegionId`). La capitale étant la structure de marché préférée de chaque utilisateur, la région peut différer d'un utilisateur à l'autre. Question restante de l'issue : les contrats en citadelle nullsec remontent-ils tous dans l'endpoint public ? `[À CONFIRMER]`.

**Options.**
- a) Région dérivée de la structure de marché préférée de chaque utilisateur ; l'historique est relevé pour l'ensemble des régions distinctes en usage.
- b) Une seule région fixe choisie par toi `[À CONFIRMER : laquelle]`, relevée en plus de The Forge.
- c) The Forge seule (n'aide pas pour les capitaux, que l'issue vise).

**Recommandation : a.** Aucune région à coder en dur, cohérent avec « capitale » du glossaire ; coût d'ESI borné par le nombre de régions distinctes.

**Conséquence boucle.** Débloque #334 (circuit m), à lancer sans autre question si a ; le point citadelle nullsec reste une limite documentée.

### #349 Alerte quand Jita passe sous le seuil de rentabilité

**Contexte.** Le commentaire du 2026-11-06 (3 prérequis absents) est en partie périmé. Sur origin/main : la file d'actions existe (`/industry/actions`, `IndustryActionResource`, PR #401 fusionnée). Le seuil de rentabilité n'existe toujours pas (`grep breakEven` vide dans `src` et `frontend/src`), il est dans #346 (PR #399 partielle). Aucun compagnon dans le code (#322 ouverte).

**Options.**
- a) Attendre #346 (seuil), puis livrer bandeau cockpit et ligne de file ; l'alerte compagnon reste dans #322.
- b) Tout attendre, compagnon compris.

**Recommandation : a.** Le compagnon est un chantier circuit l indépendant que #349 ne doit pas bloquer.

**Conséquence boucle.** #349 passe après #346 (lot seuil) ; retirer « compagnon » du périmètre de #349 ; débloquée dès que #346 est tranchée.

### #352 Surplus du projet versé au Stockpile

**Contexte.** Commentaire du 2026-11-06 : aucun résultat d'invention n'est enregistré (pas de BPC en trop) ; aucun calcul de surplus de projet hors noyau ; `IndustryStockpileTarget` est une cible (utilisateur + type unique, 4 niveaux), le stock vient des assets, la valeur est calculée au prix Jita. La règle « sans fausser le profit » contre le bouton « Le déduire du coût » n'est pas tranchée.

**Options.**
- a) Surplus de matières (apporté moins requis par ligne de BOM) affiché sur un projet terminé, avec un bouton qui crée ou incrémente une cible (proposition de la boucle, circuit m).
- b) Surplus de matières affiché en lecture seule, sans écriture Stockpile ; BPC et « Le déduire du coût » reportés.
- c) Attendre une issue qui enregistre les résultats d'invention, puis tout livrer.

**Recommandation : b.** Le stock réel est déjà dans les assets ; incrémenter une cible modifie un objectif de l'utilisateur, pas un stock `[À CONFIRMER : c'est bien le sens voulu par « versé au Stockpile »]`.

**Conséquence boucle.** Débloque #352 réduit (circuit m) ; BPC et déduction du coût en issue séparée, après enregistrement des inventions.

### #354 Projet de référence à la création

**Contexte.** Trois prérequis absents (commentaire du 2026-11-06) : pas de durée prévue donc pas de « retard » (seul `completedAt` existe) ; aucun import EFT ni doctrine dans le code ; sur un projet multi-produits le coût réel par produit n'est pas défini (un apport se rattache à une ligne de BOM). Marge = `ProjectDistributionService::calculateDistribution` (`marginPercent`, `null` si frais ou coûts inconnus).

**Options.**
- a) Périmètre défini seul : référence par produit, projets `completed` de moins d'un mois, mono-produit seulement (`null` sinon), projets dont l'utilisateur est propriétaire ou participant accepté ; affichage dans `NewProjectModal` ; retard et doctrine en issues séparées.
- b) Attendre Planning et doctrine, tout livrer d'un bloc.

**Recommandation : a.** Données réelles seulement, rien d'inventé. Le planning existe depuis #403 mais sans durée enregistrée, donc le retard reste une décision à part.

**Conséquence boucle.** Débloque #354 (circuit m) ; créer une issue doctrine et une décision « définition du retard » (durée réelle `createdAt` vers `completedAt` ou durée au plus tôt du Planning).

### #342 Santé des structures prévues (carburant, services)

**Contexte.** Prérequis 1 levé : le tableau Structures (hôte de la carte) est livré par #406 (fusionnée, `ProjectStructures.vue`). Reste : `ProductionStructure` ne porte ni `locationId` ni `corporationId` (seulement `IndustryStructureConfig`) ; le scope `esi-corporations.read_structures.v1` est déjà dans `AuthenticationService` ; client existant `AnsiblexSyncService::getCorporationStructures`. Règles non tranchées : seuil « carburant bas » (la maquette montre 2,5 j en alerte, 41 j sans alerte), personnage porteur de `Station_Manager`, cache et fraîcheur.

**Options.**
- a) Service de lecture ESI à l'ouverture de l'onglet, états `ok|low-fuel|offline-service|unknown` (403 ou structure absente = `unknown`), affiché sous le tableau.
- b) Service et ressource sans affichage (non recommandé).

**Recommandation : a.** Lecture à la demande évite une sync planifiée de plus ; `unknown` conforme à la règle des données manquantes.

**Conséquence boucle.** Débloque #342 (circuit m, niveau 3) une fois tranchés : seuil `[À CONFIRMER : valeur en jours, à fixer par toi]`, personnage lecteur (recommandé : le premier personnage de l'utilisateur ayant le rôle, sinon `unknown`).

### #394 Profit Margins : garder `materialCost` et `jobInstallCost` des options d'invention

**Contexte.** Depuis #374 (PR #392), la vue ne lit plus ces deux champs ; ils sont encore produits par `ProfitMarginService::inventionDetails` (lignes 246-247) et typés côté front (`ProfitMarginInventionOption`). Le niveau supérieur donne déjà ces montants pour l'option choisie.

**Options.**
- a) Retirer (back, service, tests, type front).
- b) Garder.

**Recommandation : a.** Rien ne les lit (recommandation de l'issue elle-même).

**Conséquence boucle.** Débloque #394 (circuit s, niveau 3, assertions de tests modifiées).

### #421 Compter la récompense du courrier dans le coût du projet

**Contexte.** `ProjectDistributionService` n'a aucun poste de transport ; #351 (PR #415, fusionnée) lie et suit le contrat. Règle de l'issue : récompense comptée seulement si le contrat est `delivered`, remise à 0 si `failed`, non comptée mais affichée sinon.

**Options.**
- a) Apport dérivé du propriétaire (comme achats et jobs, `DerivedContributionService`), avec marge.
- b) Poste de coût du propriétaire payé avant la répartition.

**Recommandation : a.** Garde « prix réel partout » et une seule marge (recommandation de l'issue).

**Conséquence boucle.** Débloque #421 (circuit m, niveau 3) ; #422 (alerte d'échec du contrat) reste indépendante.

### #248 Upgrade PostgreSQL 16 vers 18

**Contexte.** Ton commentaire du 2026-11-03 : après le déploiement big bang, pour ne pas cumuler la refonte Industry et une migration de données. Migration de production à ta charge.

**Options.**
- a) Confirmer le report après la mise en prod.
- b) Le faire avant (contradictoire avec ton commentaire).

**Recommandation : a.** Ta décision est déjà prise ; l'issue ne peut pas être vidée avant la mise en prod sans cumuler le risque.

**Conséquence boucle.** Sortir de la file (label `post-release` ou équivalent, `[À CONFIRMER : nom du label]`) ; reste ouverte.

### #299 Champs écrits jamais lus (suppression de colonnes)

**Contexte.** Six champs : `CachedStructure::$resolvedAt`, `IndustryStepJobMatch::$plannedStructureName`, `IndustryStepJobMatch::$plannedMaterialBonus`, `CachedIndustryJob::$completedDate`, `SharedShoppingList::$createdBy`, `CachedWalletTransaction::$clientId`. Ton commentaire du 2026-11-03 : reporter, pas de migration de suppression au déploiement. `[À CONFIRMER : IndustryStepJobMatch existe-t-elle encore après #362]`.

**Options.**
- a) Confirmer le report.
- b) Supprimer les colonnes avant la prod (migration non réversible sans sauvegarde).

**Recommandation : a.** Aucune valeur pour l'utilisateur, un risque de plus au déploiement.

**Conséquence boucle.** Sortir de la file, reste ouverte.

### #302 Commandes console sans référence

**Contexte.** `app:fix-token-scopes`, `app:search-structures`, `app:resolve-structure-names`, `app:structures:resolve-all`, `app:clear-structure-market-cache`. Ton commentaire du 2026-11-03 : tout garder tant que le tri n'est pas fait en prod. Il faut savoir si `fix-token-scopes` a tourné en prod `[À CONFIRMER : seul toi peux le dire]`.

**Options.**
- a) Confirmer le report.
- b) Trier maintenant et supprimer.

**Recommandation : a.** Rien ne peut être tranché sans accès prod.

**Conséquence boucle.** Sortir de la file, reste ouverte.

### #313 Minage groupé : suggestion de répartition des mineurs (exploratoire)

**Contexte.** L'issue demande d'évaluer la valeur de la suggestion avant tout code (débit par personnage grossier : ledger en cache 10 min, agrégé par jour). Dépend de #263 et #267. Ton commentaire du 2026-11-03 : gardée ouverte, reportée.

**Options.**
- a) Fermer (non retenue pour l'instant).
- b) Garder ouverte reportée.

**Recommandation : a.** Exploratoire, dépend de #267 non livrée ; on rouvre si l'estimation d'épuisement donne un débit exploitable.

**Conséquence boucle.** Issue à fermer, ou à sortir de la file si b.

### #372 Minage groupé : suppression d'un scan erroné d'un autre participant

**Contexte.** Deux décisions dans l'issue : qui peut supprimer (A auteur, B auteur et propriétaire, C auteur, propriétaire et administrateurs, recommandé), et l'effet sur l'estimation (la spec #267 R15 interdit de recalculer les métriques enregistrées). Suppression de données : niveau 3.

**Options.**
- a) C, et le scan est masqué de l'estimation à venir sans recalcul des métriques enregistrées.
- b) C, avec recalcul de l'estimation courante et des ETA suivants (contredit R15).
- c) A (auteur seul).

**Recommandation : a.** Respecte R15 et laisse un administrateur retirer un scan faux qui fausse l'ETA de la flotte.

**Conséquence boucle.** Débloque #372 (circuit m, niveau 3, jamais fermée ni poussée sans toi).

### #410 Recherche ME/TE proposée seulement quand elle est rentable

**Contexte.** Le code n'a aucun modèle de coût ni de gain de recherche. Dépend de la bibliothèque #408 (fusionnée). Suite de #357.

**Options.**
- a) Seuil simple : gain en matériaux et en temps sur les runs prévus du projet comparé au coût de recherche (job ESI, structure).
- b) Toujours proposer, avec le gain estimé affiché sans seuil.

**Recommandation : a**, horizon limité aux runs du projet (pas de runs futurs hypothétiques). Cela évite de supposer une demande future.

**Conséquence boucle.** Débloque #410 (circuit m) ; la question d'horizon est tranchée par ta réponse.

### #419 Batch scan en timeout avec une structure comme lieu de vente

**Contexte.** `BatchProfitScannerService::scan()` appelle `getAverageDailyVolumesForRegion()` qui fait un appel ESI par type absent du cache (environ 3 400 appels par scan à froid, mesure locale citée dans l'issue) et dépasse `max_execution_time`. Le chemin Jita est corrigé par #420 (PR #426). `StructureTradedVolumeService` est déjà la source de `SellMarketComparison` (vérifié par grep) et renvoie `null` sous 7 jours couverts.

**Options.**
- A) Sync planifiée des volumes régionaux (environ 6 000 appels ESI par région et par jour, risque 420, `dailyVolume` en `float|null`).
- B) `StructureTradedVolumeService` : lecture en base, aucun appel HTTP ; le volume affiché devient le volume échangé estimé de la structure ; une structure sans jours enregistrés affiche tout en inconnu et le filtre `minDailyVolume` vide l'écran.

**Recommandation : B.** Aucun appel ESI ni sync de plus, même source que le comparateur de marchés de vente ; l'écran vide est un état « inconnu » honnête.

**Conséquence boucle.** Débloque #419 (circuit m, niveau 3) ; à lancer avant la mise en prod, c'est un timeout visible.

---

## B. Questions laissées par des threads sur des issues fermées

### #353 Masquer la proposition si un projet produit déjà la cible

**Contexte.** PR #405 fusionnée, #353 fermée. Question du thread : la proposition « projet pour une cible Stockpile sous 25 % » reste affichée même si un projet en cours produit déjà la cible (aucun lien projet-cible aujourd'hui, journal du 2026-11-07).

**Options.**
- a) Masquer quand un projet non terminé produit le type de la cible (lien déduit du produit final).
- b) Laisser la proposition.

**Recommandation : a.** Sinon la file d'actions réclame un projet déjà lancé ; le lien se déduit des `items` du projet sans migration `[À CONFIRMER : projets multi-produits compris]`.

**Conséquence boucle.** Nouvelle issue ciblée (circuit s).

### #360 Profit industrie net ajouté à des revenus PVE et minage bruts

**Contexte.** PR #407 fusionnée. Le total du dashboard Revenus inclut le profit industrie connu, déjà net des coûts, alors que le PVE et le minage sont bruts de dépenses (question du thread, comme la maquette p=39).

**Options.**
- a) Garder l'addition et libeller la composante industrie « net » dans la vue d'ensemble.
- b) Exclure l'industrie du total, la montrer à part.
- c) Convertir PVE et minage en net (hors périmètre).

**Recommandation : a.** Suit la maquette, aucun chiffre existant ne change ; le libellé évite la confusion.

**Conséquence boucle.** Petite issue front (circuit s) si a.

### #256 Marges inconnues affichées malgré un minimum

**Contexte.** PR #398 fusionnée. Champ vidé = pas de minimum ; avec un minimum saisi (défaut 5), une marge inconnue reste masquée côté client et dans `BatchProfitScannerService` (ligne 387). Le thread demande d'afficher les marges inconnues malgré un minimum, avec signalement.

**Options.**
- a) Afficher les lignes à marge inconnue avec un signalement, même avec un minimum (front et backend).
- b) Garder le comportement actuel (masquées sous un minimum).

**Recommandation : a.** Un produit à marge inconnue ne doit pas disparaître en silence (règle du projet sur les données manquantes).

**Conséquence boucle.** Nouvelle issue (circuit m, touche le backend, niveau 3).

### #355 Modèles de projet : structures du modèle = politique seulement ?

**Contexte.** PR #412 et #413 fusionnées. Les « structures » du modèle sont la politique de choix (option hors système préféré, économie minimale), pas des identifiants de structures propres à chaque pilote. Le compteur « Utilisé N× » est livré par #413. Reste la confirmation du point 1.

**Options.**
- a) Confirmer : politique seulement.
- b) Enregistrer aussi des identifiants de structures.

**Recommandation : a.** Un identifiant est propre au pilote, un modèle partagé avec la corpo n'a pas à le porter.

**Conséquence boucle.** Rien à faire si a ; #355 reste fermée.

### #416 Garder la normalisation défensive du store `chain.ts`

**Contexte.** PR #425 fusionnée. Le correctif est double : cause racine backend (`skip_null_values=false`) plus normalisation des champs nullables dans `chain.ts`. Question du thread : garder la normalisation maintenant que l'API envoie les `null`.

**Options.**
- a) Garder.
- b) Retirer.

**Recommandation : a.** Elle coûte peu et protège le front d'une régression de sérialisation.

**Conséquence boucle.** Aucune.

### #361 Virements entre membres et référence de projet

**Contexte.** Le récapitulatif livré affiche le payout par participant ; le bloc « Virements » (qui paie qui, motif) n'est pas fait : ni modèle de règlement, ni référence de projet dans l'API (journal du 2026-11-07). C'est le périmètre de #324.

**Options.**
- a) Calcul serveur des virements et référence de projet (circuit m).
- b) Reporter.

**Recommandation : b.** Non nécessaire à la mise en prod ; à traiter par #324 réduite.

**Conséquence boucle.** Rattacher à #324.

---

## C. Reliquats d'issues partiellement livrées

### #335 Atelier avec file d'actions

**Contexte.** PR #401 fusionnée : Atelier, `GET /industry/actions`, compteur de barre latérale (dernier commentaire du 2026-11-07). Reste : avatars des membres dans la colonne Équipe, car la liste des projets n'expose aucun identifiant de membre.

**Options.**
- a) Fermer, avatars en issue ciblée si voulus.
- b) Garder ouverte.

**Recommandation : a.** Cosmétique, ne bloque rien.

**Conséquence boucle.** Issue à fermer ; #349 et #353 n'attendent plus #335.

### #336 Cockpit de projet

**Contexte.** PR #395 : rail des phases, prochaine action, onglets Matériaux, Apports, Finances, Ventes, Équipe. Depuis, l'onglet Chaîne est livré (#397) et le Planning aussi (#403). `projectDetailTabs.ts` ne garde que `invention` et `journal` en « bientôt » (`COMING_SOON_TABS`). Le Journal dépend de #321 ; pour l'Invention : `[À CONFIRMER : source de données, aucun résultat d'invention n'est enregistré, voir #352]`.

**Options.**
- a) Garder ouverte pour Invention et Journal.
- b) Fermer, deux issues ciblées.

**Recommandation : b.** L'issue d'origine est trop large (circuit l) pour rester ouverte sur deux onglets qui attendent chacun une donnée absente.

**Conséquence boucle.** Issues ciblées : Journal (après #321), Invention (après décision sur l'enregistrement des résultats).

### #338 Planning réel contre prévu

**Contexte.** PR #403 : planification au plus tôt, onglet Planning, « Qui lance quoi ». Quatre points ouverts (commentaire du 2026-11-07) : (1) durées prévues sans bonus de structure ni de rig (`ProductionStructure` ne les modélise pas), signalé par `structureTimeBonusIncluded=false` ; (2) plan figé demande une migration ; (3) personnages des autres participants : exposition de leurs compétences ; (4) copie et invention hors planning.

**Options.**
- a) Étendre `ProductionStructure` au temps (circuit m), puis brancher le planning ; plan figé et persos des autres reportés ; (3) seulement avec accord explicite des intéressés.
- b) Ne rien étendre, laisser l'indicateur.

**Recommandation : a** pour le point 1 seulement. Les durées affichées sont aujourd'hui trop longues et le #276 vérifié en jeu donne déjà les facteurs (compétences 0,6375, rig, rôle). Les points 2 à 4 : reporter.

**Conséquence boucle.** Nouvelle issue « bonus de temps de structure dans `ProductionStructure` » (liée à #276) ; #338 fermée après.

### #341 Comparer les structures au coût total

**Contexte.** Lots 1 (PR #382) et 2 (PR #406) fusionnés. Reste (commentaire du 2026-11-07) : bouton « Autoriser pour ce projet » (l'option n'est pas modifiable après création : PATCH et recalcul de la BOM), carte « Santé des structures » (c'est #342), bonus de rôle et de rig affichés séparément, ME du comparateur à 0, barème de transport (150 ISK/m³/saut) et EIV à confirmer en jeu.

**Options.**
- a) Fermer ; issue ciblée pour le bouton (PATCH + recalcul) ; reste en notes.
- b) Garder ouverte.

**Recommandation : a.** Le bouton est une décision à part (même mécanique que #343).

**Conséquence boucle.** Issue à fermer ; barème de transport 150 ISK : `[À CONFIRMER : en jeu]`.

### #343 Acheter ou fabriquer chaque intermédiaire

**Contexte.** PR #402 fusionnée : comparaison par intermédiaire, import compris. Le bouton « Passer en achat / en fabrication » n'est pas fait : la blacklist d'un projet n'est modifiable qu'à la création (`UpdateProjectInput` sans blacklist), la BOM n'est construite qu'à la création.

**Options.**
- A) PATCH de la blacklist puis régénération de la BOM, refusée si la ligne porte un job lié ou un apport ; la comparaison liste aussi les types blacklistés.
- B) Bascule seulement avant le statut `in_progress`, sans toucher aux lignes liées.

**Recommandation : A** (celle du thread). Sans job ni apport, la régénération ne peut rien casser, et le refus est explicite.

**Conséquence boucle.** Débloque la fin de #343 (circuit m, niveau 3) ; le même mécanisme sert au bouton de #341.

### #345 Réactions dans la chaîne de production

**Contexte.** PR #404 fusionnée : étapes de réaction, surplus sur les lignes de BOM (migration). Question du thread : fusionner la carte Réactions et la carte Acheter ou fabriquer (#402), et y réintégrer coût et valeur du surplus ? Reste ouvert : coût par unité, valeur du surplus, comparaison à l'achat, durée avec compétences et structures (#338).

**Options.**
- a) Fermer ; coût par unité et valeur du surplus fusionnés dans la carte de #343 ; durée avec bonus dans la nouvelle issue de #338.
- b) Garder ouverte.

**Recommandation : a.** Les restes sont déjà portés par #343 et #338.

**Conséquence boucle.** Issue à fermer, sans nouvelle issue.

### #346 Cascade du revenu au profit et seuil de rentabilité

**Contexte.** PR #399 fusionnée (cascade, front seul). Manquent le seuil, sa jauge et la variante usage personnel. Trois décisions (commentaire du 2026-11-07) : base du seuil (coût réel à date contre coût prévu côté serveur), projet multi-produits, usage personnel (le champ `personalUse` a été retiré par #362 R3).

**Options.**
- 1. Base du seuil : A) coût réel à date, affiché quand toutes les lignes sont couvertes et livrées ; B) calcul serveur du coût prévu (circuit m ou l).
- 2. Multi-produits : seuil seulement avec un seul type de produit final, sinon inconnu avec sa cause.
- 3. Usage personnel : A) déduit (un participant, aucune vente à la clôture) ; B) indicateur explicite à la création.

**Recommandation : 1A puis 1B ensuite, 2 comme proposé, 3B.** Données réelles seulement ; déduire l'usage personnel donne un faux positif tant que rien n'est vendu.

**Conséquence boucle.** Débloque #346 (lot seuil) puis #349.

---

## D. Group Industry face au module unifié

Le module unifié a retiré le champ `personalUse` et les routes d'étapes (#362). Vérifications sur origin/main : `DerivedContributionService` (apports dérivés des jobs liés, propriétaire seul), `ProjectKpiCards.vue`, `MemberTable.vue`, `NewProjectModal.vue`, `router/index.ts`, `ContainerBanner.vue`, `ProjectDistributionService`. La PR #326 (docs minage) est mergée et n'est pas une issue ouverte, elle est exclue. Pour chaque entrée, l'option « garder » signifie « valide telle quelle dans le module unifié », « réécrire » signifie mettre à jour l'issue sur le plan unifié, « fermer » signifie caduque. Toutes sont des recommandations.

### #318 Jobs ESI détectés comme contributions, au coût réel payé

**Contexte.** Partiellement livré autrement : `DerivedContributionService::refreshLine` dérive des apports matière et installation de job depuis les jobs liés (#391), attribués au propriétaire seul ; appelé depuis `IndustryJobSyncService`. Reste : détection pour les autres membres et coût réel payé en jeu `[À CONFIRMER : source de installCostOf]`.

**Options.** a) Garder. b) Réécrire sur les apports dérivés (rattachement à un membre autre que le propriétaire). c) Fermer.

**Recommandation : b.** Le texte parle encore de `isAutoDetected` et de contributions en attente que le module unifié a remplacés.

**Conséquence boucle.** Issue à réécrire avant lancement ; #340 en dépend.

### #319 Containers d'entrée et de sortie de chaque job ESI

**Contexte.** `blueprint_location_id` et `output_location_id` sont absents de `src` et `frontend/src` (grep vide). Besoin non couvert par #362.

**Options.** a) Garder. b) Fermer.

**Recommandation : a.** Reste valide, migration sur `CachedIndustryJob`.

**Conséquence boucle.** À lancer en circuit m ; hors chemin critique de la mise en prod.

### #320 Réserver une ligne de la BOM et contribuer depuis la ligne

**Contexte.** `line_reserved` et un champ « réservé par » n'existent pas (grep vide). Le commentaire du 2026-11-03 ajoute des apports partiels par participant (maquette p=6). L'issue parle des lignes de la BOM du projet de groupe.

**Options.** a) Garder. b) Réécrire sur les lignes du plan unifié (BOM `IndustryProjectBomLine`). c) Fermer.

**Recommandation : b.** La notion de ligne reste, mais le plan unifié change le modèle.

**Conséquence boucle.** À réécrire, puis circuit m.

### #321 Fil d'activité en direct et jobs en cours

**Contexte.** Aucun fil d'activité dans le code (grep vide). L'onglet Journal du cockpit est « bientôt » et attend cette issue (commentaire de #336).

**Options.** a) Garder. b) Fermer.

**Recommandation : a.** Elle débloque l'onglet Journal.

**Conséquence boucle.** Débloque #336 (Journal) une fois livrée.

### #322 Compagnon compact au-dessus du jeu

**Contexte.** Aucun compagnon dans le code. Dépend de #320 (« Je m'en charge ») et de #318 ; circuit l, Document Picture-in-Picture.

**Options.** a) Garder. b) Reporter après la mise en prod, en gardant l'issue ouverte.

**Recommandation : b.** Gros chantier non requis pour la prod, et plusieurs prérequis non livrés.

**Conséquence boucle.** Sortir de la file ; #349 n'en dépend plus (voir #349).

### #323 Distribution « en attente des ventes » et marge provisoire

**Contexte.** `ProjectDistributionService` somme les ventes (`array_sum`) sans état « aucune vente » ; `DistributionTable.vue` contient encore l'encart « Loss Scenario » (ligne 334). Le calcul de marge rend `null` seulement si frais ou coûts inconnus. `[À CONFIRMER : revenu 0 sans vente donne toujours −100 % côté API]`.

**Options.** a) Garder. b) Fermer.

**Recommandation : a.** Données absentes présentées comme une perte, contraire à la règle du projet.

**Conséquence boucle.** À lancer en circuit m, avant la mise en prod si possible (affichage trompeur).

### #324 Versements entre membres, vérifications de clôture et récapitulatif

**Contexte.** Le récapitulatif Discord est livré (#361, `ProjectDiscordRecap.vue`) avec le payout par participant. Restent : calcul des virements, référence de paiement, vérifications de clôture.

**Options.** a) Garder. b) Réduire aux virements et vérifications de clôture. c) Fermer.

**Recommandation : b.** Le récapitulatif est livré.

**Conséquence boucle.** À réécrire, puis circuit m ; voir #361.

### #325 Vente par contrat sans taxe du projet

**Contexte.** Aucun canal de vente dans le code (`salesChannel`, `contract_brokers_fee` introuvables). `ProjectDistributionService` applique frais et taxe à toutes les ventes. Complémentaire de #334 (estimation) sans la dupliquer.

**Options.** a) Garder. b) Fermer.

**Recommandation : a.** Fausse le revenu net des projets qui vendent par contrat.

**Conséquence boucle.** Circuit m, niveau 3.

### #327 Collage en lot sans retour, frais et liste noire à la création

**Contexte.** `NewProjectModal.vue` filtre encore les lignes sans retour (`split('\n').filter`, ligne 266) ; aucun champ de frais dans le fichier (grep `brokerage|salesTax` vide). La liste noire : `[À CONFIRMER : #355 modèles a pu changer les défauts]`.

**Options.** a) Garder. b) Réécrire.

**Recommandation : a.** Valide, petite (circuit s).

**Conséquence boucle.** À lancer en circuit s.

### #328 Écarts contributions approuvées contre container, vérification réservée aux admins

**Contexte.** `ContainerBanner.vue` vérifie les lignes (`container_not_found`, `unknown`) ; l'écart avec les apports approuvés et la restriction aux admins du bouton `[À CONFIRMER : non vérifié dans le détail]`.

**Options.** a) Garder. b) Fermer.

**Recommandation : a.** Petite, dépend de la spec 158.

**Conséquence boucle.** À lancer en circuit s.

### #329 KPI des approbations à 0, statut des membres, onglets en 403

**Contexte.** `ProjectKpiCards.vue` (clé `pendingApprovals`) et `MemberTable.vue:192` (« Active » fixe) existent encore. Le reste (nom de la corporation, 403 des onglets) `[À CONFIRMER]`.

**Options.** a) Garder. b) Réécrire.

**Recommandation : a.** Valide, à recadrer sur les apports dérivés (les approbations sont moins nombreuses depuis #391).

**Conséquence boucle.** Circuit s après relecture du KPI.

### #330 Le lien d'invitation ne dit pas quel module il ouvre

**Contexte.** `router/index.ts` n'a que `/join/:code` (ligne 131, route `group-industry-join`) et `/mining-operations/join/:code` pour le minage.

**Options.** a) Garder. b) Fermer.

**Recommandation : a.** Valide, 1 à 2 fichiers, ne touche pas au modèle.

**Conséquence boucle.** Circuit s.

### #339 N'afficher que le main des autres joueurs

**Contexte.** `MemberTable.vue` liste tous les membres sans regroupement par main (`mainCharacter` apparaît dans d'autres composants, pas ici). Maquette p=5 de la V3.

**Options.** a) Garder. b) Fermer.

**Recommandation : a.** Valide, front, sans donnée nouvelle `[À CONFIRMER : l'API expose-t-elle le main des autres membres (vie privée, voir #338 point 3)]`.

**Conséquence boucle.** Circuit s ; question de visibilité à vérifier avant lancement.

### #340 Revue des apports limitée aux apports déclarés

**Contexte.** Dépend de #318 (apports lus dans l'ESI vérifiés d'office). `DerivedContributionService` produit déjà des apports `contribution_synced` `[À CONFIRMER : sont-ils exemptés de la revue d'un admin]`.

**Options.** a) Garder. b) Réécrire après #318.

**Recommandation : b.** Sa définition dépend de la réécriture de #318.

**Conséquence boucle.** Bloquée par #318.

---

## E. Investigations

### #139 Blacklist industrie appliquée ou ignorée selon l'écran

**Contexte.** Décision prise le 2026-10-22 : la blacklist s'applique partout sauf dans Buy vs Build ; une catégorie n'est cochée que si tous ses groupes sont blacklistés. Le label `investigation` est périmé : il reste à l'appliquer dans le code `[À CONFIRMER : état actuel de StockpileService et de StockpileStartingStockReducer après #362]`.

**Options.** a) Retirer `investigation`, laisser l'issue à la boucle (circuit m). b) Fermer si le code est déjà conforme.

**Recommandation : a.** Rien à vérifier en jeu.

**Conséquence boucle.** Issue à relancer après contrôle d'état du code.

### #219 Coût d'une copie : la surtaxe SCC s'applique-t-elle ?

**Contexte.** Vérifié en jeu le 2026-11-05 : SCC 4 % sur la base de coût de job pour la copie (tableau du commentaire). `InstallCostTest.php:124` garde encore `markTestIncomplete`. Écart nouveau noté : le rig réduit le coût d'installation de la copie (−25,2 %) alors que `ProductionCostCalculator` ne passe que le bonus de rôle (6 500 calculés contre 5 541 en jeu), « à traiter dans une issue séparée » `[À CONFIRMER : cette issue existe-t-elle]`.

**Options.** a) Lever le `markTestIncomplete`, fermer #219, ouvrir l'issue du bonus de coût du rig de laboratoire. b) Garder ouverte.

**Recommandation : a.** Le relevé en jeu tranche la question.

**Conséquence boucle.** Issue à fermer après le test ; nouvelle issue pour le bonus de coût du rig (circuit m, niveau 3).

### #276 Étape de copie : bonus de temps de structure

**Contexte.** Vérifié en jeu le 2026-11-05 : durée de copie = 144 000 s × 0,6375 (compétences) × 0,496 (rig de laboratoire −50,4 %) × 0,70 (rôle) = 31 873 s, « plus rien à vérifier en jeu ». `RigCatalogue` connaît les rigs de laboratoire avec `timeBonus` mais `targetCategories` vide. La décision A2 de la spec 126-134 (« bonus de rôle seul pour la copie ») est à remplacer.

**Options.** a) Passer en spec : rig de laboratoire pour la copie et l'invention, durée avec les trois facteurs. b) Fermer.

**Recommandation : a.** Les mesures en jeu sont complètes ; elle rejoint la nouvelle issue « bonus de temps dans `ProductionStructure` » (voir #338).

**Conséquence boucle.** Retirer `investigation`, écrire la spec (circuit m), fusionner avec la nouvelle issue de #338.
