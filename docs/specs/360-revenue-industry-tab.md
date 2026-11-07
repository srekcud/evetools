# #360 : onglet Industrie du module Revenus

Maquette : `industry-project-v3.html#p=39` (note 29 « Revenus › Industrie »). Groupe `unified-industry`. Mode nuit : validée en autonomie.

## Contexte

- Le module Revenus (`views/Ledger.vue`, `GET /api/ledger/dashboard`, `LedgerDashboardProvider`) couvre PVE et minage. Onglets actuels : dashboard, pve, mining, escalations, settings. Période : `days` (7 à 90, YTD, tout).
- Le profit réel d'un projet existe déjà : `ProjectDistributionService::calculateDistribution` (revenu des ventes, frais, coût des apports approuvés, marge, part de chaque participant : `ParticipantDistribution::$totalCostsEngaged`, `$profitPart`). Il est `null` dès qu'une donnée manque (frais, valeur d'apport). Glossaire : Marge, Payout, Job lié (job ESI d'un personnage du **propriétaire**).
- Un projet terminé : `ProjectStatus::Completed`, `IndustryProject::getCompletedAt()`. Jobs liés : `IndustryProjectBomLine::getJobMatches()`, durée dans `CachedIndustryJob` (`startDate`, `endDate`, retrouvé par `esiJobId` = `jobId`, `CachedIndustryJobRepository::findByJobIds`).

## Règles

1. **Périmètre** : projets de l'utilisateur connecté (participant `accepted`, propriétaire compris), statut `completed`, `completedAt` dans la période `[maintenant − days, maintenant]`. Aucune donnée d'un autre utilisateur ne sort : la part d'un participant est la sienne, jamais celle des autres.
2. **Profit d'un projet pour l'utilisateur** : `ParticipantDistribution::$profitPart` de sa ligne (projet solo : le profit entier ; groupe : sa part de marge). `null` si la répartition est impossible, si une valeur d'apport ou les frais manquent, ou si l'utilisateur n'a aucun apport approuvé : le projet est alors **à profit inconnu** (jamais 0).
3. **Capital engagé** d'un projet : `$totalCostsEngaged` de l'utilisateur (coût de production qu'il a apporté). Compté seulement pour les projets à profit connu.
4. **Heures de slot** d'un projet : somme, sur les jobs liés du projet qui appartiennent à un personnage de l'utilisateur (nom du personnage du lien parmi ses personnages), de `endDate − startDate` en heures. Un job lié absent du cache : heures du projet **inconnues** (`null`). Un projet dont l'utilisateur n'a aucun job lié (participant non propriétaire) : 0 heure.
5. **Mois** : mois civil UTC de `completedAt` (`YYYY-MM`), du plus récent au plus ancien. Par mois : `projectCount` (tous les projets du mois, inconnus compris), `unknownProjectCount`, `profit` (somme des profits connus), `capital` (somme des capitaux des projets à profit connu), `marginPercent` = `profit / capital × 100` (marge unique du module ; `null` si capital 0), `slotHours` (somme des heures connues des projets à profit connu ; `null` si aucun projet à profit connu n'a d'heures connues), `iskPerHour` = profit des projets à profit connu **et** heures connues > 0, divisé par leurs heures (`null` s'il n'y en a aucun).
6. **Par produit** : un projet à produit unique compte sur la ligne de son produit (`typeId`, `typeName`) ; un projet à plusieurs produits compte sur une ligne unique « multi-produits » (`typeId` et `typeName` `null`), pas de répartition inventée. Par ligne : `projectCount`, `unknownProjectCount`, `profit` (somme des connus). Ordre : profit décroissant, puis nom.
7. **Totaux** de la période : `profit` (somme des profits connus), `projectCount`, `unknownProjectCount`, `capital`, `slotHours`, `iskPerHour` (mêmes règles que R5 sur toute la période).
8. **Ressource** `LedgerIndustry` : `GET /api/ledger/industry?days=` (défaut 30, comme le dashboard), `security: is_granted('ROLE_USER')`, `skip_null_values=false`, 401 sans authentification. Corps : `id`, `period {from, to, days}`, `totals`, `months`, `products`. Calculée à la lecture, rien de persisté ; pas de migration.
9. **Vue d'ensemble** : `GET /api/ledger/dashboard` gagne `industryBreakdown {profit, projectCount, unknownProjectCount}` et `industryPercent`, et `totals.industry` (profit connu de R7, `0.0` sans projet). `totals.total` = pve + minage + industrie (toujours `null` si le minage est inconnu), `profit` = total − dépenses, `iskPerDay`, `pvePercent`, `miningPercent` sur ce nouveau total ; `industryPercent` = `industry / total × 100` (0.0 si total ≤ 0, `null` si total `null`). Sans projet terminé les chiffres existants sont inchangés.
10. **Front** : onglet « Industrie » (`ledger.tabs.industry`) entre Minage et Escalations ; `LedgerIndustryTab.vue` (props `selectedDays`), store `ledger.ts` (`fetchIndustry(days)`, requêtes périmées ignorées, type `LedgerIndustry`). Carte « Profit réel par mois » : barres (CSS, une par mois, profit négatif en rouge) puis tableau Mois · Projets · Profit · Marge · Heures de slot · ISK/h · Capital engagé ; carte « Par produit » : Produit · Projets · Profit. Une valeur `null` s'affiche `UnknownValue` (violet pointillé), jamais 0 ; un mois avec projets inconnus affiche « dont N à profit inconnu, non compté ». État vide : « Aucun projet terminé sur la période ». Direction HUD EVE (composants existants `IskAmount`, `UnknownValue`). Noms de produit longs tronqués avec titre complet. FR et EN.
11. **Vue d'ensemble, front** : `LedgerDashboardTab.vue` ajoute une carte KPI « Industrie » (profit connu, nombre de projets, mention des projets à profit inconnu) et sa part dans la répartition. Le graphique quotidien (`/ledger/stats/daily`) reste PVE et minage : le profit industrie est un bilan mensuel, pas un flux journalier.
12. Mercure : aucune action asynchrone ajoutée.

## Exemples

| # | État | Résultat |
|---|---|---|
| E1 | Solo terminé le 3 nov., profit 120 M, capital 400 M, jobs liés 100 h | mois 2026-11 : projets 1, profit 120 M, marge 30 %, 100 h, 1,2 M ISK/h, capital 400 M |
| E2 | Même mois, second projet à profit connu 30 M, capital 100 M, jobs liés 50 h | profit 150 M, capital 500 M, marge 30 %, 150 h, 1 M ISK/h |
| E3 | Troisième projet du mois à profit inconnu | projets 3, `unknownProjectCount` 1, profit et capital inchangés |
| E4 | Projet de groupe, l'utilisateur a apporté 100 M sur 400 M, profitPart 12 M | profit 12 M, capital 100 M, 0 h (non propriétaire), `iskPerHour` null s'il est seul |
| E5 | Un job lié absent du cache | heures du projet null, ISK/h du projet exclu, profit conservé |
| E6 | Deux produits sur un projet | ligne « multi-produits », profit entier |
| E7 | Projet terminé hors période, projet en cours, projet d'un autre utilisateur | absents |
| E8 | Capital 0 | marge null |
| E9 | Dashboard : pve 5 M, minage 1 M, industrie 2 M, dépenses 0,5 M | total 8 M, profit 7,5 M, `industryPercent` 25 |
| E10 | Dashboard sans projet terminé | `totals.industry` 0.0, total et pourcentages comme avant |

## Hors périmètre

Skills et Skill Planner (#359), graphique journalier, export, comparaison à un projet de référence, répartition d'un projet multi-produits par produit, heures de slot des jobs d'un autre propriétaire.
