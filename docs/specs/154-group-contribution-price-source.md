# Spec #154 — Group Industry : une seule source de prix pour une contribution matériau

> **EN ATTENTE DE DÉCISION de Sylvain (Q1 : prix BOM figé ou prix courant) : aucune implémentation dans la boucle de nuit.**

> Statut : **proposition, non validée**. Rédigée sans interrogatoire (Sylvain absent) : chaque point porte la réponse recommandée, les décisions produit sont dans « 🚦 Questions pour Sylvain ». Modifie les règles R1 à R3 de la spec [#8](8-group-contribution-unknown-material-price.md) si elle est validée. Lot voisin : [#146 + #156](146-156-154-group-industry-unknown-not-zero.md).

## Contexte

Code lu sur `fix/group-group-industry`, 2026-10-26 (les lignes de l'issue sont périmées).

- **Déjà corrigé par #8** : `GroupIndustryContributionService::calculateMaterialValue()` renvoie `null` sans prix ; `resolveMaterialValue()` refuse alors la contribution (400) si aucune valeur > 0 n'est saisie. Plus aucun matériau ne vaut 0 faute de prix.
- **Reste, deux sources de prix.** `ContributeModal.vue` (`autoEstimatedValue`, `submit()`) envoie `estimatedPrice × quantité`, où `estimatedPrice` est le **prix BOM** : prix percentile Jita (5 %) lu une seule fois par `GroupIndustryProjectService::buildBom()` à la création du projet, jamais rafraîchi (seul appelant : `createProject()`). Le backend prend toute valeur reçue > 0 telle quelle (`resolveMaterialValue()`). Sans valeur reçue, il lit le prix percentile Jita **courant** (`calculateMaterialValue()`). Deux membres qui apportent le même matériau à des dates différentes, l'un par l'écran et l'autre par l'API sans valeur, sont valorisés à deux prix différents ; par l'API, la valeur d'un matériau est libre.
- **Libellé faux.** La modale annonce « Jita Weighted Sell Price » et « Method: Jita weighted sell price ». Dans le glossaire, le **prix pondéré** consomme le carnet jusqu'à couvrir une quantité (`getWeightedSellPrices()`) ; le prix utilisé est le **prix percentile** (`getCheapestPercentilePrices()`), figé à la création du projet. Les deux libellés sont en anglais codé en dur.
- **Contrainte `PositiveOrZero`** (`SubmitContributionInput::$estimatedValue`) reconfirmée : elle doit accepter 0 pour la location de ligne gratuite (`requireEnteredValue()`, test `testSubmitLineRentalWithAnEnteredZeroValueIsRecordedAsAFreeRental`) ; la positivité stricte des autres types est vérifiée dans le service, par type. Elle reste telle quelle.

## Règles métier

**R1 — Source unique : le prix BOM.** La valeur d'une contribution matériau est `prix BOM de la ligne × quantité` quand la ligne de BOM a un prix (0 compris). Le backend la calcule ; aucun prix de marché n'est lu à la soumission (Q1).

**R2 — Valeur reçue pour un matériau à prix BOM.** Une contribution matériau dont la ligne a un prix BOM et qui porte une `estimatedValue` est refusée (400) avec le message `Type <typeId> has a BOM price: a material contribution is valued from it.` Rien n'est enregistré (Q2).

**R3 — Matériau sans prix BOM.** Valeur saisie si elle est strictement positive ; sinon refus (400) avec le message de la spec #8, inchangé : `No market price for type <typeId>: enter an estimated value.` Pas de repli sur le prix Jita courant (Q3).

**R4 — Code mort.** `calculateMaterialValue()` et la dépendance de `GroupIndustryContributionService` à `JitaMarketService` disparaissent avec R1 et R3 (plus aucun appelant).

**R5 — Frontend.** `ContributeModal.vue` n'envoie plus `estimatedValue` pour un matériau à prix BOM ; il garde l'aperçu `prix BOM × quantité`. Pour un matériau sans prix BOM, le champ obligatoire de la spec #8 (R4) est inchangé.

**R6 — Libellés.** Le bloc de prix s'intitule « Prix BOM (Jita, 5 % les moins chers, à la création du projet) » / "BOM price (Jita, cheapest 5%, at project creation)" ; la ligne de méthode devient « Méthode : prix BOM × quantité » / "Method: BOM price × quantity". Clés i18n nouvelles sous `groupIndustry.contribute` (FR et EN). Plus aucune mention de « weighted » / « pondéré ».

**R7 — Inchangé.** Contributions de job, BPC et location de ligne ; valeurs des contributions déjà enregistrées.

## Exemples chiffrés

Backend (PHPUnit), ligne de BOM Tritanium (typeId 34).

| # | Prix BOM | Prix Jita courant | Quantité | Valeur reçue | Attendu |
|---|---|---|---|---|---|
| E1 | 5,5 | (non lu) | 1 000 | aucune | enregistrée, valeur 5 500 ; `JitaMarketService` jamais appelé |
| E2 | 5,5 | 7,0 | 1 000 | aucune | 5 500 (pas 7 000) |
| E3 | 5,5 | — | 1 000 | 9 999,99 | 400 (R2), rien persisté |
| E4 | 0 | — | 300 | aucune | enregistrée, valeur 0 (prix BOM connu) |
| E5 | null | — | 100 | 900 | valeur 900 |
| E6 | null | — | 100 | aucune | 400, message de la spec #8, rien persisté |
| E7 | null | — | 100 | 0 | 400 (R3) |
| E8 | 5,5 | 7,0 puis 9,0 | 1 000, apporté par deux membres à deux dates | aucune | les deux contributions valent 5 500 |

Frontend (vitest), `ContributeModal`.

| # | Entrée | Attendu |
|---|---|---|
| V1 | Matériau à prix BOM 12,5, quantité 100 | aperçu 1 250 ; corps POST `{type: 'material', bomItemId, quantity: 100}` sans `estimatedValue` |
| V2 | Matériau sans prix BOM, valeur saisie 900 | champ visible, `estimatedValue: 900` envoyé (inchangé, spec #8 E6) |
| V3 | Matériau à prix BOM | le texte de la modale ne contient ni « Weighted » ni « pondéré » ; il contient le libellé R6 de la langue active |

## Cas limites

- Prix BOM à 0 : prix connu, valeur 0 (E4) ; la contribution entre dans le coût total à 0 (voir la répartition impossible, spec #146, si c'est la seule).
- Un prix Jita apparu après la création du projet ne donne pas de prix BOM : la ligne reste sans prix et la valeur est saisie (R3). Rafraîchir les prix BOM est hors périmètre.
- Prix BOM × quantité non entier : valeur gardée telle quelle (float), pas d'arrondi.

## Hors périmètre

- Rafraîchissement des prix BOM d'un projet existant.
- Revalorisation des contributions déjà enregistrées.
- Pied de `BomTable.vue` (« Prices: Jita top 5% sell avg ») : libellé exact, seulement non traduit.
- `submitAutoDetected()` : aucun appelant dans `src/`.

## Tests existants appelés à casser

`tests/Unit/Service/GroupIndustry/GroupIndustryContributionServiceTest.php` (le `bomItem` du `setUp` n'a pas de prix BOM ; ces tests valorisent par le prix Jita courant ou une valeur saisie) :

- `testSubmitMaterialCalculatesRavworksPrice`, `testSubmitMaterialWithoutEnteredValueIsValuedAtJitaPriceTimesQuantity`, `testSubmitMaterialWithEnteredZeroValueIsValuedAtJitaPriceTimesQuantity`, `testSubmitMaterialWithAJitaPriceOfZeroIsAKnownPriceValuedAtZero` : la valeur vient du prix BOM, plus du prix Jita (E1, E4).
- `testSubmitWithExplicitValueSkipsPriceCalculation`, `testSubmitMaterialWithEnteredValueKeepsTheEnteredValueWhenAJitaPriceExists` : valeur saisie sur un matériau sans prix BOM gardée (E5) ; avec un prix BOM, refus (E3). À réécrire selon le cas visé.
- `testSubmitMaterialWithoutJitaPrice*` (3 tests) : le « sans prix » devient « sans prix BOM » ; issue attendue identique.
- `testCalculateMaterialValueWithKnownPrice`, `testCalculateMaterialValueWithUnknownPrice`, `testCalculateMaterialValueIsUnknownWhenTheTypeIsAbsentFromTheJitaPrices` : méthode supprimée (R4).

Frontend :

- `frontend/tests/components/group-industry/contributeModalUnknownMaterialPrice.spec.ts`, « hides the estimated value field and sends the value calculated from the estimated price » (attend `estimatedValue: 1250`).
- `frontend/tests/components/group-industry/contributeModalJobInstallValue.spec.ts`, « sends the value calculated from the estimated price » (attend `estimatedValue: 2925`).

## Glossaire

Terme proposé, **à ajouter seulement après validation** (section Industrie collaborative) :

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **Prix BOM** | BOM price | Prix unitaire d'une ligne de matériau d'une BOM Group Industry : **prix percentile** Jita (5 %) lu à la création du projet, jamais rafraîchi ; absent si le carnet n'en donne aucun. Il valorise les contributions matériau (spec #154) et la valeur de la BOM (spec #156). | `GroupIndustryBomItem::$estimatedPrice` |

## 🚦 Questions pour Sylvain

1. **Source du prix.** (a) prix BOM figé à la création du projet (**recommandé** : même prix pour tous les membres, égal à l'aperçu de l'écran et à la valeur de la BOM, aucun appel marché à la soumission ; montants affichés inchangés pour qui passe par l'écran) ; ou (b) prix percentile Jita courant à la soumission, l'aperçu de la modale venant alors d'un appel au backend ?
2. **Valeur envoyée pour un matériau à prix BOM.** (a) refus 400 (**recommandé** : rien d'ignoré en silence) ; ou (b) valeur ignorée, prix BOM appliqué ?
3. **Matériau sans prix BOM.** (a) valeur saisie obligatoire, sans repli (**recommandé** : une seule règle) ; ou (b) repli sur le prix Jita courant s'il existe, puis valeur saisie ?
