# Spec #19 — Une seule définition de la marge

## Contexte

L'écran Profit Margins affiche des marges calculées par le backend (`ProfitMarginService`, `profit / coût total`) et, quand un décrypteur est choisi, des marges recalculées par le frontend (`ProfitMarginTab.vue`, `computeVenueMargin`, `profit / revenu`). Sur le même écran, choisir un décrypteur change donc la formule, pas seulement le coût. Buy vs Build et le scanner utilisent déjà `profit / coût total`.

## Règles métier

- **R1 — Marge.** `marge (%) = profit / coût total × 100`, où `profit = revenu − frais − coût total` et `frais = revenu × (courtage + taxe de vente)` (0 pour un contrat). C'est la définition du backend.
- **R2 — Même calcul des deux côtés.** Le recalcul du frontend (décrypteur choisi) applique R1 ; avec les mêmes entrées, il donne la même marge que `ProfitMarginService`.
- **R3 — Coût total nul ou négatif.** Même valeur que le backend aujourd'hui (0). Le passage à une marge inconnue relève de #108.

## Exemples chiffrés

| # | Cas | Aujourd'hui (frontend) | Attendu |
|---|---|---|---|
| E1 | Prix unitaire 1 000, 10 unités, coût total 8 000, courtage 1 %, taxe 2 % | revenu 10 000, frais 300, profit 1 700, marge 17 % | marge 21,25 % (1 700 / 8 000) |
| E2 | Même cas en contrat (sans frais) | profit 2 000, marge 20 % | marge 25 % |
| E3 | Coût total 0, mêmes prix et frais que E1 | marge 97 % (9 700 / 10 000) | 0 (R3) |

## Hors périmètre

- Ratio 0..1 de Group Industry (#8) et marge inconnue quand une donnée manque (#108).
- Formules des frais (#18).

## Tests existants appelés à changer

Aucun test frontend n'existe pour ce calcul ; le calcul est extrait dans un module testable (`frontend/src/components/industry/venueMargin.ts`, sur le modèle de `venueLabel.ts`).

## Décision prise en autonomie

Spec validée en mode nuit. Définition retenue : celle du backend (`profit / coût total`), déjà commune à Profit Margins, Buy vs Build et au scanner ; seul le recalcul frontend change. Le cas « coût total nul » garde la valeur actuelle du backend (0), sans anticiper #108.

## Glossaire

Terme ajouté : **Marge** (Margin).
