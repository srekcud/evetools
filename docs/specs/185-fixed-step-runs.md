# Spec #185 — Runs fixés d'une étape et surproduction

## Contexte

Les runs saisis sur une étape intermédiaire sont réécrits par la cascade de recalcul (`UpdateStepProcessor` → `IndustryStepCalculator::recalculateStepQuantities()` → `SplitGroupQuantities::applyNeedToSteps()`) : sur un groupe découpé 10 + 10, saisir 15 sur le premier job donne 12 / 8. La régénération du projet (#21) les remet aussi aux runs du plan. Décisions de Sylvain du 2026-10-23 : les runs saisis survivent aux recalculs (runs fixés) ; le total prévu l'emporte, sauf surproduction confirmée par un bouton.

## Règles métier

- **G1 — Runs fixés.** Une étape porte un indicateur `runsFixed` (faux par défaut). Ses runs ne changent plus à aucun recalcul (cascade après une saisie sur une étape, régénération du projet). Sa quantité et les matériaux de ses jobs suivent ces runs.
- **G2 — Saisie sur une étape non découpée.** Saisir N runs fixe l'étape à `min(N, runs du besoin)`. En dessous du besoin, l'étape est fixée à N (le reste est couvert par le stock ou des achats).
- **G3 — Saisie sur un job d'un groupe découpé.** Le job est fixé à `min(N, runs du besoin − runs des autres jobs fixés − 1 par autre job non fixé du groupe)`, au moins 1 run. Le total du groupe reste celui du besoin : les jobs non fixés se partagent le reste, au moins 1 run chacun, au prorata de leurs runs actuels (règle existante d'`applyNeedToSteps`).
- **G4 — Surproduction.** Une saisie envoyée avec `overproduce: true` fixe l'étape à N sans plafond. Dans un groupe découpé, les autres jobs sont fixés à leurs runs actuels : le total du groupe devient la somme des runs fixés.
- **G5 — Retour au calcul automatique.** `runsFixed: false` sur une étape libère tous les jobs de son groupe (ou l'étape seule) ; le total revient au besoin, réparti également entre les jobs (`SplitGroupQuantities::runsBySplitIndex()`).
- **G6 — Besoin qui change ensuite.** Les jobs fixés gardent leurs runs. Les jobs non fixés du groupe se partagent `runs du besoin − runs fixés`, au moins 1 run chacun. Si tous les jobs sont fixés, le total est la somme des runs fixés, au-dessus ou en dessous du besoin.
- **G7 — Régénération.** Une étape dont un job au moins est fixé garde ses jobs (nombre, groupe découpé) : la durée maximale de job ne la redécoupe pas ; ses runs suivent G6. Les autres étapes suivent les règles de #21 (lots 3 et 4).
- **G8 — Formulaire.** Quand la réponse à une saisie donne moins de runs que la valeur saisie (plafond G2 ou G3), la ligne propose « Surproduire N runs » ; le clic renvoie la saisie avec `overproduce: true`. Une étape fixée est signalée, avec un bouton « Calcul automatique » qui envoie `runsFixed: false`.

## Exemples chiffrés

Recette des tests d'intégration de `IndustryProjectFactoryRegenerationTest` ; « besoin » = runs du besoin de l'étape.

| # | Cas | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | Étape non découpée, besoin 18, saisie 15 | 15 puis réécrit au prochain recalcul | 15, fixée ; matériaux de ses jobs calculés sur 15 runs |
| E2 | E1 puis runs du projet doublés (besoin 36) | 36 | 15 |
| E3 | Besoin 18, saisie 20 sans surproduction | 20 puis réécrit à 18 | 18, fixée |
| E4 | Besoin 18, saisie 20 avec surproduction | — | 20, fixée |
| E5 | Groupe 10 + 10 (besoin 20), saisie 15 sur le job 0 | 12 / 8 | 15 (fixé) / 5 |
| E6 | Groupe 10 + 10, saisie 25 sur le job 0 sans surproduction | — | 19 (fixé) / 1 |
| E7 | Groupe 10 + 10, saisie 25 sur le job 0 avec surproduction | — | 25 / 10, les deux fixés |
| E8 | E5 puis besoin 30, puis besoin 12 | — | 15 / 15, puis 15 / 1 |
| E9b | Groupe 10 + 10 + 10 (besoin 30), job 1 fixé à 10, saisie 25 sur le job 0 sans surproduction | — | 19 (fixé) / 10 (fixé) / 1 |
| E9 | E5 puis `runsFixed: false` sur le job 1 | — | 10 / 10, aucun job fixé |
| E10 | Composant fixé à 15 dans un groupe, durée maximale de job relevée, régénération | groupe redécoupé | même groupe, mêmes jobs, 15 sur le job fixé |
| E11 | Formulaire : saisie 20 sur une étape renvoyée à 18 | — | bouton « Surproduire 20 runs » ; clic → PATCH `{runs: 20, overproduce: true}` |

## Cas limites

- Une étape liée à un job ESI garde son champ de runs désactivé (inchangé).
- Racine (profondeur 0) : runs = runs du projet, saisie inchangée (hors périmètre).
- Copie : non concernée (pas de saisie de runs).

## Hors périmètre

- Saisie des runs de la racine, surplus affiché, bonus de structure d'une étape sans structure (#133).

## Décisions prises en autonomie

- G3/G6 : un job non fixé garde au moins 1 run (un groupe découpé a au moins deux jobs avec des runs) ; le reste est réparti au prorata, règle existante.
- G5 : le retour au calcul automatique vaut pour tout le groupe et répartit également, comme le plan.
- G7 : une étape fixée n'est pas redécoupée par la régénération, pour ne pas déplacer des runs saisis.
- G1 : la quantité d'un job fixé vaut runs × unités par run, même au-delà du besoin (surproduction).
- G5 : une saisie qui envoie `runs` et `runsFixed: false` ensemble revient au calcul automatique (la libération l'emporte).
- Racine et produit ajouté à la main (aucun besoin) : la saisie s'applique telle quelle, sans fixer les runs.
- G8 : le plafond est appliqué par le serveur ; le formulaire propose la surproduction d'après la réponse, sans connaître le besoin.

## Glossaire

- **Runs fixés** (Fixed runs) : runs d'une étape saisis par l'utilisateur, gardés à chaque recalcul. `IndustryProjectStep::$runsFixed`.
- **Surproduction** (Overproduction) : runs fixés au-delà du besoin, confirmés par l'utilisateur. Paramètre `overproduce` de la saisie.
