# #1396 : prix payé des entrées PI

Circuit M, mode nuit. Décision de Sylvain (commentaire du 2026-11-26) : le prix payé est la moyenne pondérée des achats du wallet sur les 90 derniers jours.

## Règles

1. **Période** : transactions d'achat dont la date est `>= maintenant − 90 jours` (borne incluse). Constante publique `PlanetaryPaidPriceCalculator::PAID_PRICE_DAYS`, pas de réglage.
2. **Personnages** : tous ceux de l'utilisateur connecté. Jamais ceux d'un autre utilisateur.
3. **Types** : les types en entrée d'un schéma PI (P0 à P3), une ligne par type, triées par `typeId`. Un achat d'un autre type est ignoré. Une vente est ignorée.
4. **Prix payé** : `Σ(quantité × prix unitaire) / Σ quantité`, arrondi à 2 décimales. Un type sans achat sur la période a `paidPrice = null` (inconnu), jamais 0.
5. **Quantité et nombre d'achats** : `quantity` = Σ quantités, `purchaseCount` = nombre de transactions ; 0 et 0 pour un type sans achat.
6. **Portefeuilles non lus** : `unsyncedWallets` liste chaque personnage de l'utilisateur dont le wallet n'est pas lu (même source que Revenus, `WalletReadingStateReader::unsyncedStatusByEveCharacterId`), pour que le prix affiché dise qu'il peut être incomplet.
7. **Endpoint** : `GET /api/planetary/paid-prices` (ROLE_USER, API Platform, provider), ressource `PlanetaryPaidPrice` : `id`, `days`, `since` (ISO 8601 UTC), `prices[]` (`typeId`, `typeName`, `paidPrice`, `quantity`, `purchaseCount`), `unsyncedWallets[]`.

## Exemples chiffrés

| Achats (90 j) | paidPrice | quantity | purchaseCount |
|---|---|---|---|
| 10 × 1 000 (A), 30 × 2 000 (B) | 1 750,00 | 40 | 2 |
| 1 × 10,00 et 2 × 10,01 | 10,01 (30,02 / 3 = 10,0067) | 3 | 2 |
| aucun | null | 0 | 0 |

## Cas limites

- Achat à `now − 90 j` pile : compté ; une seconde plus tôt : exclu.
- Vente d'un type PI : ignorée. Achat d'un type hors schéma : ignoré.
- Aucun personnage : toutes les lignes à `null`.

## Hors périmètre

- Écran (grille de prix du Stock : #1397) et bénéfice de production (#1400), qui lisent ce calcul.
- Réglage de la période, prix par lieu, prix de l'extrait (#1400).
