# Spec #147 : carnet Jita trop court, prix partiel signalé

- **Statut** : validée par Sylvain le 2026-10-28 (Q1 à Q7 : recommandations retenues, Q3 verdict calculé et marqué, Q7 issue séparée).
- **Décisions** : Sylvain, 2026-10-28 (rappelées en R1 à R3, non rediscutées).
- **Circuit conseillé** : `l` (voir la fin).

Les termes en gras sont ceux du [glossaire](../glossary.md), dont deux sont ajoutés par cette spec : **Couverture du carnet** et **Prix partiel**.

## Contexte

Le carnet Jita ne garde que 20 ordres de vente par type, lus sur la première page ESI ([glossaire](../glossary.md), « Carnet d'ordres » et « Carnet Jita limité à la première page ESI »). Quand la quantité à acheter dépasse ce que le carnet contient, `JitaMarketService` renvoie le **prix pondéré** des seules unités servies, avec une `coverage` inférieure à 1 (`src/Service/JitaMarketService.php:665-673`).

L'adaptateur du port de prix du noyau jette cette information : il ne garde que `weightedPrice` (`src/Industry/Infrastructure/Market/JitaMarketPrices.php:25-28`). Le calculateur reçoit donc un prix sans savoir qu'il ne couvre qu'une partie de la quantité, et le multiplie par la quantité entière (`src/Industry/Application/ProductionCostCalculator.php:268`). Pour un gros volume, le coût matériaux est présenté comme connu alors qu'il repose sur une fraction de la quantité. Profit Margins et Buy vs Build, seuls appelants du calculateur (`src/Service/Industry/ProfitMarginService.php:44`, `src/Service/Industry/BuyVsBuildService.php:53`), affichent ce coût sans réserve.

`coverage` n'est jamais 0 dans la réponse : un carnet vide, une quantité ≤ 0 ou aucun volume servi donnent `null`, c'est-à-dire aucun prix (`JitaMarketService.php:644-646`, `:665-667`).

## Règles métier

Décisions du 2026-10-28 :

1. **Le noyau garde la couverture avec le prix.** `MarketPrices::unitPricesFor()` renvoie, par typeId, le prix unitaire pondéré et la **couverture du carnet** (fraction dans ]0, 1], celle de `coverage`). Le noyau la lit, il ne la recalcule pas. Un typeId sans prix reste absent de la réponse.
2. **Aucun seuil.** Toute couverture strictement inférieure à 1 est un **prix partiel**, y compris 99,7 %. Une couverture égale à 1 est un prix complet, sans avertissement. Un prix partiel n'est jamais « inconnu » et n'est jamais retiré.
3. **Le montant reste affiché, marqué partiel.** Le coût d'une feuille à prix partiel est connu : `quantité × prix pondéré`, comme aujourd'hui (`ProductionCostCalculator.php:268`). Il porte un avertissement `partial_market_depth` avec son typeId et sa couverture.

Règles de cette spec, à valider :

4. **Le `Cost` porte les avertissements.** `Cost` expose la liste de ses prix partiels (typeId, couverture), à côté de `missingData` (`src/Industry/Domain/Cost.php:15`). `plus()` et `times()` les propagent (`:46`, `:58`) : une somme garde les avertissements de tous ses termes, `costPerUnit` ceux du coût total.
5. **Inconnu et partiel se cumulent.** Une somme inconnue garde les avertissements de ses termes connus (`plus()` d'un inconnu et d'un connu partiel liste les deux). Les avertissements ne rendent jamais un coût inconnu.
6. **Doublons.** Deux avertissements de même typeId ne sont comptés qu'une fois, avec la couverture la plus basse (pire cas). Même règle de dédoublonnage que `missingData` (`Cost.php:72-83`).
7. **Couverture 0 : pas de prix.** Un typeId dont la couverture serait 0 n'a pas de prix, il est inconnu (`market_price`, `ProductionCostCalculator.php:269`). Il n'y a rien à marquer partiel (question Q1).
8. **Couverture absente de la réponse du service** : le prix de ce typeId est traité comme absent (donc inconnu, `market_price`), jamais complet. Pas de `?? 1.0` (question Q2).
9. **Tous les achats prix portent l'avertissement.** Le calculateur construit les avertissements pour tous les achats qu'il prix : feuilles (`:81`), datacores et decryptor de l'invention (`:180`, `:183`, via `bought()` `:280-283`).
10. **Exposition API.** Profit Margins et Buy vs Build exposent `warnings`, liste de `{reason: 'partial_market_depth', typeId, coveragePercent}`, à côté de `missingData`, dans l'ordre d'apparition. `coveragePercent` = couverture × 100, arrondie à 1 décimale et bornée à [0,1 ; 99,9] : un prix partiel ne s'affiche jamais « 0 % » (qui ressemble à inconnu) ni « 100 % » (qui ressemble à complet). Sans prix partiel : `warnings` vaut `[]`.
11. **Profit Margins.** `warnings` au niveau du résultat, ceux de `totalCost` (matériaux, invention). Les lignes de matériaux n'ont pas de champ propre : le front retrouve la couverture par typeId, comme il retrouve les prix manquants (`frontend/src/components/industry/ProfitMarginTab.vue:69-77`). Montants, marges et `missingData` ne changent pas.
12. **Buy vs Build.** `warnings` au niveau du résultat, ceux de `totalProductionCost` (`optimalMixCost` plus coût hors composants). Chaque composant porte ses `warnings`, ceux de `buildCost` plus `buyCost` (le coût réellement retenu, même construction que son `missingData`, `BuyVsBuildService.php:151`). Verdicts, économies et montants ne changent pas. Le prix Jita d'un composant est marqué partiel même quand le prix structure est retenu pour le coût d'achat (`:135`) : le composant expose `buyCostJitaCoveragePercent` (null si complet ou sans prix Jita), car la ligne Jita reste affichée (`BuyVsBuildTab.vue:564-567`) et ne doit pas se lire complète.
13. **Front.** Les montants partiels restent affichés avec un marqueur et la couverture. Chaque écran affiche un avis listant les types concernés avec leur couverture. Textes dans `fr.json` et `en.json`, jamais en dur.

## Exemples chiffrés

Carnet de vente du type X : 100 à 5,00, 100 à 6,00, 100 à 7,00 (300 unités).

### Prix pondéré et couverture (adaptateur, R1 à R3, R7, R8)

| Cas | Quantité demandée (après `ceil`) | Unités servies | Prix unitaire | Couverture | Résultat |
|---|---|---|---|---|---|
| Couverture 30 % | 1 000 | 300 | (500 + 600 + 700) / 300 = 6,00 | 0,30 | prix partiel |
| Couverture 100 % | 150 | 150 | (500 + 300) / 150 = 5,3333 | 1,0 | complet, aucun avertissement |
| Carnet tout juste suffisant | 300 | 300 | 6,00 | 1,0 | complet |
| Un cran au-dessus | 301 | 300 | 6,00 | 300/301 = 0,99668 | partiel, 99,7 % |
| Couverture 0 (carnet vide, ou aucun volume) | 100 | 0 | aucun | 0 | typeId absent : `market_price` (Q1) |
| Réponse du service sans `coverage` | 100 | n/a | 6,00 | absente | typeId absent : `market_price` (Q2) |
| Type hors cache, lu à la demande | 1 000 | 300 | 6,00 | 0,30 | identique à la ligne 1 |

### Coût d'une feuille et somme (calculateur, R3 à R6)

Produit P, 1 run, 100 unités par run, ME 0, sans bonus. Matériaux par run : X 1 000 (partiel, 30 % ci-dessus), Y 150 (5,3333, complet), Z 500 (10,00, complet), mode plan.

| Cas | Attendu |
|---|---|
| Coût de X | 1 000 × 6,00 = 6 000, connu, avertissement (X, 0,30) |
| Coût de Y | 150 × 5,3333 = 800, connu, aucun avertissement |
| Plusieurs matériaux dont un partiel | `materialCost` = 6 000 + 800 + 5 000 = 11 800, connu, avertissements : [(X, 0,30)] |
| Somme de coût avec un matériau partiel | `totalCost` = 11 800 + installation connue I, connu, avertissements : [(X, 0,30)] ; `costPerUnit` = `totalCost` / 100, même avertissement |
| Partiel et inconnu | un matériau W sans prix en plus : `materialCost` inconnu, `missingData` : [`market_price` W], avertissements : [(X, 0,30)] |
| Même typeId dans deux achats | X acheté à 1 000 (couverture 0,30) et X acheté à 500 comme datacore (couverture 0,60) : une seule entrée (X, 0,30) |
| Sans prix partiel | tous les prix à couverture 1 : `warnings` vide, tous les montants identiques à aujourd'hui |

### Mode plan et coût marginal (R1)

Produit P, 1 run, consomme 10 unités d'un intermédiaire M ; M produit 100 unités par run ; chaque run de M consomme 320 X (ME 0, sans bonus). Carnet de X : 300 unités.

| Mode | Runs de M | X demandé | Couverture | Résultat |
|---|---|---|---|---|
| Plan | 1 (entier) | 320 | 300/320 = 0,9375 | partiel, 93,8 % |
| Coût marginal | 0,1 (fractionnaire) | 32 | 1,0 | complet |

La couverture se mesure sur la quantité demandée par le mode : un même produit peut être partiel en Buy vs Build (plan) et complet en Profit Margins (coût marginal).

### API et écrans (R10 à R13)

| Cas | Attendu |
|---|---|
| Couverture 0,30 / 0,9375 / 0,99668 | `coveragePercent` 30,0 / 93,8 / 99,7 |
| Couverture 0,99999 | 99,9 (jamais 100) |
| Couverture 0,00004 | 0,1 (jamais 0) |
| Profit Margins, X partiel | ligne X : `unitPrice` 6,00 et `totalPrice` 6 000 affichés avec le marqueur « 30 % » ; avis listant X ; `totalCost` et marges affichés |
| Buy vs Build, composant C acheté chez Jita, prix partiel à 30 % | `buyCostJita` chiffré, `warnings` du composant : [(C, 30,0)], verdict inchangé |
| Buy vs Build, même composant, prix structure retenu | `buyCost` = prix structure, `warnings` du composant sans C, `buyCostJitaCoveragePercent` 30,0 |

## Cas limites

| Cas | Comportement attendu |
|---|---|
| Quantité fractionnaire | L'adaptateur arrondit au supérieur avant l'appel (`JitaMarketPrices.php:19-22`) : la couverture est celle de la quantité entière demandée. 300,2 unités demandent 301 : couverture 0,99668 là où 300 donne 1,0. Le montant reste `quantité fractionnaire × prix`. |
| Quantité 0 | `ceil(0)` = 0 : le service ne renvoie aucun prix (`JitaMarketService.php:644`). Inchangé : prix inconnu. |
| Même type demandé deux fois | Impossible dans un appel (clé = typeId). Possible entre appels d'un même calcul (feuilles, datacores, decryptor : `ProductionCostCalculator.php:81`, `:180`, `:183` ; racine et composants de Buy vs Build) : R6, couverture la plus basse. Chaque appel prix sa quantité depuis le haut du carnet, sans agréger (hors périmètre). |
| Repli à la demande | Le cache puis l'ESI à la demande donnent la même forme avec `coverage` (`JitaMarketService.php:319`, `:332-337`) : même traitement. |
| Repli région du carnet Jita | La couverture est celle du carnet retenu, station ou région. L'origine du carnet n'est pas exposée : hors périmètre. |
| Structure et Jita (Buy vs Build) | Le prix structure remplace le prix Jita pour le coût d'achat (`BuyVsBuildService.php:132-135`). Il vient du meilleur ordre, sans profondeur ni couverture (`StructureMarketService.php:72-97`) : aucun avertissement ne le concerne. Le prix Jita, s'il est partiel, est marqué par `buyCostJitaCoveragePercent` (R12). |
| Couverture absente | R8. Si la clé existe mais hors ]0, 1], l'objet valeur de couverture refuse à la construction (le service ne peut pas la produire : `min(1.0, …)` et au moins une unité servie, `JitaMarketService.php:665-671`). |
| Couverture 100 % ne veut pas dire marché suffisant | Le carnet est limité à la première page ESI (glossaire) : une couverture de 1 garantit seulement que le carnet connu suffit. |
| Options de decryptor (Profit Margins) | Pas de `warnings` par option, comme pour `missingData` (glossaire, « Coût inconnu »). Hors périmètre (Q6). |
| Affichage de la couverture | Le front affiche `coveragePercent` tel que reçu, sans le réarrondir. |

## Invariance

Sans prix partiel (toutes les couvertures à 1, ou prix absent), aucun résultat ne change : montants, `missingData`, verdicts, marges, ordre des composants. Seuls apparaissent les champs `warnings: []` et `buyCostJitaCoveragePercent: null`. Les tests de caractérisation existants le prouvent sans modification : leurs doublures de `JitaMarketService` renvoient déjà `coverage` à 1,0 (`tests/Unit/Service/Industry/ProfitMarginGoldenCharacterizationTest.php:1191`, `:1199`, `tests/Unit/Service/Industry/BuildsBuyVsBuildOnGoldenSde.php:102`).

## Fichiers et écrans touchés

Noyau (`src/Industry/`) :

- `Application/MarketPrices.php:14-19` : contrat du port (prix et couverture par typeId).
- `Infrastructure/Market/JitaMarketPrices.php:17-32` : lire `coverage`, R8.
- `Domain/Cost.php:15-83`, `Domain/MissingData.php` (modèle de l'avertissement) : nouveaux objets valeur de couverture et de prix partiel, propagation par `plus` et `times`.
- `Application/ProductionCostCalculator.php:261-283` (`leafCosts`, `bought`) : porter l'avertissement dans le `Cost` de la feuille ; `:82-99` sommes et `costPerUnit` déjà passés par `plus` et `times`.

Services, API (`src/`) :

- `Service/Industry/MissingDataPresenter.php:13-22` : pendant pour les avertissements (`coveragePercent`, R10).
- `Service/Industry/ProfitMarginService.php:97-112`, `:174-188` : `warnings` du résultat.
- `Service/Industry/BuyVsBuildService.php:151-170` (composant), `:179-197` (total) : `warnings`, `buyCostJitaCoveragePercent`.
- `ApiResource/Industry/ProfitMarginResource.php:92-93`, `ApiResource/Industry/BuyVsBuildResource.php:55-56`, `:70-71` : champs et types des composants.
- `State/Provider/Industry/ProfitMarginProvider.php:133`, `State/Provider/Industry/BuyVsBuildProvider.php:106` : mapping vers la ressource.

Front (`frontend/src/`) :

- `stores/industry/types.ts:328-333` (`MissingData`), `:385-413` (`ProfitMarginResult`), `:595-629` (`BuyVsBuildComponent`, `BuyVsBuildResult`) : type d'avertissement et champs.
- `composables/useUnknownInstallCostReasons.ts:9-18` : modèle d'un composable de lecture des avertissements par typeId.
- `components/industry/ProfitMarginTab.vue:609-610` (lignes de matériaux), `:645` (total matériaux), `:758` (résumé du coût), avis près de `:541-543`.
- `components/industry/BuyVsBuildTab.vue:534-542` (matériaux de fabrication), `:557-567` (colonne achat et ligne Jita), `:669`, `:680` (totaux), avis près de `:415-419`.
- `i18n/locales/fr.json:587-596` et `en.json:587-596` (bloc `industry.inventionUnknown`) : nouveau bloc `industry.partialPrice` dans les deux langues.

À mettre à jour à l'implémentation : `docs/modules/industry.md` (port `MarketPrices`, ligne « Prix Jita »), R10 de `docs/specs/industry-engine.md` (prix partiel).

## Hors périmètre

- Pagination ESI du carnet Jita (issue #26) : la couverture mesure le carnet que l'on a, pas le marché.
- Écrans non branchés sur le noyau (`docs/modules/industry.md:25-27`) : scanner, Pivot Advisor, coût d'un projet, invention, liste d'achats (elle expose déjà `coverage`, `src/State/Provider/Industry/ShoppingListProvider.php:198`).
- Côté vente de Profit Margins : `jitaSell` garde `weightedPrice` et jette la couverture (`ProfitMarginService.php:275-278`), même défaut sur les revenus. À traiter dans une issue séparée.
- Profondeur du prix structure (Buy vs Build, `BuyVsBuildService.php:132-133`).
- Agrégation des quantités entre appels de prix (Buy vs Build prix chaque composant depuis le haut du carnet).
- Options de decryptor (Q6), seuil de couverture (exclu par la décision R2), changement de la règle de prix pondéré.

## Tests attendus

- **Domaine** (`tests/Unit/Industry/Domain/`) : objet valeur de couverture (bornes ]0, 1], refus de 0, négatif, supérieur à 1, NaN) ; `CostTest` : propagation par `plus` et `times`, cumul avec l'inconnu, dédoublonnage à la couverture la plus basse.
- **Adaptateur** (`tests/Unit/Industry/Infrastructure/Market/JitaMarketPricesTest.php`) : lignes 1 à 7 du premier tableau (stub de `JitaMarketService` pour la réponse sans `coverage`), quantité fractionnaire 300,2.
- **Calculateur** (`tests/Unit/Industry/Application/`) : tableau des sommes, plan contre coût marginal, datacore partiel dans l'invention. Le double `Double/InMemoryMarketPrices.php` garde son constructeur (couverture 1,0 par défaut) et accepte une couverture par typeId.
- **Services** (`tests/Unit/Service/Industry/`) : `ProfitMarginService` et `BuyVsBuildService` avec un prix partiel (nouveaux fichiers, sur le modèle de `BuyVsBuildServiceUnknownPriceTest`) ; les goldens existants passent inchangés (invariance).
- **Provider et API** (`tests/Unit/State/Provider/Industry/ProfitMarginProviderTest.php`, `BuyVsBuildProviderTest.php`) : `warnings` et `buyCostJitaCoveragePercent` mappés ; `[]` et null par défaut ; bornes de `coveragePercent`.
- **Front** (`frontend/tests/components/industry/`, sur le modèle de `profitMarginTabUnknownMaterialPrice.spec.ts` et `buyVsBuildUnknownPrice.spec.ts`) : marqueur et couverture sur la ligne, avis, montant toujours affiché, aucun marqueur sans avertissement, libellés FR et EN présents.

## Questions ouvertes

- **Q1 : couverture 0.** Proposition : pas de prix, donc inconnu (`market_price`), comme aujourd'hui. Recommandation : garder, il n'y a pas de prix à marquer partiel.
- **Q2 : couverture absente de la réponse.** Options : prix traité comme absent (inconnu) ou exception. Recommandation : prix absent, c'est l'état « inconnu » explicite voulu et il reste visible à l'écran.
- **Q3 : verdict Buy vs Build d'un composant partiel.** Options : verdict calculé et marqué, ou verdict `unknown`. Recommandation : calculé et marqué, conforme à la décision « le montant reste affiché ».
- **Q4 : même typeId dans deux achats.** Recommandation : couverture la plus basse (pire cas), R6.
- **Q5 : marquer la ligne Jita quand le prix structure est retenu.** Recommandation : oui (R12), sans quoi un prix partiel s'affiche sans marqueur.
- **Q6 : options de decryptor de Profit Margins.** Recommandation : hors périmètre, comme `missingData` aujourd'hui.
- **Q7 : côté vente de Profit Margins** (`jitaSell`). Recommandation : issue séparée, le revenu surestimé est le même défaut mais sort de cette décision.

## Avis circuit : `l`

Le contrat du port `MarketPrices` change, `Cost` gagne un second canal propagé dans toutes les sommes, et le changement traverse le noyau, deux services, l'API, deux écrans et l'i18n. C'est une dépendance du noyau modifiée : la note de câblage (`/tdd-red`, 1b) est utile pour lister les doublures de test à adapter.

## Termes du glossaire

Ajoutés : **Couverture du carnet** (*Order book coverage*), **Prix partiel** (*Partial price*). Modifiés : **Prix pondéré** (renvoi vers la couverture du carnet) et l'ambiguïté « Prix pondéré et prix percentile bornés à 20 ordres ».
