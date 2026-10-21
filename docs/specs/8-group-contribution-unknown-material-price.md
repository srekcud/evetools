# Spec #8 (lot Group Industry) — Contribution matériau sans prix

## Contexte

Une contribution de type matériau sans valeur saisie est valorisée au 5e percentile des ordres de vente Jita (`GroupIndustryContributionService::calculateMaterialValue()`). Sans prix, la méthode renvoie 0 et la contribution est enregistrée à 0 ISK : son payout est nul, sans que personne ne le voie. La modale de contribution n'affiche le champ de valeur que pour les BPC et la location de ligne, et n'envoie la valeur d'un matériau que si le BOM porte un prix estimé. Partie de #8.

## Règles métier

- **R1 — Valeur d'une contribution matériau.** Valeur saisie si elle est strictement positive ; sinon prix Jita (5e percentile) × quantité.
- **R2 — Prix inconnu.** Sans valeur saisie positive et sans prix Jita, la contribution est refusée (400) avec le message `No market price for type <typeId>: enter an estimated value.` Rien n'est enregistré.
- **R3 — `calculateMaterialValue()`** renvoie `null` quand le prix est inconnu, jamais 0.
- **R4 — Frontend.** Quand le matériau choisi n'a pas de prix estimé dans le BOM, la modale affiche le champ « Valeur estimée (ISK) », le rend obligatoire (> 0) pour valider le formulaire, et envoie cette valeur. Quand le BOM porte un prix, le comportement est inchangé (valeur calculée, pas de champ).
- **R5 — Inchangé.** Contributions de job, BPC, location de ligne et contributions auto-détectées.

## Exemples chiffrés

| # | Cas | Attendu |
|---|---|---|
| E1 | Matériau, 100 unités, prix Jita 12,5, pas de valeur saisie | contribution enregistrée, valeur 1 250 |
| E2 | Matériau, 100 unités, prix Jita 12,5, valeur saisie 2 000 | valeur 2 000 (inchangé) |
| E3 | Matériau, 100 unités, pas de prix Jita, pas de valeur saisie | 400, message R2, aucune contribution persistée |
| E4 | Matériau, pas de prix Jita, valeur saisie 900 | valeur 900 |
| E5 | Matériau, pas de prix Jita, valeur saisie 0 | 400 (R2) |
| E6 | Modale : matériau sans `estimatedPrice` dans le BOM | champ de valeur visible ; formulaire invalide tant que la valeur ≤ 0 ; la valeur saisie est envoyée |

## Cas limites

- Prix Jita à 0 renvoyé par le marché : prix connu, valeur 0 (inchangé).

## Hors périmètre

- Colonne `estimatedValue` nullable et payouts à valeur inconnue (pas de migration ici).
- Contributions déjà enregistrées à 0.
- Valorisation des jobs, BPC et location de ligne.

## Décision prise en autonomie

Refuser la contribution plutôt que d'enregistrer une valeur inconnue : pas de migration, pas de payout faux, et l'utilisateur a déjà un moyen de saisir une valeur.

## Glossaire

Aucun ajout.
