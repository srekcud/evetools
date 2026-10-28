# Spec #154 — Group Industry : une seule source de prix pour une contribution matériau

> Statut : **décidée par Sylvain le 2026-10-29** (voir « Décisions de Sylvain »). Modifie les règles R1 à R3 de la spec [#8](8-group-contribution-unknown-material-price.md). Lot voisin : [#146 + #156](146-156-154-group-industry-unknown-not-zero.md).

## Contexte

Code lu sur `fix/group-group-industry`, 2026-10-29.

- **Déjà corrigé par #8** : `GroupIndustryContributionService::calculateMaterialValue()` renvoie `null` sans prix (`GroupIndustryContributionService.php:168-178`) ; `resolveMaterialValue()` refuse alors la contribution (400) si aucune valeur > 0 n'est saisie (`:180-188`, 400 par `SubmitContributionProcessor.php:103-104`). Plus aucun matériau ne vaut 0 faute de prix.
- **Reste, deux sources de prix.**
  - `ContributeModal.vue` (`autoEstimatedValue`, `:73-77` ; `submit()`, `:153-155`) envoie `estimatedPrice × quantité`. `estimatedPrice` est le **prix BOM** : prix percentile Jita (5 %, `JitaMarketService::getCheapestPercentilePrices()`, `$percentile = 0.05`, `JitaMarketService.php:686`) lu par `GroupIndustryProjectService::buildBom()` (`:116-118` pour la lecture, `:128` pour `setEstimatedPrice($prices[$typeId] ?? null)`). Son seul appelant est `createProject()` (`:86`) : le prix n'est donc jamais rafraîchi.
  - Le backend prend toute valeur reçue > 0 telle quelle (`resolveMaterialValue()`, `:181-183`). Sans valeur reçue, il lit le prix percentile Jita **courant** (`:186`). Deux membres qui apportent le même matériau à des dates différentes, l'un par l'écran et l'autre par l'API sans valeur, sont valorisés à deux prix différents ; par l'API, la valeur d'un matériau est libre.
- **Libellé faux.** La modale annonce « Jita Weighted Sell Price » (`ContributeModal.vue:298`) et « Method: Jita weighted sell price » (`:336`), codés en dur en anglais. Dans le glossaire, le **prix pondéré** consomme le carnet jusqu'à couvrir une quantité (`getWeightedSellPrices()`) ; le prix utilisé est le **prix percentile**, figé à la création du projet.
- **Piège du « figé ».** Rien ne stocke la date du prix sur `GroupIndustryBomItem` (pas de colonne de date). Le prix est figé parce que `buildBom()` n'a qu'un appelant. Or `buildBom()` est public et reconstruit les lignes en place : `updateLineInPlace()` réécrit `estimatedPrice` d'une ligne de matériau (`GroupIndustryProjectService.php:226-239`). Un futur appelant de `buildBom()` rafraîchirait les prix et rendrait le libellé « figé à la création » faux (voir R6).
- **Contrainte `PositiveOrZero`** (`SubmitContributionInput::$estimatedValue`, `SubmitContributionInput.php:22-23`) : elle doit accepter 0 pour la location de ligne gratuite (`requireEnteredValue()`, `GroupIndustryContributionService.php:190-194` ; test `testSubmitLineRentalWithAnEnteredZeroValueIsRecordedAsAFreeRental`) ; la positivité stricte des autres types est vérifiée dans le service, par type. Elle reste telle quelle.

## Décisions de Sylvain (2026-10-29)

1. **Source du prix.** Prix BOM figé à la création du projet (recommandation de la spec retenue) : même prix pour tous les membres, égal à l'aperçu de l'écran et à la valeur de la BOM, aucun appel marché à la soumission.
2. **Valeur envoyée par le client sur un matériau à prix BOM.** Refusée en 400, rien n'est ignoré en silence.
3. **Matériau sans prix BOM.** Pas de repli sur le prix Jita courant : valeur saisie obligatoire (> 0), sinon 400.
4. **Ajout exigé : la source du prix est explicite dans le front.** La modale dit « prix BOM figé à la création du projet », pas « Jita weighted sell price » (R6).

## Règles métier

**R1 — Source unique : le prix BOM.** La valeur d'une contribution matériau est `prix BOM de la ligne × quantité` quand la ligne de BOM a un prix (0 compris). Le backend la calcule ; aucun prix de marché n'est lu à la soumission.

**R2 — Valeur reçue pour un matériau à prix BOM.** Une contribution matériau dont la ligne a un prix BOM et qui porte une `estimatedValue` (même égale à `prix BOM × quantité`, même 0) est refusée (400) avec le message `Type <typeId> has a BOM price: a material contribution is valued from it.` Rien n'est enregistré. Le test porte sur la présence de la valeur (`!== null`), pas sur son montant.

**R3 — Matériau sans prix BOM.** Valeur saisie si elle est strictement positive ; sinon refus (400) avec le message de la spec #8, inchangé : `No market price for type <typeId>: enter an estimated value.` Pas de repli sur le prix Jita courant. Un matériau sans prix BOM n'a jamais une valeur de 0 : sa valeur est **inconnue** (`null` côté prix) tant que personne ne la saisit.

**R4 — Code mort.** `calculateMaterialValue()` et la dépendance de `GroupIndustryContributionService` à `JitaMarketService` (`:21`) disparaissent (plus aucun appelant, `:186` mis à part). Le constructeur perd un paramètre : tous les `new GroupIndustryContributionService(…)` des tests changent (voir « Tests existants appelés à casser »).

**R5 — Frontend, envoi.** `ContributeModal.vue` n'envoie plus `estimatedValue` pour un matériau à prix BOM (retrait de la branche `else if (autoEstimatedValue.value != null)`, `:153-155`) ; il garde l'aperçu `prix BOM × quantité`. Pour un matériau sans prix BOM, le champ obligatoire de la spec #8 (R4) est inchangé.

**R6 — Frontend, la source du prix est dite.** Dans le bloc de prix d'un matériau à prix BOM (`ContributeModal.vue:296-313`), le libellé « Jita Weighted Sell Price » est remplacé par « Prix BOM, figé à la création du projet » / "BOM price, frozen at project creation". Une ligne sous le libellé donne la date de figeage et la source : « Figé le {date} · Jita, 5 % des ordres de vente les moins chers » / "Frozen on {date} · Jita, cheapest 5% of sell orders". La ligne de méthode (`:336`) devient « Méthode : prix BOM × quantité » / "Method: BOM price × quantity". Plus aucune mention de « weighted » / « pondéré ».
- **Date** : la donnée existe. C'est `createdAt` du projet (`GroupProject.createdAt`, `frontend/src/stores/group-industry/types.ts:50`, lu via `projectStore.currentProject`), formatée par `formatDate()` (`useFormatters.ts:55`). `GroupIndustryProject::$createdAt` est posé dans le constructeur (`GroupIndustryProject.php:94`), `createProject()` l'appelle puis fixe les prix dans la même requête (`GroupIndustryProjectService.php:57`, `:86`). Ce n'est pas un horodatage du prix : si un appelant rafraîchit un jour les prix (`buildBom()` hors `createProject()`), la date et le libellé deviennent faux, et il faut alors stocker une date de prix sur la ligne ou changer le libellé.
- **Clés i18n** nouvelles sous `groupIndustry.contribute` (bloc de `ContributeModal.vue`, `en.json:1958` et `fr.json:1958`), en EN et en FR : `bomPriceLabel`, `bomPriceFrozenOn` (paramètre `{date}`), `bomPriceSource`, `methodBomPrice`. Les valeurs sont celles du paragraphe ci-dessus.

**R7 — Inchangé.** Contributions de job, BPC et location de ligne ; valeurs des contributions déjà enregistrées.

## Exemples chiffrés

Backend (PHPUnit), ligne de BOM Tritanium (typeId 34).

| # | Prix BOM | Prix Jita courant | Quantité | Valeur reçue | Attendu |
|---|---|---|---|---|---|
| E1 | 5,5 | (non lu) | 1 000 | aucune | enregistrée, valeur 5 500 ; `JitaMarketService` jamais appelé |
| E2 | 5,5 | 7,0 | 1 000 | aucune | 5 500 (pas 7 000) |
| E3 | 5,5 | — | 1 000 | 9 999,99 | 400 (R2), rien persisté |
| E3b | 5,5 | — | 1 000 | 5 500 (égale au calcul) | 400 (R2), rien persisté |
| E4 | 0 | — | 300 | aucune | enregistrée, valeur 0 (prix BOM connu) |
| E4b | 0 | — | 300 | 0 | 400 (R2) : une valeur est fournie |
| E5 | null | — | 100 | 900 | valeur 900 |
| E6 | null | — | 100 | aucune | 400, message de la spec #8, rien persisté |
| E7 | null | — | 100 | 0 | 400 (R3) |
| E8 | 5,5 | 7,0 puis 9,0 | 1 000, apporté par deux membres à deux dates | aucune | les deux contributions valent 5 500 |
| E9 | null | 7,0 (carnet disponible) | 100 | aucune | 400 (R3) : pas de repli sur 7,0, aucune valeur de 700 enregistrée |

Frontend (vitest), `ContributeModal`.

| # | Entrée | Attendu |
|---|---|---|
| V1 | Matériau à prix BOM 12,5, quantité 100 | aperçu 1 250 ; corps POST `{type: 'material', bomItemId, quantity: 100}` sans `estimatedValue` |
| V2 | Matériau sans prix BOM, valeur saisie 900 | champ visible, `estimatedValue: 900` envoyé (inchangé, spec #8 E6) |
| V3 | Matériau à prix BOM, langue EN | le texte de la modale contient « BOM price, frozen at project creation » ; aucun « Weighted » |
| V4 | Idem, langue FR | contient « Prix BOM, figé à la création du projet » ; aucun « pondéré » ni « Weighted » |
| V5 | Matériau à prix BOM, projet courant créé le 2026-10-01 | la ligne de date contient la date de création formatée par `formatDate()` |
| V6 | Matériau sans prix BOM | ni le libellé du prix BOM ni la ligne de date ne s'affichent (le champ de saisie est seul) |

## Cas limites

- Prix BOM à 0 : prix connu, valeur 0 (E4) ; la contribution entre dans le coût total à 0 (voir la répartition impossible, spec #146, si c'est la seule). L'aperçu de la modale n'affiche pas le total quand il vaut 0 (`displayedValue > 0`, `ContributeModal.vue:331`).
- Un prix Jita apparu après la création du projet ne donne pas de prix BOM : la ligne reste sans prix et la valeur est saisie (R3, E9). Rafraîchir les prix BOM est hors périmètre.
- Prix BOM × quantité non entier : valeur gardée telle quelle (float), pas d'arrondi.
- Un client qui envoie `estimatedValue: null` explicite pour un matériau à prix BOM : traité comme absent (valeur calculée).

## Hors périmètre

- Rafraîchissement des prix BOM d'un projet existant.
- Revalorisation des contributions déjà enregistrées.
- Pied de `BomTable.vue` (« Prices: Jita top 5% sell avg ») : libellé exact, seulement non traduit.
- Les autres libellés anglais codés en dur du même bloc (« Auto-calculated », « Unit price: », `ContributeModal.vue:299`, `:303`) : à traduire dans le même passage si le développeur y touche, sans règle propre.
- `submitAutoDetected()` : aucun appelant dans `src/`.

## Tests existants appelés à casser

Constructeur du service (R4) : `GroupIndustryContributionServiceTest.php:45`, `SubmitContributionProcessorTest.php:93`, `ReviewContributionProcessorTest.php:98`.

`tests/Unit/Service/GroupIndustry/GroupIndustryContributionServiceTest.php` (le `bomItem` du `setUp`, `:57-62`, n'a pas de prix BOM ; ces tests valorisent par le prix Jita courant ou une valeur saisie) :

- `testSubmitMaterialCalculatesRavworksPrice` (`:67`), `testSubmitMaterialWithoutEnteredValueIsValuedAtJitaPriceTimesQuantity` (`:382`), `testSubmitMaterialWithEnteredZeroValueIsValuedAtJitaPriceTimesQuantity` (`:411`), `testSubmitMaterialWithAJitaPriceOfZeroIsAKnownPriceValuedAtZero` (`:472`) : la valeur vient du prix BOM, plus du prix Jita (E1, E4).
- `testSubmitWithExplicitValueSkipsPriceCalculation` (`:100`), `testSubmitMaterialWithEnteredValueKeepsTheEnteredValueWhenAJitaPriceExists` (`:398`) : valeur saisie sur un matériau sans prix BOM gardée (E5, E9) ; avec un prix BOM, refus (E3). À réécrire selon le cas visé.
- `testSubmitMaterialWithoutJitaPriceNorEnteredValueIsRefusedAndNothingIsPersisted` (`:425`), `testSubmitMaterialWithoutJitaPriceUsesTheEnteredValue` (`:441`), `testSubmitMaterialWithoutJitaPriceAndEnteredZeroValueIsRefusedAndNothingIsPersisted` (`:456`) : le « sans prix » devient « sans prix BOM » ; issue attendue identique.
- `testCalculateMaterialValueWithKnownPrice` (`:586`), `testCalculateMaterialValueWithUnknownPrice` (`:361`), `testCalculateMaterialValueIsUnknownWhenTheTypeIsAbsentFromTheJitaPrices` (`:372`) : méthode supprimée (R4).

Frontend :

- `frontend/tests/components/group-industry/contributeModalUnknownMaterialPrice.spec.ts`, « hides the estimated value field and sends the value calculated from the estimated price » (`:142`, attend `estimatedValue: 1250`, `:155`) : le corps ne porte plus `estimatedValue`.
- `frontend/tests/components/group-industry/contributeModalJobInstallValue.spec.ts`, « sends the value calculated from the estimated price » (`:145`, attend `estimatedValue: 2925`, `:157`) : idem.

## Glossaire

Terme validé, **à ajouter dans le commit d'implémentation** (section Industrie collaborative), pas avant : le glossaire décrirait un comportement qui n'existe pas encore.

| Terme FR | Term EN | Définition | Symbole(s) |
|---|---|---|---|
| **Prix BOM** | BOM price | Prix unitaire d'une ligne de matériau d'une BOM Group Industry : **prix percentile** Jita (5 %) lu à la création du projet, jamais rafraîchi ; absent (inconnu, jamais 0) si le carnet n'en donne aucun. Il valorise les contributions matériau (spec #154) et la valeur de la BOM (spec #156). | `GroupIndustryBomItem::$estimatedPrice` |
