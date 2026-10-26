# Spec #229 — Fraîcheur des données sur les écrans de décision

## Contexte

Les écrans de décision industrie et marché (Batch Scan, Buy vs Build, Profit Margins, Pivot Advisor, Market Browser, Appraisal) calculent sur des prix Jita, des cost index et des adjusted prices issus de syncs globales planifiées. Aucun de ces écrans n'affiche l'âge de ces données. Un utilisateur peut donc décider d'un achat ou d'une production sur des prix vieux de plusieurs heures (sync en échec, budget d'erreurs ESI épuisé) sans le savoir.

Deux affichages trompent même aujourd'hui :

- Market Browser porte un badge vert pulsant « Sync Jita » fixe, quel que soit l'état de la sync (`frontend/src/views/MarketBrowser.vue:166-169`).
- Profit Margins et Cost Estimation affichent « Dernière mise à jour » avec l'heure de la requête du navigateur (`lastFetchedAt = new Date()`, `ProfitMarginTab.vue:327`), pas l'âge des prix.

### Ce que l'API expose déjà (vérifié au 2026-10-26)

| Donnée | Horodatage côté backend | Exposé à un utilisateur ? |
|---|---|---|
| Prix structure (par structure) | `StructureMarketService::getLastSyncTime()` (méta, TTL 2 h) | Oui : `structureLastSync` de `ShoppingListResource` et `ParseResultResource`, affiché dans Appraisal et la liste d'achats |
| Prix Jita | `JitaMarketService::getLastSyncTime()` (méta, TTL 2 h) ; `SyncTracker` (`market-jita`) | **Non.** `MarketService::comparePrices()` calcule `jitaLastSync`, mais aucune resource ne le porte |
| Cost index | `SyncTracker` (`cost-indices`) seulement | **Non** (admin seulement, `AdminStatsResource::$schedulerHealth`) |
| Adjusted prices | `SyncTracker` (`adjusted-prices`) seulement | **Non** (admin seulement) |

### Ce qui manque côté backend (hors périmètre du thread ui-shell)

- **M1 — Aucun endpoint utilisateur.** `SyncTracker::getAll()` n'est lu que par `AdminService` et `TriggerSyncProcessor`.
- **M2 — Pas de date de dernière sync réussie.** `SyncTracker::fail()` écrase `completed_at` avec l'heure de l'échec : `completed_at` est la fin de la dernière exécution, réussie ou non.
- **M3 — Un échec de la sync Jita est enregistré comme une réussite.** `JitaMarketService::syncJitaMarket()` attrape l'exception et rend `success: false` (`JitaMarketService.php:96-107`) ; `TriggerJitaMarketSyncHandler` appelle `complete()` sans lire `success` (`:28-36`). Le statut `ok` et l'heure de fin sont donc posés même quand aucun prix n'a été lu.
- **M4 — La méta Jita disparaît avant les prix.** La méta `syncedAt` expire après 2 h, mais `refreshPricesForTypes()` (alertes de prix, toutes les 30 min) réécrit le cache des prix avec un nouveau TTL de 2 h. Après 2 h sans sync Jita réussie, des prix restent en cache et `getLastSyncTime()` rend `null`. La source de l'âge doit donc être `SyncTracker` (état gardé 7 jours), pas cette méta.
- **M5 — Pas de temps réel pour les utilisateurs.** Le topic Mercure `admin-sync` n'est publié qu'à l'admin qui a déclenché une sync manuelle (`SyncTracker::notifyTriggeredBy()`), jamais pour une sync planifiée.

Contrat proposé pour le backend (à confirmer, voir Q3) : `GET /api/data-freshness`, `ROLE_USER`, une entrée par source globale : `source` (`market-jita`, `cost-indices`, `adjusted-prices`), `lastSuccessAt` (ISO 8601 avec décalage, ou `null`), `expectedInterval` (secondes, lu dans `SyncTracker::EXPECTED_INTERVALS`), `lastRunFailed` (booléen). Ni message d'erreur, ni déclencheur, ni `started_at`.

## Règles métier

- **R1 — Âge.** L'âge d'une source est `maintenant − dernière sync réussie`, calculé dans le navigateur. Un âge négatif (horloge du navigateur en retard sur le serveur) vaut 0.
- **R2 — Niveau de fraîcheur.** Rapporté à l'intervalle de sync de la source (`SyncScheduler`) :
  - **frais** : âge ≤ 1,5 × intervalle ;
  - **vieux** : 1,5 × intervalle < âge ≤ 3 × intervalle ;
  - **périmé** : âge > 3 × intervalle ;
  - **inconnu** : pas de dernière sync réussie connue, ou état des syncs illisible (endpoint en erreur).
  Bornes incluses à gauche, comme `SyncTracker::computeHealth()` (seuils à valider, Q1).
- **R3 — Échec de la dernière exécution.** Si la dernière exécution a échoué, l'âge reste celui de la dernière réussite, le niveau vaut au moins **vieux**, et le libellé porte la mention « dernière sync en échec ».
- **R4 — Inconnu explicite.** Une date absente n'est jamais remplacée par une valeur plausible (heure de la requête, 0, « à l'instant »). Le niveau inconnu a son propre rendu, jamais celui de frais.
- **R5 — Libellé.** `<source> · <âge relatif>`. L'âge relatif suit la locale : moins d'1 min → « a l'instant » / « just now » ; moins de 60 min → minutes entières ; moins de 24 h → heures entières ; au-delà → jours entiers. Toujours arrondi à l'inférieur. Une infobulle donne la date absolue au format de date choisi (`formatDateTime`, eu/us), dans le fuseau du navigateur.
- **R6 — Vieillissement à l'écran.** Libellé et niveau se recalculent chaque minute sans recharger les données.
- **R7 — Une sync en cours ne change rien.** L'âge et le niveau restent ceux de la dernière réussite.
- **R8 — Un seul formateur d'âge relatif.** `formatTimeSince` (`useFormatters.ts:68`) devient traduit (il affiche aujourd'hui « 5m ago » en français) et remplace les trois copies locales identiques `formatRelativeTime` d'`Appraisal.vue:367`, `ShoppingTab.vue:358` et `ShoppingListResults.vue:110`.

Sources et libellés :

| Source | Intervalle (`SyncScheduler`) | Libellé FR | Libellé EN |
|---|---|---|---|
| `market-jita` | 1 h | Prix Jita | Jita prices |
| `cost-indices` | 2 h | Cost index | Cost indices |
| `adjusted-prices` | 24 h | Prix ajustés | Adjusted prices |

## Exemples chiffrés

Maintenant : `2026-10-26T12:00:00Z`. Libellés construits sur les clés i18n existantes `common.time.*` (« il y a {minutes}min », « {hours}h ago »…) et une clé `common.time.daysAgo` à ajouter.

| # | Source | Dernière réussite | Dernière exécution en échec | Libellé FR | Libellé EN | Niveau |
|---|---|---|---|---|---|---|
| E1 | market-jita | 11:59:30 | non | Prix Jita · a l'instant | Jita prices · just now | frais |
| E2 | market-jita | 11:18:00 | non | Prix Jita · il y a 42min | Jita prices · 42min ago | frais |
| E3 | market-jita | 10:30:00 (90 min) | non | Prix Jita · il y a 1h | Jita prices · 1h ago | frais |
| E4 | market-jita | 10:29:00 (91 min) | non | Prix Jita · il y a 1h | Jita prices · 1h ago | vieux |
| E5 | market-jita | 09:00:00 (3 h) | non | Prix Jita · il y a 3h | Jita prices · 3h ago | vieux |
| E6 | market-jita | 08:59:00 (3 h 01) | non | Prix Jita · il y a 3h | Jita prices · 3h ago | périmé |
| E7 | cost-indices | 09:00:00 (3 h) | non | Cost index · il y a 3h | Cost indices · 3h ago | frais |
| E8 | cost-indices | 07:00:00 (5 h) | non | Cost index · il y a 5h | Cost indices · 5h ago | vieux |
| E9 | cost-indices | 05:30:00 (6 h 30) | non | Cost index · il y a 6h | Cost indices · 6h ago | périmé |
| E10 | adjusted-prices | 2026-10-25T00:00:00Z (36 h) | non | Prix ajustés · il y a 1j | Adjusted prices · 1d ago | frais |
| E11 | adjusted-prices | 2026-10-24T00:00:00Z (60 h) | non | Prix ajustés · il y a 2j | Adjusted prices · 2d ago | vieux |
| E12 | adjusted-prices | 2026-10-22T12:00:00Z (96 h) | non | Prix ajustés · il y a 4j | Adjusted prices · 4d ago | périmé |
| E13 | market-jita | 11:18:00 | oui | Prix Jita · il y a 42min · dernière sync en échec | Jita prices · 42min ago · last sync failed | vieux |
| E14 | market-jita | 08:00:00 (4 h) | oui | Prix Jita · il y a 4h · dernière sync en échec | Jita prices · 4h ago · last sync failed | périmé |
| E15 | market-jita | `null` | non | Prix Jita · date inconnue | Jita prices · unknown date | inconnu |
| E16 | (toutes) | endpoint en erreur | — | `<source>` · date inconnue | `<source>` · unknown date | inconnu |
| E17 | market-jita | 12:02:00 (horloge du navigateur en retard de 2 min) | non | Prix Jita · a l'instant | Jita prices · just now | frais |
| E18 | market-jita | 11:18:00, sync en cours | non | Prix Jita · il y a 42min | Jita prices · 42min ago | frais |
| E19 | market-jita | 11:31:00 affiché à 12:00, puis 12:01 sans recharger | non | il y a 29min, puis il y a 30min | 29min ago, then 30min ago | frais |

Formateur (R8), locale FR :

| # | Entrée | Aujourd'hui (`formatTimeSince`) | Attendu |
|---|---|---|---|
| F1 | il y a 5 min | 5m ago | il y a 5min |
| F2 | il y a 3 h | 3h ago | il y a 3h |
| F3 | il y a 2 j | 2d ago | il y a 2j |
| F4 | `null` | Never | jamais |

## Cas limites

- **Date absente** : niveau inconnu, libellé « date inconnue ». Pas de `?? 0`, pas de repli sur l'heure de la requête ni sur la méta Jita (M4).
- **Redis vidé ou état `SyncTracker` expiré (7 jours)** : dernière réussite inconnue, donc niveau inconnu jusqu'à la prochaine sync réussie.
- **Horloge** : l'âge dépend de l'horloge du navigateur. Un retard donne un âge négatif ramené à 0 (E17) ; une avance vieillit les données à tort. Écart accepté en v1.
- **Fuseau** : l'API rend des dates ISO 8601 avec décalage (`format('c')`). L'âge ne dépend pas du fuseau ; seule l'infobulle affiche la date dans le fuseau du navigateur.
- **Locale** : un changement de langue retraduit libellé et âge sans recharger.
- **Prix à la demande** : un type absent du cache synchronisé est lu à la demande (cache 5 min). Il est donc plus récent que l'âge affiché : l'âge de la sync est une borne pessimiste, acceptée.
- **Sync Jita en échec enregistrée comme réussie (M3)** : tant que M3 n'est pas corrigé côté backend, E13 et E14 ne peuvent pas se produire pour `market-jita` en production.
- **Cost index et adjusted prices périmés** : au-delà de 3 intervalles, leur cache a expiré (`SYNC_INTERVALS_KEPT_IN_CACHE = 3`) et les coûts qui en dépendent sont déjà inconnus. Le niveau périmé est cohérent avec ce coût inconnu.

## Hors périmètre

- Toute modification backend (M1 à M5) : endpoint, date de dernière réussite, correction de M3. Question pour Sylvain (Q3).
- Mise à jour en direct par Mercure (M5) : en v1, les données de fraîcheur sont lues au chargement de l'écran, et seul l'âge vieillit (R6).
- Âge de chaque prix individuel ; prix structure (déjà affichés par structure) ; public contracts, alertes de prix.
- Données personnelles (assets, jobs, PI, wallet, compétences) et leurs affichages existants (`SkillsConfig.vue`, `IndustrySlotsSection.vue`).
- Vue admin du scheduler (`SchedulerHealthTable.vue`), hors le passage de `formatTimeSince` à l'i18n.

## Tests existants appelés à changer

- `frontend/tests/composables/useFormatters.spec.ts`, bloc `formatTimeSince` : « Never », « 5m ago », « 3h ago », « 2d ago » deviennent traduits (F1 à F4, et leurs équivalents EN : « never », « 5min ago », « 3h ago », « 2d ago »).

## Termes du glossaire proposés (non ajoutés)

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **Sync globale** | Global sync | Sync planifiée qui ne dépend d'aucun utilisateur et alimente un cache partagé : prix Jita, cost index, adjusted prices, public contracts… Son intervalle est fixé par `SyncScheduler`. | `SyncTracker::EXPECTED_INTERVALS` |
| **Dernière sync réussie** | Last successful sync | Fin de la dernière exécution réussie d'une sync globale. Une exécution en échec ne la modifie pas. | aucun aujourd'hui (`completed_at` de `SyncTracker` est écrasé par un échec, M2) |
| **Fraîcheur** | Data freshness | Âge de la dernière sync réussie d'une sync globale, rapporté à son intervalle : frais (≤ 1,5 ×), vieux (≤ 3 ×), périmé (> 3 ×), ou inconnu sans dernière sync réussie connue. Une dernière exécution en échec la rend au moins vieille (spec #229). | à créer : composant `DataFreshness`, fonction de niveau côté front |

Incohérence relevée au passage : le glossaire donne aux cost indices une durée de vie en cache de 2 h et aux adjusted prices 24 h ; le code garde 3 intervalles (`EsiCostIndexService::SYNC_INTERVALS_KEPT_IN_CACHE`), soit 6 h et 72 h.

## Questions pour Sylvain (gate 🚦)

1. **Seuils et échec.** Recommandation : frais ≤ 1,5 ×, vieux ≤ 3 ×, périmé > 3 × l'intervalle, comme `SyncTracker::computeHealth()` (une seule définition de la santé d'une sync dans le projet ; 3 × est aussi la durée de vie en cache des cost index et adjusted prices). L'issue proposait l'ambre dès 2 intervalles et le rouge à tout échec ; je propose qu'un échec rende la donnée au moins **vieille** (ambre) et garde le rouge pour **périmé**, parce qu'un échec survenu 10 min après une sync réussie laisse des prix encore exploitables. D'accord ?
2. **Écrans couverts en v1.** Recommandation : en-tête d'Industry pour les quatre onglets d'analyse (Profit Margins, Batch Scan, Buy vs Build, Pivot Advisor : prix Jita, cost index, adjusted prices), Market Browser (prix Jita, à la place du badge vert fixe), Appraisal (prix Jita, à côté de la ligne structure existante). On retire au passage le « Dernière mise à jour » trompeur de Profit Margins. Cost Estimation d'un projet et liste d'achats en v2. D'accord ?
3. **Composant `DataFreshness` et dépendance backend.** Le composant a besoin d'un champ que l'API n'expose pas (M1, M2) et d'une correction backend (M3), hors périmètre du thread ui-shell. Recommandation : un lot backend préalable (endpoint `GET /api/data-freshness` au contrat ci-dessus, date de dernière réussite dans `SyncTracker`, correction de M3) confié à un thread backend ; ui-shell démarre en parallèle sur ce qui ne dépend pas de l'API (fonction de niveau, formateur R8, composant testé sur des données injectées), puis branche l'endpoint. Mercure reste hors v1. Quel thread prend le lot backend, ou faut-il en faire une issue séparée ?
