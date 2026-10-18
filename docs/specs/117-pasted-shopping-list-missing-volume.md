# Spec #117 — Liste d'achats collée et Appraisal : un volume manquant est inconnu, jamais 0 m³

## Contexte

Même défaut que #104 (liste d'achats d'un projet, corrigée), sur les deux traitements d'une liste collée :

- `ParseProcessor` (`POST /api/shopping-list/parse`, écran Liste d'achats) : `getVolume() ?? 0.0` (`:84`) et `$volumes[$typeId] ?? 0.0` (`:109`). Un type sans volume compte 0 m³, son coût d'import vaut 0, Jita peut être choisi sans import et les totaux l'ignorent.
- `AppraiseProcessor` (`POST /api/shopping-list/appraise`, écran Appraisal, repris tel quel par les listes partagées) : même lecture (`:72`, `:124`). Pas de coût d'import, mais le volume de l'item et le volume total sont faux.

Règle du glossaire : une donnée manquante n'est jamais remplacée par 0 ; une somme qui en contient une est inconnue.

## Règles métier

- **R1 — Volume inconnu.** Volume `null` dans `invTypes`, ou type absent de `invTypes` : volume inconnu. Un volume `0.0` du SDE reste connu.
- **R2 — Parse, ligne.** Pour un item au volume inconnu : `volume`, `totalVolume`, `importCost` et `jitaWithImport` valent `null`. Prix et totaux Jita et structure inchangés.
- **R3 — Parse, meilleur lieu.** Jita n'est jamais choisi sans `jitaWithImport`. Avec un prix structure : `bestLocation = structure`, `bestTotal = structureTotal`. Sans prix structure : `bestLocation` et `bestTotal` valent `null`.
- **R4 — Parse, totaux.** Dès qu'un item a un volume inconnu, `totals.volume`, `totals.import` et `totals.jitaWithImport` valent `null`. `totals.jita`, `totals.structure` et `totals.best` sont calculés comme aujourd'hui (le meilleur total d'un item au volume inconnu suit R3).
- **R5 — Appraisal.** Pour un item au volume inconnu : `volume` et `totalVolume` valent `null` ; `totals.volume` vaut `null` dès qu'un item a un volume inconnu. Prix et autres totaux inchangés.
- **R6 — Signalement.** Les deux réponses portent `missingVolumeTypeIds` : typeIds au volume inconnu, chacun une fois, triés par ordre croissant ; `[]` quand tout est connu (y compris liste vide ou aucun item trouvé).
- **R7 — Frontend.** Écran Liste d'achats, écran Appraisal et page d'une liste partagée : « inconnu » à la place d'un volume, d'un coût d'import ou d'un total « Jita + import » `null`, et un avertissement qui nomme les types sans volume (quand la réponse porte `missingVolumeTypeIds`). Aucun calcul front ne traite `null` comme 0. Une liste partagée enregistrée avant le correctif s'affiche comme aujourd'hui.

## Exemples chiffrés

Transport 1 200 ISK/m³. Type A : 100 unités, Jita 500. Type B : 10 unités, volume 2 m³, Jita 1 000.

| # | Traitement | Situation | Attendu |
|---|---|---|---|
| E1 | Parse | A volume 0,5, Jita seul (garde-fou) | volume 0.5, totalVolume 50.0, importCost 60 000.0, jitaWithImport 110 000.0, best Jita 110 000.0, `missingVolumeTypeIds = []` |
| E2 | Parse | A volume `null`, Jita seul | volume, totalVolume, importCost, jitaWithImport `null` ; jitaTotal 50 000.0 ; best `null` ; totals.volume, totals.import, totals.jitaWithImport `null` ; totals.jita 50 000.0 ; `missingVolumeTypeIds = [A]` |
| E3 | Parse | A absent de `invTypes` | identique à E2 |
| E4 | Parse | A volume `null`, Jita 400, structure 450 | bestLocation structure, bestTotal 45 000.0 (jamais Jita sans import) ; totals.best 45 000.0, totals.structure 45 000.0 |
| E5 | Parse | A volume `null`, B volume 2 | ligne B inchangée (totalVolume 20.0, importCost 24 000.0, jitaWithImport 34 000.0) ; totals.volume, import, jitaWithImport `null` ; totals.jita 60 000.0 ; `missingVolumeTypeIds = [A]` |
| E6 | Parse | A volume 0.0 dans le SDE (garde-fou) | volume 0.0, importCost 0.0, jitaWithImport 50 000.0, `missingVolumeTypeIds = []` |
| E7 | Parse | deux types inconnus | `missingVolumeTypeIds` triés croissants |
| E8 | Appraisal | A volume 0,5 (garde-fou) | volume 0.5, totalVolume 50.0, totals.volume 50.0, `missingVolumeTypeIds = []` |
| E9 | Appraisal | A volume `null`, B volume 2 | A : volume, totalVolume `null` ; B totalVolume 20.0 ; totals.volume `null` ; totaux de prix inchangés ; `missingVolumeTypeIds = [A]` |
| E10 | Appraisal | A absent de `invTypes` | identique à E9 pour A |

## Cas limites

- Aucun item trouvé (réponse anticipée `emptyTotals()`) : totaux à 0.0 comme aujourd'hui, `missingVolumeTypeIds = []`.
- Échec de lecture des prix (`priceError`) avec un volume inconnu : R2, R5 et R6 s'appliquent.
- Arrondis existants (`round(…, 2)`) inchangés pour les valeurs connues.

## Hors périmètre

- La liste d'achats d'un projet (#104, fait) et ses économies (#103, fait).
- La réécriture des listes partagées déjà enregistrées.
- Le contenu de `ShareListProcessor` : il enregistre ce que le client envoie ; seul l'affichage de la page partagée tolère `null`.

## Glossaire

Aucun terme ajouté.

## Tests impactés

Aucun test ne couvre aujourd'hui le calcul de `ParseProcessor` ni d'`AppraiseProcessor` (`ParseProcessorTest` ne teste que l'analyse du texte) : tous les tests sont nouveaux.
