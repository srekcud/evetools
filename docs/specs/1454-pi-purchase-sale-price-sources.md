# #1454 Sources de prix d'achat et de vente du module PI

Trois lots, une branche, une PR. Le Planificateur applique aujourd'hui une seule source (`priceSource` = `jita-sell` ou `jita-buy`) aux intrants achetés et au produit vendu. Le testeur achète à l'ordre d'achat et vend à un prix de vente : le profit calculé est trois fois trop bas.

## Vocabulaire

- **Source d'achat** (`purchaseSource`) : `jita-sell`, `jita-buy`, `hub`, `paid`, `best`.
- **Source de vente** (`saleSource`) : `jita-sell`, `jita-buy`, `hub`.
- `hub` = prix de vente le plus bas du hub des réglages PI (`PlanetarySettingsResource::forUser`, #1451). `paid` = prix payé moyen pondéré des 90 derniers jours (`PlanetaryPaidPriceCalculator`, #1396).
- `best` (achat seulement) = le plus bas des prix connus parmi payé, hub et Jita sell. `null` seulement si aucun n'est connu.
- Un prix de la source choisie manquant donne `null` (inconnu), jamais 0 ni une autre source (sauf `best`, dont c'est la définition).

## Lot 1 : Planificateur et classement

Règles :
1. Catalogue API (`GET /planetary/planner`) : chaque entrée de `prices` gagne `hub: float|null` et `paid: float|null` (en plus de `sell`, `buy` Jita). Le catalogue global reste sans donnée utilisateur ; le provider ajoute `hub` et `paid` de l'utilisateur connecté (hub via `StructureMarketService::getLowestSellPrices`, payé via `PlanetaryPaidPriceCalculator::calculate`). La ressource gagne `hub: {structureId, structureName, hasData}`. Un type non acheté par le joueur ou sans ordre au hub : `null`.
2. Front, `utils/planetaryPlanner.ts` : `PriceSource` est remplacé par `PurchaseSource` et `SaleSource`. `PlannerSettings` porte `purchaseSource` et `saleSource` à la place de `priceSource`. Défaut : `jita-sell` pour les deux (comportement actuel). `unitPrice(typeId, catalog, source)` devient `purchasePrice(...)` et `salePrice(...)`.
3. `TreeNode.price` devient le prix d'achat du type (choix « acheter », étoile, achats hebdomadaires). `TreeNode.salePrice` est nouveau : prix de vente du type, base du profit de la cible et de la taxe.
4. Coût de production d'un type : intrants au coût unitaire (achat ou production), plus la taxe `taxPercent` appliquée au **prix de vente** du type. Prix de vente inconnu : coût de production inconnu (comportement actuel pour le prix inconnu).
5. Profit par jour d'une cible = `targetPerDay × (salePrice − produceCost)`. Inconnu si l'un des deux l'est.
6. Étoile (produire ou acheter) : compare `produceCost` au prix d'achat.
7. Classement (`planetaryRanking.ts`) : `decideChain` compare le coût de production d'un intermédiaire à son prix d'achat ; prix du produit classé = prix de vente ; profit et marge sur ce prix.
8. UI (`PiPlannerSettings.vue`) : deux groupes de boutons, « Source d'achat » (5 choix) et « Source de vente » (3 choix), libellés i18n FR et EN. Le libellé `hub` affiche le nom de la structure ; `paid` rappelle la fenêtre de 90 jours. Un choix dont aucun prix n'est connu (hub sans données, aucun achat payé) reste sélectionnable ; les lignes concernées s'affichent « inconnu » comme aujourd'hui.

Exemple chiffré : intrant X, Jita sell 100, hub 90, payé 80, Jita buy 70. Produit P : Jita sell 1 000, hub 950, Jita buy 900, taxe 4 %, recette 10 X pour 1 P.
- achat `jita-buy`, vente `jita-sell` : coût 10×70 + 40 = 740, profit unitaire 260.
- achat `best`, vente `hub` : X = min(80, 90, 100) = 80 ; coût 10×80 + 4 % de 950 = 838, profit unitaire 112.
- achat `paid` sans achat connu : coût inconnu, profit inconnu.

Cas limites : `best` avec un seul prix connu prend ce prix ; la taxe d'un P1 produit (coût = taxe seule) suit le prix de vente ; le prix de vente d'un P0 n'est jamais utilisé.

## Lot 2 : persistance dans les gabarits

9. `settings` d'un gabarit : `{purchaseSource, saleSource, taxPercent, daysBetweenRounds}`. `CreatePlanTemplateProcessor` valide les deux sources (listes ci-dessus) ; l'ancienne clé `priceSource` n'est plus acceptée à l'écriture.
10. Lecture des gabarits existants (`settings.priceSource` seul en base) : `PlanetaryPlanTemplateResource::fromEntity` renvoie `purchaseSource = saleSource = priceSource`. Aucune migration, aucune commande de prod ; la ligne est réécrite au prochain enregistrement du gabarit. Défaut de l'entité : `jita-sell` pour les deux.
11. Front : `templateToPayload`, `loadTemplate` et `evaluateTemplate` portent les deux sources ; charger un gabarit restaure les deux groupes de boutons ; l'évaluation du gabarit (profit, achats hebdo) utilise ses sources.

## Lot 3 : profit de la production de Revenus aligné

12. `PlanetaryProductionProfitCalculator::calculate` prend les deux sources. Source du choix : les `settings` du gabarit **suivi** de l'utilisateur (`PlanetaryPlanTemplateRepository::findFollowedByUser`) ; sans gabarit suivi, défaut actuel : achat `paid`, vente `hub`. Aucun nouveau paramètre de requête.
13. Ventes : chaque ligne vendue est valorisée au prix de vente de la source (hub ou Jita), plus `hubSellPrice`. Intrants achetés (quantité non produite par le joueur) : prix de la source d'achat, via la même règle que le lot 1 (`best` = plus bas des connus). Intrants P0 extraits : inchangés, valorisés à l'achat le plus haut du hub (`valuesExtractedAtHubBuy`).
14. Un service unique `PlanetaryPriceSources` (PHP) porte la règle des cinq sources d'achat et des trois sources de vente ; `PlanetaryProductionCosting` lui délègue au lieu de lire `paidPrices` seul.
15. La ressource `GET /planetary/production-profit` gagne `sources: {purchase, sale, followedTemplateName: string|null}` et chaque ligne `salePrice: float|null`. Les champs existants restent.
16. Front Revenus (`PiProductionProfitGrid.vue`) : une ligne « Prix : achat {source} · vente {source} » avec le nom du plan suivi, ou « par défaut » sans plan suivi. La colonne de prix de vente affiche `salePrice`.

Exemple : plan suivi avec achat `jita-buy`, vente `jita-sell` ; le profit de Revenus d'une ligne vendue 10 par jour à Jita sell 1 000, intrants achetés à Jita buy pour 740 l'unité, vaut 10 × (1 000 − 740). Sans plan suivi, il reste calculé au hub avec les prix payés.

## Hors périmètre

- Choix de source par type ou par production.
- Source de vente `paid` ou `best`, source d'achat par station de jeu autre que le hub des réglages.
- Stock, Approvisionnement et onglet Colonies (leur prix payé et leur hub sont déjà lus par leurs propres ressources).
- Partie 2 de #1459 (profit à côté de la production théorique) : traitée après, si le quota le permet.

## Tests attendus (proportionnés)

- vitest : `purchasePrice`/`salePrice` pour les huit sources ; `best` ; profit et taxe sur le prix de vente (exemple ci-dessus) ; classement qui suit l'achat et la vente ; store (deux setters, gabarit restauré, ancien gabarit à une source) ; réglages : deux groupes de boutons.
- Unit PHP : `PlanetaryPriceSources` ; `PlanetaryProductionCosting` avec chaque source ; calculateur de profit (vente Jita, achat `best`, défaut sans gabarit suivi) ; validation du gabarit ; lecture d'un gabarit ancien.
- Functional : `GET /planetary/planner` (hub et payé par utilisateur, sans fuite d'un autre utilisateur), `POST /planetary/templates`, `GET /planetary/production-profit`.

## Décisions prises en autonomie

- Hub = prix de vente le plus bas (achat et vente), cohérent avec l'onglet Stock.
- Taxe sur le prix de vente du type produit.
- Revenus suit le gabarit suivi plutôt qu'un réglage nouveau ou un paramètre de requête.
- `priceSource` n'est pas conservé à l'écriture (remplacé, lecture ancienne tolérée).
