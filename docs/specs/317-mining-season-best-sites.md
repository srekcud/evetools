# #317 (reliquat) : meilleurs sites par système dans la saison

Maquette : `grouped-mining-v4.html`, `renderSeason`, carte « Meilleurs sites par système » (valeur extraite des scans / durée observée). Mode nuit : spec écrite par le thread.

## Déjà sur main (non traité)

Saison (KPI, heures par joueur, #317 lot 1), ISK/h par vaisseau (#364), sites liés à une opération avec scans, `closedAt`, `extractedValue` et `MiningSiteHistorySummarizer` (#267, #305). Ce lot ne touche à aucun de ces calculs.

## Règles

**Backend**
- **R1** Un site de la saison est un `MiningSite` dont l'opération fait partie des opérations de la période (même sélection que le reste de la saison, `MiningOperationRepository::findOfSeason`). Les sites sans opération n'en font pas partie. Les opérations non gelées (valeur inconnue) comptent leurs sites : la valeur d'un site vient des scans, pas du gel des parts.
- **R2** ISK/h d'un site = `extractedValue` × 3600 / `observedSeconds`, arrondi à l'entier, calculé avec `MiningSiteHistorySummarizer` sur le premier et le dernier scan visibles (scans masqués exclus). Inconnu (null, jamais 0) si le site a moins de 2 scans visibles, si `observedSeconds` vaut 0 ou si `extractedValue` est null. Une valeur extraite négative (prix en hausse entre deux scans) reste une valeur connue, brute.
- **R3** Regroupement par `solarSystemId`. Un site sans système est écarté du tableau et compté dans `sitesWithoutSystemCount`.
- **R4** Meilleur site d'un système = ISK/h connu le plus haut ; égalité : durée observée la plus longue, puis libellé alphabétique. Aucun site connu : le système a `bestSite` null, jamais 0.
- **R5** Chaque système porte `sitesCount` (tous ses sites, connus ou non) et `unknownSites` : liste `{label, reason}`, `reason` = `fewScans` (moins de 2 scans visibles) ou `noPrice` (2 scans ou plus, valeur extraite inconnue). Le meilleur site n'y figure pas.
- **R6** `GET /api/mining-operations/season` ajoute `sitesBySystem` : liste de `{solarSystemId, solarSystemName, sitesCount, bestSite: ?{label, iskPerHour, observedSeconds}, unknownSites}` triée par ISK/h du meilleur site décroissant, systèmes sans meilleur site à la fin, puis `solarSystemId` croissant ; et `sitesWithoutSystemCount`. Nom du système lu dans le SDE (`MapSolarSystemRepository`) ; id absent du SDE : `solarSystemName` null (le front affiche « Système {id} »).
- **R7** Accès inchangé : corporation actuelle de l'utilisateur, opérations de visibilité corporation, aucun identifiant d'utilisateur ni de site exposé, seulement le libellé du site.
- **R8** Pas de N+1 sur les scans : une requête par lot de sites pour les premier et dernier scans visibles (repository), pas une requête par site.

**Frontend**
- **R9** `MiningSeasonPanel` ajoute une carte `season-sites` « Meilleurs sites par système » à côté des vaisseaux (colonnes Système, Meilleur site, ISK/h, Durée, Sites) ; ligne `season-site-row` par système. Meilleur site absent : « inconnu » en ambre, jamais 0 ; sous le libellé, indication « inconnu : {libellé} ({raison}) » par site de `unknownSites`, raisons « sans prix » et « un seul scan » (aussi pour 0 scan). Durée formatée avec `formatSeasonDuration`.
- **R10** Sans aucune ligne : texte `season-sites-empty` « Aucun site scanné sur la période ». Si `sitesWithoutSystemCount` > 0 : note `season-sites-no-system` avec le nombre. La carte est masquée tant que la saison n'a aucune opération (le texte vide existant reste).
- **R11** Type `MiningSeason` et i18n FR et EN (`miningOperation.season.sites.*`). Direction design HUD EVE, libellés longs coupés (`[overflow-wrap:anywhere]`), pas de nouvelle maquette.

## Exemples chiffrés

| # | Cas | Résultat |
|---|---|---|
| E1 | Site A (système S1) : scans 20:00 valeur 3 000 000, 21:00 valeur 1 200 000 | extrait 1 800 000, durée 3600 s, ISK/h 1 800 000 |
| E2 | Site A (E1) et site B (S1) : 90 min, extrait 2 700 000 (1 800 000/h) | égalité à 1 800 000 : B (durée 5400 s) est le meilleur de S1 |
| E3 | S1 avec A (1 800 000/h) et C (900 000/h) | `bestSite` A, `sitesCount` 2, `unknownSites` vide |
| E4 | Site D (S2) à un seul scan | S2 : `bestSite` null, `unknownSites` [{D, fewScans}], `sitesCount` 1 |
| E5 | Site E (S2), 2 scans dont le dernier sans prix | `unknownSites` [{E, noPrice}] |
| E6 | Site F sans système | absent du tableau, `sitesWithoutSystemCount` 1 |
| E7 | Site de 30 min, extrait 1 000 000 | ISK/h 2 000 000 |
| E8 | Scan masqué au milieu | ignoré, le premier et le dernier scan visibles servent |
| E9 | Site d'une opération hors période ou d'une autre corporation | absent |
| E10 | Opération non gelée avec un site de 2 scans valorisés | le site compte (ISK/h connu), les KPI d'opération restent inchangés |
| E11 | Période `30d` : S1 à 1 800 000/h, S3 à 2 400 000/h | S3 avant S1 |

## Cas limites

Aucune opération ou aucun site : `sitesBySystem` vide, `sitesWithoutSystemCount` 0. Valeur extraite négative : ISK/h négatif connu, un site à ISK/h positif lui passe devant ; seul, il reste le meilleur de son système. Site clos sans `closedAt` : sans effet (la durée vient des scans).

## Tests existants contredits ou modifiés

`MiningSeasonApiTest` et `MiningSeasonPanel.spec.ts` : les assertions de forme exacte de la réponse (clés de la ressource) et le nombre de cartes sont à compléter par les nouvelles clés ; aucune assertion métier existante ne change.

## Hors périmètre

Estimation du débit par type (trend) en saison, détail des scans, filtre par système, ISK/h par type de site (non modélisé), rattrapage des sites sans système, export. Aucune migration, aucun appel ESI, aucune commande console.

## Termes du glossaire ajoutés

**Meilleur site d'un système** (Best site of a system) : site de la saison au plus haut ISK/h extrait (valeur extraite entre le premier et le dernier scan visible / durée observée), un par système ; inconnu sans site mesurable (spec #317, reliquat).

## Décisions ouvertes

Aucune. Décision prise en autonomie : valeur extraite négative gardée brute, cohérente avec #305.
