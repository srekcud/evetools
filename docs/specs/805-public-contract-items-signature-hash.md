# Spec — Signature d'un contrat public stockée en empreinte

## Contexte

#805 : `PublicContractListing::itemsSignature` est un `VARCHAR(1000)` construit comme `typeId:quantité;…` sur tous les types du contrat. Un contrat de The Forge avec une soixantaine de types distincts dépasse 1000 caractères, le flush de `PublicContractHistoryRecorder::record()` lève `value too long`, et toute la sync `public-contracts` échoue (aucun historique, purge jamais exécutée). La signature ne sert qu'à une comparaison d'égalité (`ContractSalesAnalyzer`, clé `issuerId|signature` des remises en vente).

## Règles

- **S1.** La signature est le sha256 hexadécimal (64 caractères) de la chaîne canonique `typeId:quantité` jointe par `;`, items agrégés par type et triés par identifiant de type, inchangée par rapport à aujourd'hui.
- **S2.** La colonne `items_signature` devient `VARCHAR(64)`.
- **S3.** La migration réécrit les lignes existantes avec `encode(sha256(convert_to(items_signature, 'UTF8')), 'hex')` avant de réduire la colonne : une remise en vente observée après le déploiement reste appariée à l'ancienne ligne.
- **S4.** L'ordre des items à l'entrée ne change pas la signature.

## Exemples

| # | Cas | Attendu |
|---|---|---|
| E1 | 200 types distincts | enregistré sans erreur, signature de 64 caractères |
| E2 | items 34×100 et 35×50, dans un ordre ou dans l'autre | `7d11f23a0c81d76e36aaa79f4b02c9743d72a5161e75bb5ab91bbf5227289244` |
| E3 | 34×100 et 35×51 | signature différente de E2 |
| E4 | l'expression SQL de la migration sur `34:100;35:50` | égale à la signature PHP de E2 |

## Hors périmètre

Aucun changement de `ContractSalesAnalyzer` ni de l'historique ; pas de retour arrière sur le format de colonne dans `down()` au-delà du type (les anciennes valeurs ne sont pas reconstructibles).

## Actions de production

La migration s'exécute au déploiement. Aucune commande console.
