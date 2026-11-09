# #50 lot 2 : prévision de vente dans l'onglet Finances

Décision de Sylvain (2026-11-09, option B) : la prévision valorise la quantité restante à vendre de chaque produit au plus bas ordre de vente de la **structure de vente du projet** (lot 1), Jita à côté. Revenu net seulement : pas de profit prévu (aucun coût de production prévu côté API). Aucune migration, aucune action asynchrone (pas de Mercure).

## Règles

1. **Quantité restante à vendre** (règle de `ProjectDistributionService::unsoldProducts`, extraite dans `UnsoldProductsOfProject::of(IndustryProject, array $sales)` et réutilisée par la distribution, qui garde sa condition « aucune vente = attente de ventes ») : par `typeId`, somme des `requiredQuantity` des lignes BOM `isJob` du groupe `final`, moins la somme des quantités des ventes (`IndustryProjectSale`) du projet ; seuls les restes strictement positifs sont retenus. C'est la même règle que le compteur de la phase « Vente » du cockpit.
2. **Ouverture de `SellMarketComparison`** : nouvelle méthode `compareAt(User $seller, ?SellStructure $structure, int $typeId, int $quantity, \DateTimeImmutable $today)` ; la ligne `capital` est celle de la structure passée (`getLowestSellPrice(id)`, nom de `SellStructure`), absente si `null`. `compare($user, …)` délègue à `compareAt` avec `ProjectSellStructures::defaultFor($user)` : comportement, `SellMarketProvider` et `SellMarketsCard` inchangés (tests existants verts).
3. **Vendeur et frais** : les frais viennent de `SellerSalesFeesResolver::forUser($project->getOwner())` (le propriétaire vend, comme la structure de vente est la sienne par défaut ; la prévision ne change pas selon qui la lit). Frais non résolus : taux et net inconnus (nul).
4. **Ressource** `GET /industry/projects/{projectId}/sell-forecast` (`ProjectSellForecastResource`, `uriVariables` avec `Link` vers `IndustryProjectResource`, `ProjectSellForecastProvider`) : accès `ProjectAccessChecker::assertAcceptedMember` (comme la BOM) ; 401 sans utilisateur, 404 projet inconnu ou identifiant non UUID, 403 non-membre.
5. **Forme** :
   - `projectId`, `sellStructure` (`{id, name}` ou nul : celui du projet, jamais remplacé par Jita ou par la préférée de quelqu'un) ;
   - `products` : liste triée par nom, un élément par produit restant : `typeId`, `typeName`, `quantity` (restante), `structure` (nul si le projet n'a pas de structure) et `jita`, chacun `{name, unitPrice, coveragePercent, feeRate, netUnitPrice, netRevenue, daysToSell}` ; `netRevenue = quantity × netUnitPrice`, nul si `netUnitPrice` est nul ;
   - `totals` : `structureNetRevenue` et `jitaNetRevenue` : somme des `netRevenue` de la colonne, **nul dès qu'un produit est inconnu** dans cette colonne (jamais une somme partielle ni 0) ; nul aussi sans produit ni, pour la structure, sans structure ; `structureUnknownProductCount` et `jitaUnknownProductCount` donnent le nombre de produits inconnus de la colonne.
6. Projet sans structure : `sellStructure` nul, `structure` nul sur chaque produit, `totals.structureNetRevenue` nul ; Jita seul.
7. Projet sans reste à vendre (tout vendu, ou aucune ligne finale) : `products` vide, totaux nuls.
8. Les prix inconnus (aucun ordre de vente, carnet Jita vide) restent nuls dans la réponse ; rien n'est remplacé par 0 ni par un prix de vente passé.
9. **Front** : carte `SellForecastCard.vue` (`data-testid="sell-forecast-card"`) montée sous `FinancialSummary` dans l'onglet Finances (`activeTab === 'distribution'` de `GroupIndustryDetail`) ; store `stores/group-industry/sellForecast.ts` (`fetchForProject(projectId)`, `forecast`, `loading`, `error`, garde contre une réponse d'un autre projet).
   - en-tête « Prévision de vente » ; sous-titre : « Structure de vente : <nom> » ; sans structure : avis « Aucune structure de vente définie : prévision sur Jita seulement » (jamais « Jita » présenté comme la structure du projet) ;
   - tableau : produit, quantité restante, colonne structure (prix net unitaire, revenu net) sauf sans structure, colonne Jita idem ; valeur nulle = `UnknownValue` ; ligne Jita avec `PartialPriceMarker` quand `coveragePercent` est renseigné ;
   - ligne de total par colonne : somme, ou `UnknownValue` avec « N produit(s) sans prix ou frais inconnus » ;
   - mention « Revenu net prévu, hors coûts : pas de profit prévu » ;
   - aucun reste à vendre : message « Rien à vendre » ; erreur de chargement : message d'erreur dans la carte, le reste de l'onglet inchangé ; chargement : indicateur.

## Exemples (frais 4,755 % = 1,38 % courtage + 3,375 % taxe)

| Situation | Résultat |
|---|---|
| 10 requis, 4 vendus, structure à 800 000 | quantité 6, net unitaire 761 960, revenu 4 571 760 |
| Deux lignes finales du même type (3 et 2), 1 vendu | quantité 4 |
| Tout vendu | `products` vide, totaux nuls |
| Un produit sans ordre de vente en structure, un autre à 761 960 × 2 | `structureNetRevenue` nul, `structureUnknownProductCount` 1 |
| Propriétaire sans compétences synchronisées | taux, net unitaire et revenu nuls, prix gardés |
| Projet sans structure | `sellStructure` nul, `structure` nul, Jita renseigné |

## Cas limites

- Vente d'un type absent de la BOM finale : ignorée. Ventes supérieures au requis : produit non listé (reste ≤ 0).
- Structure de vente inconnue du marché (aucun ordre) : prix nul, jours selon le volume estimé.

## Hors périmètre

Profit prévu, coût de production prévu, choix d'une autre structure dans la carte, modification de `SellMarketsCard` / `ProfitMarginTab`, export Discord, tout autre onglet.

## Décisions prises en autonomie

- Vendeur = propriétaire du projet (prévision identique pour tous les lecteurs).
- Quantité restante = reste du plan (BOM finale), pas la seule quantité déjà produite : c'est une prévision de ce que le projet doit encore vendre, comme le cockpit.
- Totaux nuls dès qu'un produit est inconnu, avec le compte des inconnus, pour ne jamais présenter une somme partielle comme le revenu prévu.
