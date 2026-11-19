# Benchmark — module Industrie (fonctionnalités, workflows, UX)

Date : 2026-10-07. Fichier local, non versionné.
Périmètre : fonctionnalités et UX uniquement. Les formules (ME, coût d'installation, durée, invention, agrégation de la demande, surplus de lot) sont traitées dans `.claude/tasks/industry-core/benchmark.md` (ci-après **[core]**), qui contient déjà un tableau de comparaison fonctionnelle (§2). Ce document ne le refait pas : il le complète côté workflow joueur.

---

## 0. Sources

| Id | Outil | Source | Nature / licence |
|---|---|---|---|
| R1 | Ravworks — planner | https://www.ravworks.com/planner | Site fermé, lecture des pages publiques |
| R2 | Ravworks — config | https://www.ravworks.com/config | idem |
| R3 | Ravworks — FAQ | https://ravworks.com/FAQ | idem |
| R4 | Ravworks — exemple de plan | https://ravworks.com/plan/CBevjGf | idem (même plan que le golden de [core]) |
| R5 | Ravworks — accueil | https://ravworks.com/ | idem |
| F1 | EVE Forge — fil d'annonce | https://forums.eveonline.com/t/eve-forge-all-in-one-industry-planner-free-open-beta/508372 | Forum public |
| F2 | EVE Forge — suite du fil | https://forums.eveonline.com/t/eve-forge-all-in-one-industry-planner-free-open-beta/508372?page=2 | Forum public |
| Z1 | Fuzzwork Blueprint Calculator | https://www.fuzzwork.co.uk/blueprint/ | Code MIT (cf. [core] S2) |
| A1 | Adam4EVE — calculateur de fabrication | https://www.adam4eve.eu/manu_calc.php | Site fermé |
| T1 | EVE Tycoon | https://forums.eveonline.com/t/eve-tycoon-a-profit-tracking-and-market-management-tool-for-traders-and-industrialists-now-with-private-citadel-support/197074 | Forum public |
| P1 | Eve Industry Planner (EIP) | https://forums.eveonline.com/t/eve-industry-planner-industry-job-management-application-v0-6-1-havoc-update/347562 | Forum public |
| I1 | Indeve | https://forums.eveonline.com/t/indeve-the-production-planner-released/496571 | Forum public |
| I2 | Industreaux | https://forums.eveonline.com/t/service-tool-industreaux-new-industry-build-profit-planning-and-tracking/504621 | Forum public |
| M1 | MONW | https://forums.eveonline.com/t/monw-multi-character-eve-industry-platform/510194 | Forum public |
| B1 | EvE Blueprint | https://forums.eveonline.com/t/eve-blueprint-highly-customizable-production-platform/516801 | Forum public |
| S1 | EVE Industry Scanner | https://forums.eveonline.com/t/eve-industry-scanner-tool/512171 | Forum public |
| E1 | eveindustry.app | https://forums.eveonline.com/t/web-tool-eveindustry-app/506842 | Forum public |
| X1 | EVE Retroindustry | https://forums.eveonline.com/t/eve-retroindustry-a-free-industry-tool-that-runs-on-your-own-machine/517016 | Open source annoncé, licence non vérifiée → lecture seule par prudence |
| G1 | eve-trader, PR « Asset-optimized build list » | https://github.com/Pappmichel/eve-trader/pull/191 | Licence non visible → idée seulement, aucun code repris |

Non accessible : Reddit (domaine bloqué pour l'outil de recherche), donc pas d'avis joueurs Reddit. Les retours joueurs cités viennent des fils du forum officiel. Aucun code AGPL/GPL n'a été lu.

**Fait notable** : EVE Forge, l'outil « tout-en-un » le plus proche d'evetools, n'est plus accessible depuis fin juillet 2026 selon F2 (site et Discord hors ligne ; le domaine `eveforge.org` affiche une page « domaine à vendre »). La cause évoquée dans le fil est une rumeur, non confirmée. Des joueurs y disent leur déception : il y a une population orpheline d'un outil intégré (ESI + planner + suivi).

---

## 1. Ce qu'evetools a déjà (état au 2026-10-07)

| Capacité | Où | Remarque |
|---|---|---|
| Onglets Industrie : dashboard, projets, marges, scanner batch, buy vs build, pivot, slots, stockpile, config | `frontend/src/views/Industry.vue:104-121` | Couverture fonctionnelle plus large que Ravworks |
| Projet = un produit, N runs, ME/TE, durée max de job | `src/Entity/IndustryProject.php:32-49`, `src/ApiResource/Input/Industry/CreateProjectInput.php:13-29` | Plusieurs racines possibles seulement par split/ajout manuel (`ProjectDetail.vue:62-86`) |
| Découpage des jobs par durée max | `src/Service/Industry/IndustryProjectFactory.php:478-511` | Pas de prise en compte des slots |
| Split / merge / ajout de jobs, ME/TE par étape | `frontend/src/components/industry/StepsTab.vue:57-85` | |
| Étape « achetée » / « en stock » | `src/Entity/IndustryProjectStep.php:58-61` | #52, #59 ouverts |
| Stock du projet : **collé** (nom + quantité), persisté en `localStorage` | `src/State/Processor/Industry/ApplyStockProcessor.php:56-80`, `frontend/src/composables/useShoppingStockAnalysis.ts:53-71` | N'utilise pas les assets synchronisés |
| Matching des jobs ESI aux étapes (auto + manuel) | `src/Service/Industry/IndustryJobMatcher.php:40`, `StepsTab.vue:42-96` | Déclenché par bouton ou commande `app:industry:match-jobs` (`src/Command/MatchProjectJobsCommand.php:18-21`), pas après chaque sync. #3, #59 ouverts |
| Rattachement des achats wallet aux étapes | `src/Entity/IndustryStepPurchase.php:30`, `src/State/Provider/Industry/PurchaseSuggestionProvider.php:96-106` | Rare chez les concurrents (EIP seulement, P1) |
| Personnage recommandé par étape (compétences) | `src/State/Provider/Industry/IndustryResourceMapper.php:249`, `StepTree.vue:709` | |
| Liste d'achats, multibuy Jita / structure, volume manquant | `frontend/src/components/industry/shopping/ShoppingTotalsSummary.vue:70-77`, `ShoppingMaterialsTable.vue:118` | #20, #51, #52 ouverts |
| Prix « façon Ravworks » (centile bas des ordres de vente) | `src/Service/JitaMarketService.php:775-782` | |
| Stockpile (cibles, statut met/partial/critical, goulot) | `src/Service/Industry/StockpileService.php:121-175` | Stock compté sur assets **personnels** uniquement (`src/Repository/CachedAssetRepository.php:281-292`, `is_corporation_asset = false`) |
| Slot tracker + Gantt 72 h + suggestion de quoi lancer sur un slot libre | `src/Service/Industry/SlotTrackerService.php:65, 295-325`, `frontend/src/components/industry/IndustrySlotsSection.vue:22-82` | Suggestion tirée des cibles stockpile : unique dans le benchmark |
| Notification « job terminé » (hub + push) | `src/Service/Sync/IndustryJobSyncService.php:241-247` | |
| Progression de sync en temps réel (Mercure) | `src/Service/Sync/IndustryJobSyncService.php:51-99` | |
| Structures de corpo partagées entre membres | `src/Repository/IndustryStructureConfigRepository.php:92-96`, `src/Entity/IndustryStructureConfig.php:58-70` | #47, #48 ouverts (découvrabilité) |
| Group Industry : projet multi-produits, BOM, contributions, location de ligne, ventes, lien court | `src/Entity/GroupIndustryProject.php:38-88`, `src/Enum/ContributionType.php:12` | Aucun concurrent grand public n'a l'équivalent complet |
| BPC kit, invention, coût de copie | `src/Service/Industry/InventionService.php:354-415` | Formules : voir [core] F8 |
| Scanner batch avec ISK/jour et volume | `src/Service/Industry/BatchProfitScannerService.php:346-367` | |
| Scopes blueprints perso/corpo demandés mais jamais utilisés | `src/Service/ESI/AuthenticationService.php:42-43` | #42 ouvert |

Issues ouvertes à ne pas reproposer : #2, #3, #6, #7, #8, #15-#21, #47-#52, #54, #59 (voir `gh issue list`).

---

## 2. Ce que font les autres (workflow et UX)

### Ravworks (référence de la communauté)

- **Saisie par liste collée, « une ligne par job »** : produit, runs, nombre de jobs, ME, TE, decryptor, relique (R1). C'est exactement la demande de #49 (N BPC × R runs) généralisée à plusieurs produits.
- **Stock de départ collé** (matériaux et intermédiaires) et **stock cible à conserver après production** (« List the stock you wish to maintain after production », R1).
- **Modes de découpage** : défaut (durée max) ; T2 (4 étapes : 2 de réactions, composants, produit fini) et T3 (3 étapes), où « each of these stages will try to fully utilize the available slots » (R3). Option « Don't split jobs shorter than ___ days » et nombre de slots fabrication/réaction en config (R2).
- **Page de plan** avec permalien (R4) : panneau **Cash-Flows** (stocks de départ, stocks de fin, variation, matériaux à acheter, coût des jobs, taxes, invention, profit attendu *hors stocks* et *avec stocks*) ; **Total Job Time par catégorie** (réactions, composants…) ; table **Stocks and Materials** (To Buy, To Buy en valeur, **To Buy Volume**, Start Amount, **End Amount**) avec boutons copier et **« Buy All »** ; jobs groupés par catégorie, repliables, avec runs / jours / coût.
- **Blacklist par catégorie** prête à cocher (Fuel Blocks, Tools, T1 hulls, Capital Components, Advanced Components, réactions par type) + liste « Do not build » libre (R2).
- **Override du cost index** (wormhole) et **systèmes distincts** par activité (fabrication, réaction, invention) (R2).
- **Config en fichier** importable (R2).
- **Profit Margins = liste de favoris**, volontairement pas de classement « le plus rentable » : la FAQ explique que ce classement favorise les items surévalués ou à faible volume (R3).
- Pas de suivi des jobs ESI, pas de compte (R5 : cookies fonctionnels uniquement).

### EVE Forge (hors ligne depuis juillet 2026, F2)

- Statuts de matériaux tirés des assets ESI, container compris (F1 ; libellés REQUIRED / IN STOCK / ELSEWHERE relevés par [core] §2).
- Vue **Pipeline** des manques, **Gantt** avec réordonnancement de la file par glisser-déposer (F1).
- Liste d'achats triée **par lieu** (badges MFG/RXN) et **exports séparés par destination** (F1).
- Tableau de bord ISK/h, **webhooks Discord/Telegram**, « Corporation Hub » (F1).
- Retours joueurs : apprécié pour « une seule interface au lieu de jongler entre outils » ; demandes : saisie manuelle du cost index en wormhole (faite), meilleure visualisation des manques (F1).

### Eve Industry Planner (P1)

- Cycle de vie du job en 5 colonnes : **Planning → Purchasing → Building → Complete → Selling**.
- Ordres de marché et transactions liés au job, **mise à jour automatique à la vente**.
- **Bibliothèque de blueprints** (BPO/BPC, runs restants, en cours d'utilisation).
- Watchlist build vs buy en rouge/orange/vert.
- Demandes joueurs : groupes de projets sauvegardés, profit automatique, prix au 95ᵉ centile, planification partagée au niveau corpo.

### EVE Tycoon (T1)

- **Profit réalisé** : apparie les ventes aux achats ou aux jobs terminés, à travers plusieurs personnages (« buy on one character, build on another, sell on a third »). Avec un personnage Factory Manager, suit les jobs et le wallet corpo.

### Autres

- **Indeve** (I1) : plusieurs produits cibles, **presets de config par activité**, répartition des jobs intermédiaires sur des lignes de production fournies, **prix des matériaux importés depuis l'historique de transactions wallet**, export multibuy / quickbar / tableur.
- **Industreaux** (I2) : choix automatique de la meilleure structure selon les bonus, **plan de transport** (minerai compressé), partage de build avec la corpo. Retour joueur : bouton « calculer » éloigné des résultats, plan de transport faux sur les mouvements intra-station.
- **MONW** (M1) : **EFT → BOM complète du fit**, plans de fabrication partagés avec rôles (sans rôle director), **assignation de jobs par personnage avec vérification des compétences**, agrégation des slots par personnage.
- **EvE Blueprint** (B1) : arbre où l'on **déplie chaque composant qu'on préfère fabriquer**, recalcul en direct ; composants assignés à des structures différentes.
- **EVE Industry Scanner** (S1) : **note de confiance A-D** sur la fiabilité des données, score d'opportunité (profit, volume, marge), « Build Check ». Demande joueur récurrente : sortir des tableurs.
- **eve-trader** (G1) : priorisation des slots par **temps de déblocage aval** (le job qui débloque le plus de travail passe en premier), départage par couverture du stock.
- **Fuzzwork** (Z1) : overrides de prix manuels, ISK/h normalisé 24 h. **Adam4EVE** (A1) : coût « Buy » vs « Optimal » côte à côte, profit/coût en %.

---

## 3. Idées retenues (triées par valeur)

Légende effort : S (≤ 2 j), M (≤ 1 semaine), L (plus).

### I1 — Stock du projet tiré des assets synchronisés, par lieu — **valeur haute, effort M**
- **Problème** : le joueur doit recopier son inventaire pour chaque projet ; le stock collé vit dans un seul navigateur (`useShoppingStockAnalysis.ts:53-71`) et n'est jamais remis à jour.
- **Ailleurs** : EVE Forge (F1, statuts en stock / ailleurs), MONW (M1), Retroindustry (X1, « your own stock counted in », hangars corpo).
- **Proposition** : bouton « Prendre le stock depuis mes assets » avec choix des lieux (structure de production par défaut), trois états par ligne : *requis*, *en stock sur place*, *en stock ailleurs* (→ à transporter). Option : inclure les divisions corpo partagées (voir I9).
- **Fichiers** : `ApplyStockProcessor.php`, `CachedAssetRepository::getQuantitiesByUserWithLocations()` (`:281`), `ShoppingTab.vue`. ESI : aucun appel nouveau (assets déjà synchronisés toutes les 30 min).
- **Dépendances / risques** : #20 (stock partiel d'un intermédiaire) doit être corrigé d'abord, sinon on importe plus vite un calcul faux ; #54 (containers corpo) ; décider si le stock est réservé par projet (deux projets ne doivent pas compter le même stock deux fois).

### I2 — Matching automatique après chaque sync de jobs, poussé en temps réel — **valeur haute, effort S**
- **Problème** : le matcher, fonctionnalité la plus appréciée des testeurs (#3), ne tourne qu'au clic ; un projet ouvert ne reflète pas un job qui vient de démarrer ou de finir.
- **Ailleurs** : EIP (P1) passe les jobs en colonne Building/Complete automatiquement ; Ravworks ne suit rien.
- **Proposition** : à la fin de `IndustryJobSyncService`, relancer `IndustryJobMatcher::matchEsiJobs()` sur les projets actifs de l'utilisateur, puis publier un événement Mercure ; le détail du projet se rafraîchit seul (« 2 jobs démarrés, 1 terminé »).
- **Fichiers** : `src/Service/Sync/IndustryJobSyncService.php`, `IndustryJobMatcher.php`, `MercurePublisherService`, `frontend/src/stores/sync.ts`. ESI : aucun.
- **Dépendances / risques** : **bloqué par #3 et #59** (aujourd'hui un match modifie le plan) ; à n'activer qu'après leur correction et un test de caractérisation.

### I3 — Saisie multi-produits « une ligne par job » — **valeur haute, effort M**
- **Problème** : un projet = un produit (`IndustryProject.php:32`) ; un industriel T2 lance 5-10 produits ensemble et veut une seule liste d'achats.
- **Ailleurs** : Ravworks (R1 : produit, runs, nombre de jobs, ME, TE, decryptor), Indeve (I1), Group Industry d'evetools lui-même (`GroupIndustryProject::$items`).
- **Proposition** : zone de texte collable `Produit  runs  [×jobs]  [ME]  [TE]`, aperçu avant création. **Étend #49** (N BPC × R runs) plutôt qu'une nouvelle issue.
- **Fichiers** : `CreateProjectInput.php`, `IndustryProjectFactory::createProject()` (`:43`), modèle de données (racines multiples). ESI : aucun. Le calcul agrégé relève du nouveau moteur ([core] F2).
- **Risques** : changement de modèle (`productTypeId` unique) ; à faire dans le nouveau moteur, pas sur l'ancien.

### I4 — Bibliothèque de blueprints : ME/TE réels et BP manquants — **valeur haute, effort M**
- **Problème** : ME/TE saisis à la main, ME 10 supposé sur les intermédiaires (glossaire, `docs/glossary.md` « ME ») ; le joueur ne voit pas qu'il lui manque un BPO ou qu'un BPC n'a plus assez de runs.
- **Ailleurs** : EIP (P1, BPO/BPC, runs restants, en cours d'utilisation), EVE Industry Scanner (S1, blueprints corpo via OAuth).
- **Proposition** : sync `GET /characters/{id}/blueprints/` et `GET /corporations/{id}/blueprints/` ; à la création d'un projet, pré-remplir ME/TE par étape depuis le meilleur BP possédé et marquer « BP absent » / « BPC : 3 runs restants sur 10 nécessaires ».
- **Fichiers** : nouveau service de sync (`src/Service/Sync/`), entité cache, `IndustryProjectFactory`. Scopes déjà demandés (`AuthenticationService.php:42-43`) : **résout aussi #42** pour ces deux scopes.
- **Risques** : la pagination et le rôle Director/Factory Manager pour la corpo ; volume (les corpos ont des milliers de BP).

### I5 — Découpage par slots disponibles et ordre de lancement — **valeur haute, effort L**
- **Problème** : le découpage ne regarde que la durée (`IndustryProjectFactory.php:478-511`) ; avec 30 slots, le joueur re-découpe à la main pour occuper ses lignes.
- **Ailleurs** : Ravworks modes T2/T3 (R3), « Total Job Time » par catégorie (R4) ; Indeve (I1) répartit sur des lignes ; eve-trader (G1) priorise par déblocage aval ; MONW (M1) assigne par personnage.
- **Avantage evetools** : on connaît les **slots réels libres** par personnage (`SlotTrackerService.php:65, 237`) et ses compétences, là où Ravworks demande un nombre à la main.
- **Proposition** : mode « remplir mes slots » par étape de production, puis une **checklist de lancement par personnage** (« Perso A : 4 jobs de réaction ici ; Perso B : … »), qui se coche toute seule via I2.
- **Fichiers** : `IndustryProjectFactory`, `SlotTrackerService`, `IndustryResourceMapper.php:249`. Dépend du nouveau moteur (split avec recalcul des matériaux par job, [core] §2 « Découpage »).

### I6 — Panneau Cash-Flows et surplus valorisés — **valeur moyenne, effort S** (une fois le moteur livré)
- **Problème** : le joueur ne voit pas ce que le plan laisse en stock (lots entiers) ni ce que ça vaut ; deux marges coexistent (#19).
- **Ailleurs** : Ravworks (R4) : stocks début/fin, variation, profit hors stocks / avec stocks, Start/End Amount par matériau.
- **Proposition** : sur le résumé du projet, un bloc unique *achats, jobs, taxes, invention, ventes, variation de stock, profit (hors stock / avec stock)*. Données : surplus du moteur ([core] F2).
- **Fichiers** : `CostSummaryCards.vue`, `ProfitMarginService`. À articuler avec #19 (une seule définition de marge).

### I7 — Profit réalisé : rattacher les ventes au projet — **valeur moyenne, effort M**
- **Problème** : `sellPrice` est saisi ou estimé (`IndustryProject.php:70-91`) ; le joueur ne sait pas ce qu'il a réellement gagné.
- **Ailleurs** : EVE Tycoon (T1, ventes appariées multi-personnages), EIP (P1, étape Selling mise à jour à la vente).
- **Proposition** : même mécanique que les achats (`PurchaseSuggestionProvider.php:96-106`), côté ventes : suggestions de transactions de vente du produit fini après la livraison du job ; profit réalisé vs prévu.
- **Fichiers** : nouveau provider symétrique, `CachedWalletTransactionRepository`. ESI : wallet transactions déjà synchronisées (20 min).

### I8 — Arbre interactif build/buy par nœud — **valeur moyenne, effort M**
- **Problème** : la décision « j'achète ce composant » passe par la blacklist globale ou la case « acheté » de l'étape (#52 : elle n'alimente pas l'approvisionnement) ; buy vs build n'analyse que le niveau 1 ([core] §2).
- **Ailleurs** : EvE Blueprint (B1, déplier ce qu'on préfère fabriquer, recalcul en direct), EIP (P1, watchlist couleur), blacklist par catégorie de Ravworks (R2).
- **Proposition** : dans `StepHierarchyTree.vue`, un interrupteur Fabriquer / Acheter par nœud avec le delta ISK affiché (vert/rouge), et des cases « catégories » prêtes (fuel blocks, composants T2, réactions…) dans la blacklist. Le calcul récursif est au moteur ; ici, l'UX.
- **Dépend de** #52 (une étape achetée doit apparaître dans l'approvisionnement).

### I9 — Assets corpo partagés comme source de stock — **valeur moyenne, effort S** (après I1) — *propre à evetools*
- **Problème** : une corpo industrielle stocke ses intermédiaires en hangar corpo ; stockpile et projets les ignorent (`CachedAssetRepository.php:290`).
- **Ailleurs** : Retroindustry (X1) agrège les hangars corpo, mais en local et pour un seul joueur ; personne ne gère le partage par division choisi par le director.
- **Proposition** : case « inclure les divisions corpo visibles » dans I1 et dans le stockpile, en réutilisant `CorpAssetVisibility`.
- **Dépend de** #54 (containers absents des divisions partagées).

### I10 — Lieu, volume et export par destination — **valeur moyenne, effort S**
- **Ailleurs** : Ravworks « To Buy Volume » (R4), EVE Forge exports séparés par destination (F1), Industreaux plan de transport (I2).
- **Existant** : volume manquant affiché (`ShoppingMaterialsTable.vue:118`), multibuy Jita / structure (`ShoppingTotalsSummary.vue:70-77`), coût d'export au m³ (`IndustryUserSettings.php:46`).
- **Proposition** : regrouper la liste d'achats par lieu d'achat (choix #51) et afficher m³ par groupe + bouton multibuy par groupe. Petit complément à #51, pas une issue séparée.

### I11 — Projet depuis un fit EFT — **valeur moyenne, effort S** — *croisement avec l'appraisal*
- **Ailleurs** : MONW (M1, « paste an EFT fit, get a full BOM »).
- **Existant** : le parseur de l'appraisal reconnaît déjà EFT (`frontend/src/views/ShoppingList.vue:34`).
- **Proposition** : « Créer un projet depuis un fit » (coque + modules fabricables, le reste en achats). Dépend de I3 (multi-produits).

### I12 — Indice de confiance des prix — **valeur moyenne, effort S**
- **Ailleurs** : EVE Industry Scanner (S1, notes A-D) ; Ravworks refuse le classement brut pour la même raison (R3).
- **Proposition** : badge par prix (âge de la donnée, profondeur du carnet, volume journalier) et « inconnu » explicite. **Complète #8** (prix manquant = gratuit) et la règle du CLAUDE.md sur les données manquantes ; à intégrer dans la correction de #8 plutôt qu'en issue séparée, sauf avis contraire.

### I13 — Couverture PI du plan — **valeur basse à moyenne, effort M** — *propre à evetools*
- **Problème** : les composants (fuel blocks, composants de structure/capitaux) consomment des produits PI que le joueur produit lui-même ; aucun outil ne relie colonies et plan.
- **Existant** : production PI calculée par colonie (`src/Service/Planetary/PlanetaryProductionCalculator.php:63, 221`).
- **Proposition** : sur la liste d'achats, marquer les matériaux PI couverts par ses colonies (« produit 1 200/jour, besoin 8 000 → 7 jours »). Aucun concurrent du benchmark ne le fait (non trouvé dans F1, R1-R4, M1, B1).

### I14 — Convertir un projet perso en projet Group Industry — **valeur basse à moyenne, effort M** — *propre à evetools*
- **Problème** : un joueur planifie seul puis veut ouvrir le projet à sa corpo ; il doit tout ressaisir (processors existants : `src/State/Processor/GroupIndustry/` n'ont pas de création depuis un projet perso).
- **Ailleurs** : Industreaux (I2) et MONW (M1) partagent des plans ; EIP (P1) a reçu la demande de planification corpo.
- **Proposition** : action « Ouvrir à ma corpo » sur un projet. Dépend de #39 (N+1 Group Industry) et de I3.

---

## 4. Idées écartées

- **Classement « les plus rentables » global** : Ravworks l'écarte avec de bons arguments (R3) ; evetools a déjà scanner batch + favoris (`IndustryScannerFavorite`) avec volume et ISK/jour (`BatchProfitScannerService.php:346-367`).
- **Webhooks Discord/Telegram** (F1) : à traiter dans le Notifications Hub planifié, pas dans Industrie.
- **Import/export de la config en fichier** (R2) : utile pour un outil sans compte ; evetools stocke la config côté serveur.
- **Presets de config par activité** (I1) : déjà couvert par structures + systèmes favoris par activité (`IndustryUserSettings.php:30-34`).
- **Override manuel du cost index** (R2, F1) : relève du moteur de formules ([core] F3). Que `/industry/systems/` couvre ou non les systèmes wormhole est **non confirmé** (non vérifié ici) ; si non, l'idée remonte en valeur moyenne pour les industriels en wormhole.
- **Plan de transport de minerai compressé** (I2) : hors module Industrie (minage/logistique), retour joueur négatif sur sa justesse.
- **Gantt avec réordonnancement glisser-déposer** (F1) : le jeu ne gère pas de file ; le réordonnancement n'a d'effet que sur un plan, couvert par la checklist de I5.
- **T3 / reliques** : hors périmètre décidé dans [core] (D9).
- **Graphiques financiers « trading »** (F1, B1) : relève du Market Browser.

---

## 5. Ce qu'evetools fait déjà mieux

1. **Suivi réel de l'exécution** : matching des jobs ESI aux étapes et rattachement des achats wallet (`IndustryJobMatcher.php:40`, `IndustryStepPurchase.php:30`). Ravworks n'a aucun suivi ; seul EIP (P1) s'en approche.
2. **Slots réels + suggestion de production** depuis les cibles stockpile (`SlotTrackerService.php:325`) : aucun concurrent du benchmark ne relie slots libres et stock cible.
3. **Industrie collaborative complète** (contributions, approbation, location de ligne, payout, ventes ; `GroupIndustryProject.php`, `ContributionType.php:12`) : seuls des partages de plan existent ailleurs (I2, M1).
4. **Structures partagées au sein de la corpo** (`IndustryStructureConfigRepository.php:92-96`).
5. **Temps réel** (Mercure) et notification de fin de job (`IndustryJobSyncService.php:241`).
6. **Un seul outil** marché + industrie + PI + assets, avec compte : le créneau d'EVE Forge, aujourd'hui inaccessible (F2).
7. **Prix de structure** en plus de Jita (#50/#51 en cours) ; Ravworks est Jita uniquement (R3).

Faiblesses à corriger avant toute nouvelle fonctionnalité : justesse des quantités et du coût (#2, #6, #7, #8, #15, #16, #20 et [core] §3), sans quoi les idées ci-dessus amplifient des chiffres faux.

---

## 6. Termes de glossaire à proposer

- **Surplus** (*Surplus / End amount*) : unités produites au-delà du besoin à cause des lots entiers.
- **Plan de lancement** (*Launch plan*) : affectation des jobs d'un projet aux personnages et aux slots.
- **Bibliothèque de blueprints** (*Blueprint library*) : BPO/BPC possédés (perso et corpo), avec ME/TE et runs restants.
- **Profit réalisé** vs **profit estimé** (*Realized / Estimated profit*).
