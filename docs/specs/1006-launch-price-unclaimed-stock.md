# 1006 lot 1 : prix de lancement figé et stock non réclamé (lecture seule)

Décision de Sylvain (2026-11-15 et 2026-11-16, option A) : #1006 est découpée en trois lots. Ce lot ne pose que le socle en lecture seule. Réclamation, assignation et don : #1050. Coût inconnu, marge incomplète, répartition bloquée : #1051.

## Règles

1. **Prix de lancement par ligne matériau.** `IndustryProjectBomLine::$launchPrice` (`launch_price`, float nullable). Il est écrit une seule fois, au passage du projet à `in_progress` (depuis `draft` ou `published`), par `IndustryProjectService::freezeLaunchPrices`.
2. **Source du prix** : le 5e percentile des ordres de vente Jita, `JitaMarketService::getCheapestPercentilePrices` au percentile `ProjectContributionService::DEPOSIT_DAY_PERCENTILE` (0,05, le même que le prix du jour d'apport, rendu public pour être partagé).
3. Une ligne de job n'a jamais de prix de lancement. Une ligne matériau dont le prix Jita est absent ou nul garde `launchPrice = null` : jamais 0, jamais de prix de repli.
4. Le prix figé ne bouge plus : `buildBom` et `reconcileBomLines` ne le touchent pas, et un nouveau passage ne le réécrit pas. Une ligne ajoutée après le lancement reste sans prix de lancement (« inconnu »).
5. **Projet déjà en cours sans prix figé** (créé avant la migration) : aucun rattrapage, `launchPrice` reste `null`.
6. **Quantité non réclamée d'une ligne matériau**, calculée en continu à chaque lecture de la BOM, rien de persisté :
   `non réclamée = max(0, min(quantité en container, quantité requise) − quantités des contributions matériau de la ligne en statut pending ou approved)`.
   Les contributions dérivées (achats liés, `isAutoDetected`) comptent comme les autres, les rejetées jamais. Les contributions d'un autre type que `material` ne comptent pas.
7. La quantité non réclamée est `null` quand le stock du container n'est pas connu (`ContainerStockState` ≠ `Known`), pour une ligne de job, et pour un projet qui n'est ni `in_progress` ni `selling` : pas de valeur inventée.
8. **Ressource** `ProjectBomLineResource` : `unclaimedQuantity: ?int`, `launchPrice: ?float`, `unclaimedValue: ?float` (= quantité non réclamée × prix de lancement, `null` si l'un des deux est `null`).
9. **Badge, lecture seule** (`UnclaimedStockBadge.vue`, dans la cellule du nom de `BomTable.vue`) : visible quand `unclaimedQuantity > 0`. Avec un prix de lancement : « Non réclamé : N · valeur ISK au prix de lancement ». Sans : « Non réclamé : N · prix de lancement inconnu ». Aucun bouton ni action. i18n FR et EN.

## Exemples

| Requis | Container | Contributions pending + approved | Non réclamée |
|---|---|---|---|
| 100 | 80 | 0 | 80 |
| 100 | 80 | 30 | 50 |
| 100 | 150 | 0 | 100 |
| 100 | 80 | 90 | 0 |
| 100 | inconnu | 0 | `null` |

Prix de lancement 12,5 ISK et 50 non réclamées : valeur 625 ISK.

## Cas limites

- Container vide ou ligne absente du container : 0 non réclamée, badge masqué.
- Marché Jita sans ordre pour un type : ligne sans prix de lancement, les autres lignes sont figées.
- Un projet qui passe à `selling` ou `completed` ne réécrit rien.

## Hors périmètre

Réclamation (« depuis mon stock »), assignation par le propriétaire, don déclaré à 0 (#1050) ; coût du projet, coût unitaire, point mort, marge incomplets, répartition bloquée (#1051) ; produits intermédiaires (`IntermediateStockProposer`).

## Décisions prises en autonomie

- Stock non réclamé calculé en continu (décision de Sylvain du 2026-11-16).
- Projet en cours sans prix figé : « prix de lancement inconnu » (décision de Sylvain du 2026-11-16).
- Non réclamé masqué avant le lancement (`draft`, `published`) : le prix de lancement n'existe pas encore, le badge afficherait « inconnu » à tort.
- Migration `Version20261116140000` : une colonne nullable, aucune donnée à migrer.
