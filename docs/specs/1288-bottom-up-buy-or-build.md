# #1288 — Acheter ou fabriquer par intermédiaire, de bas en haut, coûts livrés

Circuit `l`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype (`propagate`, `computeBB`, `bbView` de `#acheter`). Back-end seulement : l'écran est l'étape 3 du wizard (#1289, #1290), il lira cette ressource. L'ancienne comparaison plate (#343, `IntermediateBuyOrBuildComparator`, `GET /buy-or-build`, `ProjectBuyOrBuild.vue`) reste intacte : l'écran du flux la remplacera, la retirer est à l'accord de Sylvain.

## Contexte

Le plan d'un projet (`ProductionPlanning::planWithStructuresOf`) donne, par job, les matières déjà corrigées du ME du blueprint et du bonus de structure (`Job::$materials`), la station du job (`PlannedProduction::structureChoiceFor`, choix de #1285) et les intermédiaires. Un achat d'intermédiaire = son type dans la blacklist du projet (`IndustryProject::getBlacklistTypeIds`, bascule existante `PATCH blacklistTypeIds`), le plan recalcule alors les quantités en aval. Le marché d'un achat est `IndustryProjectBomLine::getSourcingMarket` (#1287).

## Règles

R1. **Univers** : plan « tout fabriqué » = plan du projet avec pour seule blacklist les types résolus de `blacklistGroupIds` (achats imposés, jamais décidés : pas d'option fabriquer). Les `blacklistTypeIds` du projet ne sont plus une contrainte du plan mais le choix courant (R7). Lignes : les feuilles du plan et les intermédiaires ayant au moins un job. Un intermédiaire dont le stock couvre toute la demande n'a pas de ligne ; quand un job le consomme, il est chiffré comme un achat Jita (valeur d'opportunité, `unitAt`).

R2. **Options d'une ligne**, chacune avec un coût livré total sur la demande de l'univers (`demand` pour un intermédiaire, quantité de feuille sinon), réparti sur les stations des jobs consommateurs (R3) :
- `jita` : prix pondéré du carnet pour la quantité (`MarketPrices`, couverture partielle conservée comme `JitaPurchase::priceCostOf`) plus l'import jusqu'à chaque station (`JitaPurchase::importCostOf` avec le hub de courier).
- `structure` (marché de la capitale, le marché structure du lecteur, #1287) : quantité × `sell_min` plus le pont jusqu'à chaque station (même calcul que `MaterialSourcingComparator`, extrait dans `Delivery::bridgeCostOf` et repris par le comparateur sans changer ses résultats). Éligible seulement si `sellVolumesFor` connu et au moins la quantité.
- `build` (intermédiaires avec job uniquement) : somme sur les jobs de l'intermédiaire de [matières du job × coût livré par unité de chaque matière à la station du job (`unitAt`, R3) + installation du job à sa station] ; plus le pont, par unité, de la station du job jusqu'à chaque station consommatrice. Tous les runs entiers comptent, surplus compris, le coût par unité divise par la demande (règle #343 R2/R3, aucun crédit pour le surplus).
- Installation : `StructureTotalCostComparator::installationOf(Recipe, Runs, ProductionStructure, alpha=false)` (le calcul privé de `breakdownOf`, rendu public, mêmes résultats) = EIV des prix ajustés × cost index du système de la station × bonus de coût de la structure, taxe d'installation. Station absente du job : `MissingData::costIndex()`.

R3. **Destination et bas en haut** : la station d'un job consommateur est la structure de son `StructureChoice` ; absente : destination inconnue (import et pont inconnus, `jumpRoute`). `unitAt(type, station)` = coût livré par unité de l'option retenue de `type` à `station`, calculé récursivement des matières de base aux intermédiaires puis aux produits (les jobs des cibles chiffrent leurs matières comme les autres). Le choix d'un intermédiaire compare les totaux de R2 sur toutes ses stations consommatrices ; un intermédiaire n'a donc qu'un choix, jamais un par station. Mémoïsation par `(type, station)` ; prix, volumes et stocks lus une fois pour tout l'univers.

R4. **Conseillé** : l'option éligible la moins chère (coût total connu ; `structure` en plus avec stock suffisant) ; égalité : `build`, puis `jita`, puis `structure`. Aucune éligible : `recommended = null` et coût livré inconnu avec les `MissingData` de toutes les options, jamais 0.

R5. **Choix demandé** : `buys: array<int,bool>` (vrai = acheter, faux = fabriquer) et `markets: array<int,SourcingSide>` (marché explicite d'un achat). Choix effectif d'un intermédiaire : acheter → `markets[type]` sinon le conseillé parmi `jita`/`structure` ; fabriquer → `build` ; non demandé : le conseillé. Un choix explicite est suivi même inéligible (comme #1287 R6, l'écran le signale). Une feuille n'a que `jita`/`structure`, effectif = `markets[type]` ou conseillé.

R6. **Recalcul des quantités** : l'ensemble acheté `B` = intermédiaires dont le choix effectif n'est pas `build` ; le plan est recalculé avec la blacklist de R1 plus `B` (fermeture fournie par l'appelant). Chaque ligne porte la quantité du plan recalculé (feuille : quantité à acheter ; intermédiaire fabriqué : demande, runs, surplus ; acheté : sa quantité de feuille) ; une ligne à quantité nulle (ancêtre acheté) est omise. Les décisions fabriquer/acheter sont celles du passage sur l'univers. Seuls le marché des achats (éligibilité du stock de la capitale) est réévalué à la quantité recalculée ; les montants d'une ligne = coût livré par unité de l'univers (total ÷ demande de l'univers) × quantité recalculée.

R7. **Totaux** sur le plan recalculé : `purchases` (somme des lignes achetées au choix effectif, avec leur part de transport), `installation` (tous les jobs du plan recalculé, produits finaux compris), `transport` (ponts des intermédiaires fabriqués) et `total` ; chacun `null` dès qu'une part est inconnue, avec `missingData`. Un montant n'est jamais 0 par défaut.

## Exemples chiffrés (test rouge, taux hub 800 ISK/m³, pont 0)

| Cas | Données | Résultat |
|---|---|---|
| A. Réaction moins chère à acheter | Fernite : 3 runs ×100, matières 22 000 000 + install 500 000 contre Jita 28 000 000 | `build` 22 500 000 < `jita` 28 000 000 : conseillé `build` ; si le carnet Jita donne 20 000 000 : `jita`, le plan recalculé supprime le job et ses matières |
| B. Capitale sans stock | Option `structure` moins chère mais stock en vente < quantité | non éligible, conseillé `jita` ; choix explicite `structure` suivi, `eligible=false` |
| C. Intrants au meilleur choix | Composant dont la matière réaction est mieux achetée que fabriquée | la matière est chiffrée à son option retenue, pas au prix du marché plat |
| D. Deux stations consommatrices | Composant consommé à S1 (60) et S2 (40), pont 10 ISK/m³, 1 m³/u | transport de l'option fabriquer = 60×0 + 40×10, un seul choix |
| E. Prix absent | Pas de prix Jita | option `jita` inconnue, `structure` seule éligible, sinon `recommended=null` et coût inconnu |

## API

`GET /industry/projects/{projectId}/sourcing-decisions` (`ProjectSourcingDecisionsResource`, `uriVariables` avec `Link`, `skip_null_values=false`, `assertAcceptedMember`, plan du propriétaire, 422 si le plan échoue). Requête facultative `buy=<ids>` et `build=<ids>` (entiers séparés par des virgules, ce calcul seulement, rien n'est écrit) ; sans elle, le choix courant : types de la blacklist du projet = acheter, marché = `sourcingMarket` des lignes de BOM. Corps : `id`, `rows` (par ligne : `typeId`, `typeName`, `stage` `base|reaction|component|hull`, `quantity`, `activity`, `runs`, `surplus`, `consumerStructureIds`, `options` `{build?, jita, structure}` chacune `{total, perUnit, price, installation, transport, eligible, availableQuantity?, structureId?, missingData}`, `recommended`, `chosen`, `bought`), `totals`, `boughtTypeIds`. Montants arrondis à 2 décimales ; inconnu = `null`. Lecture seule : pas de Mercure.

## Hors périmètre / décisions

- Écran (étape 3 du wizard), écriture du choix (la bascule `PATCH blacklistTypeIds` existe), suppression de #343 : non.
- Le stock d'intermédiaire apporté n'est pas valorisé dans les lignes (R1) ; le temps de transport n'entre pas dans le coût.
- Marché de la capitale d'un intermédiaire : même réglage que #1287, aucun nouveau champ, aucune migration.

## Glossaire

Ajouter : **Décision acheter ou fabriquer (bas en haut)**, **Coût livré** (prix + transport jusqu'à la station qui consomme), **Station consommatrice**.
