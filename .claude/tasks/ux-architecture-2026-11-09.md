# Audit UX : architecture de l'information et parcours (2026-11-09)

## Contexte

L'app a accumulé des modules sans cohérence globale : pages fourre-tout, fonctions d'une même activité dispersées, parcours joueur pensés écran par écran. Cas déclencheur : Revenus (`/ledger`) porte les onglets Minage (ISK/m³, chunks lunaires) qui appartiennent au module Minage. Livrable : ce document (copié dans `.claude/tasks/ux-architecture-2026-11-09.md` après validation), des décisions tranchées avec Sylvain, puis un découpage en issues GitHub (créées seulement après accord). Aucun code touché dans ce thread.

Sources : router `frontend/src/router/index.ts`, sidebar `frontend/src/constants/sidebarNavigation.ts`, palette `composables/useNavigationEntries.ts`, layout `layouts/MainLayout.vue`, 3 inventaires d'exploration (Revenus+Minage, Industrie+achats, Market/Assets/PI/PVE/layout), comparaison communauté (product-analyst).

---

## 1. Inventaire

### Sidebar actuelle
| Groupe | Entrées | Remarque |
|---|---|---|
| — | Tableau de bord | |
| Production | Industrie, Appraisal, PI | Appraisal et PI n'y sont pas à leur place |
| Minage | Opérations | groupe à un seul item |
| Économie | Marché, Revenus, Inventaire | |
| Compte | Personnages, Paramètres (modale) | doublés dans le menu compte |
| Admin | Admin | |

Hors sidebar : liste des projets `/group-industry` (palette, Dashboard industrie, retour du détail), `/shopping-list` (orpheline : ni lien, ni palette).
Palette : manquent `ledger?tab=industry|dashboard`, `industry?tab=workshop|dashboard` ; entrées projet sur l'ancienne URL `/group-industry/:id`.

### Pages et onglets
| Route | Onglets (`?tab=`, défaut en gras) | Sous-vues |
|---|---|---|
| `/dashboard` | — | À faire (persos, PI, alertes prix), KPI persos + wallet, cartes persos, flux sync local |
| `/industry` | Vue d'ensemble : **workshop**, dashboard · Analyse : margins, batch, buy-vs-build, pivot · Operations : slots, stockpile | panneau Config (frais, skills, structures + systèmes favoris, blacklist, durée max) |
| `/group-industry` | **my-projects**, available | NewProjectModal (3 modes), LineRentalModal |
| `/industry/projects/:id` | **bom**, contributions, distribution, sales, members (toujours affiché), chain, planning (+ invention, journal « à venir ») | PhaseRail, NextActionCard |
| `/mining-operations` | **mine**, available, archive, season, sites | récurrences sous `mine` |
| `/mining-operations/:id` | page à étapes (planned → in_progress → closing → shares → settled / completed) | ~15 panneaux selon statut |
| `/mining-sites/:id` | — | scan, courbe, ETA par type |
| `/ledger` « Revenus » | **dashboard**, pve, mining, industry, escalations, settings | Minage : entries / oreValue / moonChunks **hors URL** ; période globale (ignorée par escalations) |
| `/planetary` | — | KPI, bandeau, production, colonies |
| `/market` | **browse**, detail (dynamique), alerts | |
| `/assets` | **assets**, contracts | visibilité corpo (directeur) |
| `/appraisal` | — | saisie, KPI, comparaison structure, actions |
| `/shopping-list` | mode appraisal / import | doublon d'`/appraisal` |
| `/characters`, `/admin` | — | |

### Doublons de fonctions
| Fonction | Où | Problème |
|---|---|---|
| Prix du minerai | 6 bases : table ISK/m³, lignes ledger, stats ledger (78 %, Jita, sans taxe), opération, sites/chunks (Jita buy compressé), vérif fleet storage | un même minerai affiché à des valeurs différentes ; le chunk n'est pas valorisé comme l'opération qui l'exploite |
| Rendement de retraitement | localStorage `miningReprocessYield`, profil serveur, constante 0.78, réglage par opération | 4 sources |
| Structure de marché | préférée (Paramètres), localStorage onglet Minage, sync Appraisal, réglage opération | 4 sélecteurs |
| Quantités minées | `MiningSyncService` (ledger) et `MiningLedgerReader` (opérations) | jamais rapprochées |
| Prix industrie | Marges (pondéré), Scan/Pivot/Stockpile (meilleur ordre), BOM/contributions (percentile 5 %), Appraisal (pondéré + meilleur) | même marge, mêmes matériaux, prix différents |
| Achat vs Prod | `BuyVsBuildTab` et onglet `chain` du projet | deux moteurs |
| Listes de projets | Atelier, Dashboard industrie, `/group-industry` | trois listes |
| Jobs / slots | Slots, Atelier, Dashboard industrie, Gantt projet | pas de lien job → projet |
| Liste d'achats | `/shopping-list`, `/appraisal`, Stockpile, BOM projet | BOM sans export, chips de format décoratives |
| Flux d'activité | flux sync local du Dashboard, cloche serveur | deux flux |
| Revenu PVE | onglets dashboard et pve de Revenus (2 calculs) | escalations vendues non comptées |

### Fonctions sans écran ou mal câblées (constatées, hors IA pure)
- Taxe lunaire : backend complet (`src/ApiResource/MoonTax/*`, sync planifiée 1 h), **aucune interface**.
- Alertes prix : déclenchement publié en Mercure seulement, aucune notification (`MarketAlertService.php:82`) ; catégories `price`, `escalation`, `esi` sans producteur.
- Seuil PI : préférence utilisateur jamais lue (24 h KPI, 2 h notif codés en dur).
- Alertes marché : suppression seule, pas d'édition ni de réarmement.

---

## 2. Parcours

| Profil | Chemin actuel | Clics / ruptures | Chemin cible | Clics cible |
|---|---|---|---|---|
| **Mineur** : ISK/m³ avant de choisir une belt | Économie › Revenus › Minage › Valeur au m³ › marché | ~5 ; passe par Économie ; sous-vue hors URL ; le site affiche un autre « meilleur minerai » | Minage › Valeur du minerai (même base que sites et opérations) | 2 |
| **Chef de flotte** : créer, inviter, clôturer, payer | Minage › Opérations › Nouvelle › … › Solder | ~14 + N, cohérent sur une page ; le lien « rendement inconnu » atterrit sur la mauvaise sous-vue de Revenus | idem + réglage de valorisation dans le module | ~14 + N, sans sortie |
| **Directeur lunaire** : valeur du prochain chunk → opération | Économie › Revenus › Minage › Chunks › déplier → sidebar › Minage › Opérations › Nouvelle (ressaisie) | ~11, 2 pages, aucune trace lune ↔ opération ; taxe lunaire invisible | Minage › Lunes › chunk › « Planifier l'opération » (prérempli, lié au chunk) ; taxe lunaire sur la même page | ~4 |
| **Industriel solo** : rentable ? → projet → vente | Industrie › Marges (cul-de-sac) › Achat vs Prod (ressaisie) › Créer › liste › carte › statuts › Ventes | ~18, 2 saisies, 3 pages | Industrie › Analyse (`typeId` gardé) › « Créer le projet » › projet ouvert | ~8 |
| **Industriel corpo** : créer, inviter, contribuer, répartir | Industrie › Dashboard › Nouveau projet › … › 7 onglets | ~25 côté owner ; liste des projets hors sidebar | Industrie › Projets › Nouveau (onglet Équipe à l'invitation) ; BOM → export multibuy / Évaluateur | ~18 |
| **Acheter les matériaux** | BOM → recopie à la main dans Appraisal | rupture totale | BOM › « Multibuy » / « Évaluer » (prérempli) | 2 |
| **Trader** : prix, alerte, réaction | Marché › item › alerte › valider ; déclenchement invisible hors todo Dashboard | 4 + 3 pour réagir, pas de notification, pas de réarmement | idem + notification cliquable vers le détail, édition/réarmement | 4 + 1 |
| **PVE** : escalation + revenu du mois | Économie › Revenus › Escalations › Ajouter ; puis onglet PVE | ~6 ; escalation vendue non comptée ; aucun revenu sur l'accueil | PVE › Escalations ; PVE › Revenus PVE (escalations vendues incluses) ; Bilan sur l'accueil | ~3 |
| **PI** : extracteurs qui expirent, valeur | Planétaire (KPI 24 h) ; notif à 2 h ; seuil réglé ignoré | 1 + 1/colonie ; pas de lien Marché | idem, seuil respecté, produit → Marché | 1 |

---

## 3. Diagnostic

1. **Revenus est un fourre-tout** : synthèse financière, outil PVE complet, outil d'escalations, trois outils minage (ledger, ISK/m³, chunks lunaires), compta industrie, un réglage. Six métiers sur une page, rangée dans « Économie ».
2. **Le module Minage est coupé en deux** : la valeur du minerai et les lunes vivent dans Revenus, les opérations et sites dans Minage ; un seul lien entre les deux, cassé.
3. **Pas de pont entre étapes** : chunk → opération, Marges → projet, BOM → achat, alerte → notification, job (Slots) → projet, produit PI → Marché.
4. **Valorisation incohérente** : 6 bases pour le minerai, 4 pour les prix industrie, 4 sélecteurs de structure, 4 rendements ; aucune étiquette de source commune.
5. **Industrie unifiée seulement côté données** : l'UI dit encore « Industrie de groupe », l'onglet Équipe est toujours là, les projets n'ont pas d'entrée de sidebar, Atelier et Dashboard industrie se recouvrent.
6. **Groupes de sidebar par type de donnée, pas par activité** : Appraisal et PI sous Production, Escalations sous Économie, Minage presque vide.
7. **Réglages dispersés sur 7 écrans**, dont des réglages métier en localStorage (minage).
8. **Accueil sans activité** : ni revenus, ni opération en cours, ni chunk, ni jobs qui finissent.
9. **Pages mortes ou doublées** : `/shopping-list`, composants `shopping/*`, deux flux d'activité.

---

## 4. Cible (validée le 2026-11-09, voir §5)

Principe (forme de navigation : D4, sidebar à 8 modules + onglets de module) : la sidebar suit les **activités du joueur** (vocabulaire du jeu, HUD), chaque module porte son outil d'analyse ET son suivi, et un seul Bilan agrège les revenus. Aucune feature ni aucun réglage retiré : tout est déplacé ou fusionné.

```
Accueil                 (Pont : À traiter, en cours : opérations, jobs, chunks, PI ; bilan du mois)
Industrie
  ├ Atelier             (fusion Atelier + Dashboard industrie, bouton Nouveau projet)
  ├ Projets             (/industry/projects, ex-« Industrie de groupe » ; Équipe dès qu'on invite)
  ├ Analyse             (Marges, Scan, Achat vs Prod, Pivot ; typeId conservé dans l'URL)
  └ Production          (Slots, Stockpile ; jobs liés à leur projet)
Minage
  ├ Opérations          (mine, available, archive, récurrences)
  ├ Lunes               (← Revenus › Minage › Chunks : calendrier, valeur, composition ; + taxe lunaire)
  ├ Sites               (← onglet sites des Opérations)
  ├ Valeur du minerai   (← Revenus › Minage › Valeur au m³ : ISK/m³, profil de retraitement, vente)
  └ Mon minage          (← Revenus › Minage › Entrées : ledger perso ; + Saison)
PVE
  ├ Revenus PVE         (← Revenus › PVE ; escalations vendues incluses)
  └ Escalations         (← Revenus › Escalations)
Planétaire
Commerce
  ├ Marché              (browse, détail, alertes)
  ├ Évaluateur          (← Production › Appraisal ; reçoit BOM et Stockpile)
  └ Contrats            (← Inventaire › Contrats)
Patrimoine
  ├ Bilan               (← Revenus réduit : synthèse toutes activités + compta industrie + réglage compta corpo, liens vers chaque module)
  └ Inventaire
Compte : Personnages, Paramètres (page à sections : tous les réglages, dont valorisation minière serveur et préférences de notification)
```

Chantiers transverses : base de valorisation commune (minerai puis industrie) avec étiquette de source ; ponts entre étapes ; notifications câblées (alertes prix, seuil PI).

---

## 5. Décisions (posées à Sylvain par petits groupes, /ask)

- **Groupe A — Minage (cas déclencheur)** : place du ledger perso, page Lunes + taxe lunaire, Sites en page, réglages de valorisation minière.
- **Groupe B — Revenus / PVE** : Bilan réduit vs Revenus à onglets ; module PVE (revenus + escalations).
- **Groupe C — Industrie** : projets dans la sidebar et sous-pages, fusion Atelier/Dashboard, `/shopping-list` (suppression = accord requis), Équipe conditionnelle.
- **Groupe D — Transverse** : groupes Commerce / Patrimoine, page Paramètres, chantier prix commun, notifications.

Réponses (2026-11-09, toutes sur la recommandation) :
- A1 Minage = 5 pages : Opérations, Lunes, Sites, Valeur du minerai, Mon minage (ledger perso + Saison).
- A2 Taxe lunaire dans la page Lunes (onglet, visible aux Director / Station_Manager) ; c'est l'issue existante #369.
- A3 Profil de valorisation minière côté serveur (rendement, structure, taxe d'export, marché/côté) dans Paramètres, utilisé par défaut partout ; une opération le surcharge et fige ses prix ; chunk, site et opération sur la même base ; source affichée.
- A4 Chunk → « Planifier l'opération » prérempli, lien chunk ↔ opération dans les deux sens.
- B1 Revenus devient **Bilan** (synthèse + compta industrie + réglage compta corpo, liens vers les modules).
- B2 Module **PVE** : Revenus PVE + Escalations ; une escalation vendue compte dans le revenu PVE.
- B3 Accueil = **Pont** (à traiter + en cours + bilan du mois), base maquette `dashboard.html`.
- C1 Industrie = 4 sous-pages : Atelier, Projets (renommé, `/group-industry` redirigé), Analyse (typeId dans l'URL), Production (Slots, Stockpile).
- C2 Atelier et Dashboard industrie fusionnés (aucun bloc retiré).
- C3 `/shopping-list` supprimée, redirigée vers `/appraisal` ; page partagée sur les composants de l'Évaluateur ; `components/shopping/*` supprimés.
- C4 Onglets Équipe et Contributions affichés seulement à partir d'une invitation ou d'un 2e membre ; bouton « Inviter » sinon.
- D1 Groupes **Commerce** (Marché, Évaluateur, Contrats) et **Patrimoine** (Bilan, Inventaire) ; Planétaire au premier niveau.
- D2 Page **Paramètres** à sections + bouton « Réglages » par module qui ouvre sa section ; aucun réglage retiré.
- D4 (2026-11-10) Sidebar = les 8 modules seulement (Accueil, Industrie, Minage, PVE, Planétaire, Commerce, Patrimoine, Compte), environ 300 px ; les pages d'un module sont une barre d'onglets HUD en haut du module, chacune avec sa propre URL ; un clic sur un module rouvre sa dernière page visitée. Raison : la sidebar à plat ferait 20 entrées + 6 titres (environ 850 px), contre 10 entrées aujourd'hui.
- D3 Chantiers inclus : prix industrie communs, notifications câblées, ponts entre étapes, formats de l'Évaluateur (implémenter ou retirer les puces : à trancher à la spec, aucun retrait sans accord).

Point annexe (product-analyst) : une source non officielle cite 30 000 m³/h pour le débit d'extraction, le glossaire dit 25 000 m³/h ; à vérifier en jeu, rattaché à #368.

---

## 6. Découpage en issues (création seulement après accord de Sylvain)

Labels existants vérifiés : `group:mining-ops` (porte #368 valeur du chunk et #369 écrans taxe lunaire), `group:ui-shell`. Nouveaux labels proposés : `group:mining-module`, `group:mining-valuation`, `group:revenue-pve`, `group:industry-ia`, `group:commerce`, `group:notifications-wiring`, `group:price-rules`, `group:workflow-bridges`. Chaque issue : labels `feature` + domaine + `group:*`, besoin, comportement attendu, anciennes URL à rediriger (palette, notifications backend, liens partagés), critère testable. Rédaction en français comme les issues existantes (#369).

**Lot 1 — `group:mining-module` (déplacement Minage, en premier)**
1. Module Minage : routes `/mining/operations`, `/mining/moons`, `/mining/sites`, `/mining/ore-value`, `/mining/my-mining` [noms d'URL à figer à la spec]  ; barre d'onglets du module (5 pages), la sidebar garde une seule entrée Minage ; redirections `/ledger?tab=mining`, `/mining-operations*` ; palette ; sous-vues dans l'URL.
2. Page Valeur du minerai : `OreValueTab` + `ReprocessingProfilePanel` + `OreSalePanel` sortis de `MiningTab`.
3. Page Lunes : `MoonChunksTab` (liste, semaine, mois, valeur, composition) ; emplacement de l'onglet Taxe lunaire ; #369 et #368 réordonnées derrière (commentaire sur chaque).
4. Page Mon minage (`MiningStatsCards`, `MiningEntriesTable`, Saison) et page Sites (`MiningSiteHistory`) ; le lien « rendement inconnu » de `MiningStockpileCoverage` pointe vers le bon écran.
5. Chunk → « Planifier l'opération » préremplie, lien chunk ↔ opération ; site → son opération.

**Lot 2 — `group:mining-valuation`**
6. Profil de valorisation minière serveur (remplace les clés localStorage `miningReprocessYield`, `miningExportTax`, `miningSelectedStructure`), section Minage des Paramètres, surcharge par opération.
7. Une seule base de prix du minerai pour table ISK/m³, ledger, stats, sites, chunks, fleet storage (`OreValueService`, `MiningBestValueCalculator`, `MiningSiteUnitPrices`, `MiningFleetStorageVerifier`) + étiquette de source.

**Lot 3 — `group:revenue-pve`**
8. Module PVE : Revenus PVE (`PveTab`) et Escalations en pages ; redirections `/pve`, `/escalations`, `/ledger?tab=pve|escalations`.
9. Escalation vendue comptée dans le revenu PVE et le Bilan.
10. Revenus → Bilan (dashboard, compta industrie, réglage compta corpo), liens vers chaque module.

**Lot 4 — `group:industry-ia`**
11. Industrie en 4 sous-pages, « Industrie de groupe » → « Projets », `/group-industry` → `/industry/projects`, palette (ancienne URL `/group-industry/:id`, pills manquants).
12. Fusion Atelier + Dashboard industrie avec bouton Nouveau projet.
13. Équipe et Contributions affichées à l'invitation ; `/join` d'un membre existant ouvre le projet.

**Lot 5 — `group:commerce`**
14. Groupe Commerce : Évaluateur (ex-Appraisal), Contrats en page (redirection `/assets?tab=contracts`).
15. Suppression de `/shopping-list` et `components/shopping/*`, page partagée sur les composants de l'Évaluateur.
16. Formats de l'Évaluateur : implémenter ou retirer les puces (décision à la spec).

**Lot 6 — `group:ui-shell` (existant)**
17. Sidebar finale à 8 modules (D4) avec barre d'onglets de module commune (composant partagé, dernière page visitée mémorisée), palette alignée sur toutes les pages ; remplace les groupes repliables `evetools_nav_collapsed`.
18. Accueil « Pont ».
19. Page Paramètres à sections + raccourci « Réglages » par module.

**Transverses (ordre libre après le lot 1)**
20. `group:notifications-wiring` : alerte prix → notification cliquable, édition/réarmement ; seuil PI utilisateur réellement lu.
21. `group:price-rules` : règle de prix industrie commune (Marges, Scan, Achat vs Prod, Pivot, Stockpile, BOM, Évaluateur) + étiquette de source.
22. `group:workflow-bridges` : Marges → Créer le projet et Achat vs Prod (typeId conservé) ; BOM et Stockpile → multibuy / Évaluateur ; job → projet ; produit PI → Marché.

## 7. Étapes après approbation

1. Écrire ce document (inventaire, parcours, diagnostic, cible, décisions, découpage) dans `.claude/tasks/ux-architecture-2026-11-09.md`.
2. Ajouter les chantiers à `.claude/tasks/todo.md`.
3. Montrer à Sylvain la liste des 22 issues et labels ; ne créer (`zsh -ic 'gh label create …'`, `gh issue create …`) qu'après son accord explicite, lot 1 d'abord ; commenter #369 et #368 pour les rattacher à la page Lunes.
4. Rappel mémoire : aucun réglage ni feature retiré sans accord (respecté : seuls `/shopping-list`, accepté, et les puces de format, à trancher, sont concernés).

## Vérification

- Chaque élément de l'inventaire renvoie à un fichier lu par les inventaires (refs dans `.claude/tasks/ux-architecture-2026-11-09.md`).
- Contrôle croisé : chaque route de `router/index.ts` et chaque onglet a une place dans la cible (aucune feature ni réglage perdu).
- Les issues ne sont créées qu'après accord explicite, avec `gh` via `zsh -ic`.
