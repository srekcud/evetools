# Spec — Rendement de retraitement du profil dans les entrées et le tableau de bord de minage (#269)

## Contexte

Décision de Sylvain (2026-11-09, option A) : les entrées de minage et le tableau de bord utilisent le rendement de retraitement du profil de l'utilisateur (`ReprocessingProfile::getReprocessYield()`, déjà calculé depuis compétences et lieu). Profil absent ou illisible : valeur retraitée inconnue, jamais 78 %. La saisie `localStorage` du rendement dans `MiningTab` est retirée (approuvé explicitement). Le rendement par opération (`MiningOperation::reprocessYield`) est déjà livré et ne change pas.

## Règles

- **R1.** `OreValueService::getBatchOrePrices` n'a plus de rendement par défaut : sans rendement fourni (`null`), `reprocessValue`, `structureReprocessValue` et `reprocessYield` valent `null`. Un rendement fourni se comporte comme aujourd'hui.
- **R2.** `GET /api/ledger/mining/entries` valorise le retraitement avec le rendement du profil de l'utilisateur connecté. Le paramètre de requête `reprocessYield` est retiré.
- **R3.** Chaque entrée porte `reprocessYield` (fraction 0..1, `null` si inconnu). Profil sans rendement (`failure` renseigné) ou absent : `reprocessYield`, `reprocessValue` et `structureReprocessValue` sont `null` ; les autres prix sont inchangés.
- **R4.** `MiningBestValueCalculator` (totaux, journaliers, par usage, par type : stats minage, tableau de bord, stats journalières) compare les sources avec le rendement du profil de l'utilisateur. Rendement inconnu : les sources de retraitement sont absentes de la comparaison (meilleur prix parmi les sources connues, puis prix brut) ; jamais 78 %.
- **R5.** `getBestUnitPrices` reçoit l'utilisateur en premier argument.
- **R6.** Les appelants de `getBatchOrePrices` qui n'utilisent pas le retraitement (vérificateur de stockage de flotte) passent `null` explicitement.
- **R7.** Front : le champ de saisie du rendement et la clé `localStorage` `miningReprocessYield` disparaissent de `MiningTab` ; `fetchMiningEntries` n'envoie plus `reprocessYield` ; `useMiningPricing` et `MiningEntriesTable` n'ont plus de rendement saisi : l'étiquette « reprocess N% » vient de `entry.reprocessYield`, l'en-tête de colonne affiche le rendement commun des entrées ou « Reproc. ? » (titre : rendement inconnu, profil de retraitement absent ou illisible) quand aucune entrée n'en porte.

## Exemples chiffrés (Veldspar, prix Jita des tests existants)

| # | Cas | Attendu |
|---|---|---|
| E1 | Service sans rendement | `reprocessValue` null, `reprocessYield` null, prix brut/compressé inchangés |
| E2 | Service rendement 0,78 | 13,3224 (inchangé) |
| E3 | Entrées, profil 0,7236 | `reprocessYield` 0,7236, `reprocessValue` = 13,3224 × 0,7236 / 0,78 |
| E4 | Entrées, aucun profil | `reprocessYield` null, `reprocessValue` null, `rawUnitPrice` inchangé |
| E5 | Calculateur, profil 0,78 | totaux identiques à aujourd'hui |
| E6 | Calculateur, aucun profil | prix retenu = compressé équivalent (ou brut), jamais le retraitement à 78 % |

## Cas limites

- Entrées vides : pas de lecture du profil nécessaire ; liste vide.
- Profil existant mais `reprocessYield` null (compétence non lue) : même traitement qu'un profil absent.

## Hors périmètre

Table de valeur au m³ (déjà basée sur le profil), couverture de stock, rendement par opération, taxe de retraitement, API `PUT /api/mining/reprocessing-profile`.

## Actions prod

Aucune migration ni commande console.
