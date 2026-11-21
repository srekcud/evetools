# Spec #1140 (suite) — la couverture par le stock du container compte les matériaux consommés par les jobs lancés

Mode nuit : spec validée en autonomie. Suite de `1140-container-check-started-jobs.md` (vérification du container, mergée en #1141) : même défaut côté couverture des lignes (#866, `ProjectLineFulfillments`). Décision de Sylvain (2026-11-21) : « fix le restant ».

## Contexte

`ProjectLineFulfillments::fulfilledOf` couvre une ligne achetable à hauteur de `min(stock du container, requis)`. Une fois un job lancé, ses matériaux ont quitté le container : la ligne perd une couverture qu'elle avait (exemple de #1140 : Cadmium 38 200 achetés et livrés, 34 987 restants dans le container, 3 213 consommés par une réaction ×33, la ligne affiche 91,6 %). `ChainMaterialSupply`, le cockpit, la BOM, l'approvisionnement et les achats à amener lisent cette couverture.

## Règles

1. `ProjectLineFulfillments` gagne `consumedQuantitiesByTypeId` (`array<int, int>`, vide par défaut). Pour une ligne dont le stock du container est lisible : `fulfilledOf = max(fulfilledQuantity, min(stock du container + consommé du type, requis))`. Sans stock lisible, ou sans consommation : inchangé. `containerQuantityOf` reste le stock physique du container (affichage, stock non réclamé, achats à amener : inchangés).
2. `StartedJobConsumption` expose `quantitiesByTypeId(): array<int, int>` (lève `\LogicException` si inconnue, comme `consumedOf`).
3. `ProjectContainerFulfillment::of(project)` : stock du container lisible → `StartedJobConsumptionReader::of` ; consommation connue → `Known` avec les quantités consommées ; **inconnue → état `ContainerStockState::Unknown`** (toutes les lignes lisent « stock inconnu », jamais une couverture plausible, `ChainMaterialSupply` retombe sur son cas `null`). Stock illisible ou projet sans nom de container : le lecteur n'est pas appelé, états actuels inchangés.
4. `ProjectContainerFulfillment::ofWithJobMaterialNeeds(IndustryProject $project, array $bomLines, ?array $jobMaterialNeeds)` : même résultat que `of` sans relire ni replanifier : les lignes de job (`isJob`, groupe `component` ou `final`) viennent de `$bomLines`, la consommation de `StartedJobConsumption::of($jobLines, $jobMaterialNeeds)` (besoins `null` avec un job lancé : inconnue).
5. `ProjectChainProvider` charge les besoins une seule fois (`ProjectJobMaterialNeedsLoader::load`) et les passe à `ChainBuilder::build` **et** à `ofWithJobMaterialNeeds` ; `ProjectMaterialSourcingProvider` calcule les besoins par `JobMaterialNeedsMapper::map($bomLines, $planned->plan)` sur le plan qu'il a déjà et appelle `ofWithJobMaterialNeeds`. Les autres appelants (cockpit, BOM, achats à amener, stock non réclamé) gardent `of`. Objectif : au plus un `planOf` par requête (#1136), et aucun quand aucun job n'est lancé.

## Exemples chiffrés (`fulfilledOf` / `remainingOf`)

| # | Requis | Contribué (`fulfilledQuantity`) | Container | Consommé du type | Fulfilled | Remaining |
|---|---|---|---|---|---|---|
| 1 | 55 | 0 | 2 | 0 | 2 | 53 (existant) |
| 2 | 38 200 | 0 | 34 987 | 3 213 | 38 200 | 0 |
| 3 | 1 000 | 0 | 100 | 400 | 500 | 500 |
| 4 | 1 000 | 0 | 900 | 300 | 1 000 (plafonné) | 0 |
| 5 | 1 000 | 700 | 100 | 200 | 700 (le plus grand des deux) | 300 |
| 6 | 1 000 | 0 | 0 | 0 | 0 | 1 000 |
| 7 | 1 000 | 0 | container illisible | — | état `Unknown` | quantité de la ligne |
| 8 | 1 000 | 0 | lisible | consommation inconnue | état `Unknown`, `fulfilledOf` = contribué | — |

Fournisseur : `ProjectContainerFulfillment::of` aux cas 7 et 8 (lecteur jamais appelé au 7), `ofWithJobMaterialNeeds` avec besoins `null` et un match `active` au 8, et avec besoins connus au 2.

## Cas limites

- Ligne non achetable (job `final`, `copy`, `invention`) : `containerQuantityOf` est `null`, inchangée.
- Ligne de job `component` : même règle (le composant consommé par un job parent compte, par `typeId`).
- Aucun job lancé : `consumedQuantitiesByTypeId` vide, aucun appel au plan.

## Accès et fraîcheur

Aucun nouveau point d'entrée ni donnée nouvelle ; mêmes providers, mêmes contrôles.

## Tests existants modifiés

`ProjectChainProviderTest`, `ProjectMaterialSourcingProviderTest` (et tout test qui stubbe `ProjectContainerFulfillment` pour ces deux providers) : appel de `ofWithJobMaterialNeeds`. `ProjectContainerFulfillmentTest` : le constructeur gagne le lecteur de consommation (« aucun job lancé » dans le harnais existant).

## Hors périmètre

`UnclaimedStock`, `PurchasesToDeliverBuilder`, `ContainerDeliveryConfirmer`, propositions de stock : ils lisent le stock physique, inchangés. Pas de front : l'affichage des quantités du container reste physique.

## Termes du glossaire

Mise à jour de **Consommation des jobs lancés** : elle sert aussi à la couverture par le stock du container (`ProjectLineFulfillments`), un projet dont la consommation est inconnue lit « stock inconnu ».
