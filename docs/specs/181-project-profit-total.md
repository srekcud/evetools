# Spec #181 — Un seul total de profit des projets

## Contexte

Trois totaux coexistent (main f2f3f5a) :

- liste : `ProjectCollectionProvider:38-46` somme `IndustryProject::getProfit() ?? 0` (valeurs saisies seules, usage personnel inclus, tous projets), affiché par `ProjectTable.vue:579` même quand le tableau ne montre que les projets actifs (`displayedProjects`, prop `activeOnly`) ;
- lignes : profit du mapper (`IndustryResourceMapper`), repli sur les estimations, null si un coût est inconnu (#183) ;
- dashboard : `IndustryDashboard.vue:42-44` somme `p.profit ?? 0` des projets actifs.

Décision de Sylvain (2026-10-23) : total = somme des profits **connus** des lignes, hors usage personnel, avec « N projets au profit inconnu exclus » ; même règle pour la liste et le dashboard.

## Règles métier

- **R1 — Règle unique.** Total = Σ `profit` des projets donnés dont `personalUse` est faux et `profit` non null. Projets exclus pour profit inconnu = ceux dont `personalUse` est faux et `profit` null. Les projets à usage personnel ne comptent ni dans le total ni dans les exclus.
- **R2 — Une seule implémentation.** Fonction front `projectProfitTotal(projects)` → `{ total: number, unknownProfitCount: number }` dans `frontend/src/utils/` ; le tableau l'applique aux lignes qu'il affiche (`displayedProjects`), le dashboard aux projets actifs.
- **R3 — Affichage.** Sous le total : « N projet(s) au profit inconnu exclu(s) » quand N > 0 (clés i18n FR/EN nouvelles), rien sinon.
- **R4 — API.** `ProjectListResource::$totalProfit` et son calcul dans `ProjectCollectionProvider` sont supprimés (seul consommateur : le front, qui ne le lit plus) ; le store ne garde plus `totalProfit`.

## Exemples

| # | Projets (profit, usage personnel) | Attendu |
|---|---|---|
| E1 | (1 000 000, non), (−200 000, non) | total 800 000, exclus 0 |
| E2 | (1 000 000, non), (null, non), (null, non) | total 1 000 000, exclus 2 |
| E3 | (1 000 000, non), (500 000, oui), (null, oui) | total 1 000 000, exclus 0 |
| E4 | aucun projet | total 0, exclus 0 |
| E5 | Tableau `activeOnly`, un projet actif (300 000) et un terminé (700 000) | total affiché 300 000 (aujourd'hui : total serveur de tous les projets) |
| E6 | Dashboard, actifs (1 000 000, non), (null, non) ; terminé (5 000 000, non) | 1 000 000, « 1 projet au profit inconnu exclu » |

## Hors périmètre

Calcul du profit d'une ligne (#183), profit de l'entité, Group Industry (`DistributionTable.vue`).

## Glossaire

Aucun ajout.
