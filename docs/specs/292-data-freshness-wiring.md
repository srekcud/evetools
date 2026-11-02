# Spec #292 : brancher DataFreshness sur GET /api/data-freshness

**Statut : validée en autonomie en mode nuit le 2026-11-03, à relire par Sylvain.**

Circuit : `circuit:m`. Source : issue #292, spec #229 (règles R1 à R7, exemples E1 à E19, décision 2 sur les écrans, validées le 2026-10-27) et spec #236 (contrat de l'endpoint). Lot frontend seulement.

## Contexte

Le composant `frontend/src/components/common/DataFreshness.vue` et le calcul du niveau (`frontend/src/utils/dataFreshness.ts`) existent (#229) mais aucun écran ne les monte et rien ne lit `GET /api/data-freshness` (#236). Cette spec ne change ni le composant, ni le niveau, ni l'endpoint : elle ajoute la lecture de l'endpoint et place le composant sur les écrans décidés en #229 (décision 2).

## Règles

- **R1 : Un seul état partagé.** Un store Pinia `dataFreshness` lit `GET /data-freshness` (collection de `{source, lastSuccessAt, expectedInterval, lastRunFailed}`, contrat #236) et expose, pour chacune des trois sources `market-jita`, `cost-indices`, `adjusted-prices`, un `DataFreshnessState` ou `null`.
- **R2 : Source absente, inconnue.** Une source absente de la réponse vaut `null` (niveau inconnu, libellé « date inconnue »). Une entrée dont `source` n'est pas l'une des trois est ignorée. Jamais de valeur par défaut plausible (pas de `new Date()`, pas de `expectedInterval` inventé).
- **R3 : Endpoint en erreur.** Si la requête échoue, les trois sources valent `null` (E16 de #229) et l'erreur n'est ni affichée en bannière ni propagée : le libellé « date inconnue » est l'état visible. Un nouvel essai a lieu au prochain chargement de l'écran.
- **R4 : Lecture au chargement de l'écran.** La requête part au montage de l'écran qui affiche la fraîcheur, une seule fois par montage d'écran (pas une requête par composant). Pas de Mercure, pas de rafraîchissement périodique des données : seul l'âge vieillit, déjà géré par le composant (R6 de #229).
- **R5 : Écrans (décision 2 de #229).**
  - **Industry** : les trois sources (`market-jita`, `cost-indices`, `adjusted-prices`) sont affichées au-dessus du contenu des quatre onglets d'analyse (`margins`, `batch`, `buy-vs-build`, `pivot`) et seulement sur ceux-là ; pas sur dashboard, projets, slots, stockpile.
  - **Market Browser** : la source `market-jita` remplace le badge vert pulsant fixe « Sync Jita » (clé `market.syncJita`, supprimée des deux locales si plus utilisée).
  - **Appraisal** : la source `market-jita` s'affiche à côté de la ligne de prix de structure existante.
  - **Profit Margins** : le « Dernière mise à jour » basé sur l'heure de la requête du navigateur (`lastFetchedAt`, clé `industry.costEstimation.lastUpdated` si plus utilisée) est retiré.
- **R6 : Composants.** `DataFreshness` reste inchangé. Un composant `DataFreshnessList` (props : liste de sources) lit le store via l'état partagé et monte un `DataFreshness` par source ; la requête de R4 part du composant d'écran (Industry, MarketBrowser, Appraisal), pas de `DataFreshnessList`.

## Exemples

Maintenant : `2026-10-31T12:00:00Z`. Réponse type : `market-jita` réussie à 11:18 sans échec, `cost-indices` réussie à 05:30 avec échec, `adjusted-prices` jamais réussie.

| # | Entrée | Rendu | Niveau |
|---|---|---|---|
| W1 | réponse type, onglet `margins` d'Industry | 3 badges : « Jita prices · 42min ago », « Cost indices · 6h ago · last sync failed », « Adjusted prices · unknown date » | fresh, stale, unknown |
| W2 | réponse type, onglet `dashboard` d'Industry | aucun badge de fraîcheur | |
| W3 | endpoint en erreur 500, onglet `buy-vs-build` | 3 badges « <source> · unknown date » | unknown ×3 |
| W4 | réponse avec seulement `market-jita` | `cost-indices` et `adjusted-prices` : « unknown date » | unknown |
| W5 | réponse avec une quatrième entrée `source: "wallet"` | ignorée, trois badges | |
| W6 | Market Browser, réponse type | un badge `market-jita` ; plus de « Sync Jita » fixe | fresh |
| W7 | Appraisal, réponse type | un badge `market-jita` près de la ligne structure | fresh |
| W8 | Industry, passage `margins` puis `pivot` | une seule requête `/data-freshness` | |
| W9 | Profit Margins après une analyse réussie | plus de « Dernière mise à jour » | |

## Hors périmètre

Cost Estimation d'un projet et liste d'achats (v2 de #229), Mercure, rafraîchissement périodique des données, modification du composant `DataFreshness`, de `dataFreshnessLevel` ou de l'endpoint, vue admin du scheduler.

## Glossaire

Aucun terme ajouté : « Fraîcheur », « Dernière sync réussie », « Sync globale » existent (#229, #236).
