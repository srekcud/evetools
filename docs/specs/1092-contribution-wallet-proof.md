# #1092 Contributions: wallet proof of material contributions

Circuit m, mode nuit (spec validée en autonomie).

## Règles

1. `ProjectContributionResource::$walletBackedQuantity` (`?int`) : unités de la contribution couvertes par une transaction du wallet. `null` pour tout type autre que `material` et pour une contribution sans ligne de BOM.
2. Achats rattachés à une contribution `material` (même `typeId` que la ligne) :
   - contribution détectée (`isAutoDetected`) : achats de la ligne sans contribution liée, dont l'acheteur est le membre (acheteur absent = propriétaire du projet) ;
   - contribution déclarée : achats dont `getContribution()` est cette contribution.
3. `walletBackedQuantity` = somme des quantités de ces achats qui ont une transaction (`getTransaction() !== null`), plafonnée à `quantity` de la contribution.
4. Le calcul vit dans `IndustryProjectContribution::walletBackedQuantity()` ; le mapper l'expose, donc la liste, le détail et les réponses des processors portent la même valeur.
5. La liste (`findByProjectForListing`) charge les achats des lignes dans la même requête : pas de requête par contribution.
6. `ContributionTable.vue`, ligne `material` : badge `Wallet ✓` (tout couvert), `Wallet x / y` (partiel, x > 0), `No wallet proof` (x = 0). Pas de badge sur les autres types ; pas de badge si `walletBackedQuantity` est absent.
7. Filtre « Wallet proof » (tout / avec preuve complète ou partielle / sans preuve) dans la barre de filtres ; la valeur « sans preuve » ne garde que les lignes `material` à x = 0.
8. Libellés « Unverified », « Auto-detected » et « Delivery confirmed by hand » passent en i18n FR + EN (clés `groupIndustry.contributions.badges.*`), ainsi que les nouveaux badges et le filtre.
9. La preuve du wallet (paiement) et `isVerified` (livraison vue dans le container) restent deux badges distincts.

## Exemples

| Contribution | Achats | walletBackedQuantity |
|---|---|---|
| détectée, 100 | 100 avec transaction | 100 |
| détectée, 100 | 60 avec transaction + 40 manuels | 60 |
| détectée, 100 | 100 manuels | 0 |
| déclarée, 100 | aucun lié | 0 |
| déclarée, 100 | 100 liés avec transaction | 100 |
| déclarée, 100 | 100 avec transaction, liés à une autre contribution | 0 |
| détectée, 50 | 80 avec transaction | 50 (plafond) |
| job_install | n'importe lesquels | null |

## Hors périmètre

- Contributions `job_install` (preuve ESI déjà affichée).
- Tri, pas de nouvelle colonne.
- Aucune migration ni nouveau champ en base.

## Décisions

- D1 : le rattachement achat → contribution suit `DerivedContributionService::purchasesByBuyer` (achats sans contribution = contribution détectée) plutôt que « achats de la ligne par cet acheteur » seul, pour ne pas compter deux fois un achat déjà lié à une contribution déclarée.
- D2 : filtre à trois valeurs plutôt qu'un tri, les statuts et types étant déjà des filtres.
