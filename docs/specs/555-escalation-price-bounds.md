# Spec #555 — Prix d'escalation : colonne bigint et plafond en 422

## Contexte

`escalations.price` est une colonne `integer` (int4, 2 147 483 647 ISK au plus). Ni `CreateEscalationInput::$price` ni `UpdateEscalationProcessor` ne bornent la valeur : au-delà, PostgreSQL lève un dépassement d'entier et l'API répond 500, rien n'est enregistré. Le revenu d'escalation (`PveIncome::$amount`, float) compte déjà des montants plus grands. Décision de nuit : option 1 de l'issue (recommandation), mode nuit, notée dans « Décisions » du journal.

## Règles

- **R1.** `escalations.price` passe en `bigint` (migration `ALTER COLUMN price TYPE BIGINT`, `down` ramène à `INTEGER`). `Escalation::$price` reste un `int` PHP (`Types::BIGINT`, DBAL 4 hydrate en `int`).
- **R2.** Le prix d'une escalation est compris entre 0 et `Escalation::MAXIMUM_PRICE` = 1 000 000 000 000 000 ISK (1 000 Md ; reste exact en float et en nombre JavaScript). Le plafond vit dans une constante unique de l'entité, utilisée par la création et par le PATCH.
- **R3.** `POST /api/escalations` : `price` hors de 0..plafond répond 422 (contrainte `Assert\Range` sur `CreateEscalationInput::$price`), aucune escalation créée.
- **R4.** `PATCH /api/escalations/{id}` : un `price` entier hors de 0..plafond répond 422 (`UnprocessableEntityHttpException`). Le contrôle se fait avant toute modification : ni le prix, ni le statut de vente, ni les notes envoyés dans la même requête ne sont enregistrés, et aucun revenu d'escalation n'est créé.
- **R5.** Jamais de 500 pour un prix entier : à l'intérieur des bornes, le prix est enregistré tel quel (y compris au-delà de 2 147 483 647) et le revenu d'escalation vaut ce prix.
- **R6.** Un `price` non entier dans le PATCH reste ignoré comme aujourd'hui (comportement existant, hors périmètre).

## Exemples chiffrés (tests rouges)

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | PATCH `{saleStatus: vendu, price: 2147483648}` | 500, aucun revenu | 200, prix 2 147 483 648 enregistré, 1 revenu de 2 147 483 648.0 |
| E2 | PATCH `{saleStatus: vendu, price: 1000000000000000}` | 500 | 200, revenu 1e15 |
| E3 | PATCH `{saleStatus: vendu, price: 1000000000000001}` | 500 | 422, escalation inchangée (prix et statut), aucun revenu |
| E4 | PATCH `{price: -1}` | accepté | 422, prix inchangé |
| E5 | POST avec `price: 2147483648` | 500 | 201, prix 2 147 483 648 relu à l'identique |
| E6 | POST avec `price: 1000000000000001` | 500 | 422, aucune escalation créée |
| E7 | POST avec `price: 0` | accepté | 201 (borne basse incluse) |

Le test de caractérisation `testPriceBeyondInteger4CurrentlyAnswers500AndCreatesNoIncomeIssue533` (`EscalationSaleIncomeApiTest`, `#[Group('issue-533')]`) est remplacé par des tests nommés sans `Currently`, `#[Group('issue-555')]`.

## Cas limites

- PATCH avec un `price` hors bornes et un `saleStatus` valide : 422, le statut reste inchangé (R4).
- Prix à 0 : accepté (déjà le cas, le formulaire front exige `>= 0`).
- Mercure : le rejet 422 ne publie aucun événement.

## Accès

Inchangé : le propriétaire seul modifie (`isOwnedBy`), le contrôle de propriété passe avant la validation du prix, donc un non-propriétaire reçoit toujours 403 et non 422.

## Tests existants contredits

- `EscalationSaleIncomeApiTest::testPriceBeyondInteger4CurrentlyAnswers500AndCreatesNoIncomeIssue533` : remplacé (E1 et E3).

## Hors périmètre

- Formulaire front (le champ saisit déjà des montants raisonnables ; l'erreur 422 s'affiche par le chemin d'erreur existant).
- Autres colonnes int4 du module PVE (`PveIncome`, ventes de loot).
- Plancher négatif des autres champs ; `price` non entier au PATCH.

## Actions prod

Migration `doctrine:migrations:migrate` au déploiement (verrou bref sur `escalations`, table petite).

## Décisions ouvertes

Aucune. Plafond à 1 000 Md ISK et plancher à 0 pris en autonomie (journal « Décisions »), modifiables par la constante.

## Glossaire

Aucun terme ajouté.
