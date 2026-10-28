# Spec #267 — Minage : ETA d'épuisement d'un site à partir de plusieurs scans

Statut : **brouillon**, non validé. Les règles marquées 🚦 attendent l'accord de Sylvain ; le format du collage attend un exemple réel (voir « Lecture du collage »).

## Contexte

Un mineur veut savoir combien de temps une belt ou une anomalie de minage va encore tenir, pour rester, déplacer la flotte ou appeler du renfort. Le Mining Survey Chipset (ex-survey scanner, depuis Catalyst) affiche le restant de chaque astéroïde, et sa fenêtre de résultats se copie toujours (vérifié en jeu le 2026-10-29). Des outils communautaires estiment déjà la durée de vie d'une belt à partir de ce collage.

La fonctionnalité se découpe en deux parties indépendantes :

1. **Lecture du collage** : texte du chipset → **scan de site**. Bloquée tant qu'un exemple réel n'est pas joint à l'issue.
2. **Estimation** : deux scans ou plus → débit, ETA d'épuisement et valeur restante par type de minerai, puis pour le site. Elle ne dépend pas du format du collage : elle peut passer en phase rouge dès la validation de cette spec.

## Règles métier — Estimation

- **R1 — Scan de site.** Un scan est un horodatage et, pour chaque type de minerai, la quantité restante en **unités**, somme des astéroïdes de ce type dans le scan. L'horodatage est l'heure de réception du collage par le serveur (UTC, à la seconde). Le volume restant en m³ vaut `unités × volume du type` (SDE `invTypes.volume`) ; un volume absent du SDE rend le m³ inconnu, pas l'ETA.
- **R2 — Ordre des scans.** Les scans d'une estimation sont triés par horodatage. Deux scans au même horodatage sont refusés (erreur de validation), car le débit serait une division par zéro.
- **R3 — Débit avec deux scans.** Pour un type présent dans les deux scans : `débit = (Q1 − Q2) / (t2 − t1)`, en unités par minute. `ETA = t2 + Q2 / débit`.
- **R4 — Débit avec trois scans ou plus.** 🚦 Régression linéaire par moindres carrés de `Q` en fonction de `t` sur tous les scans où le type est présent : `débit = −pente`. L'ETA est l'instant où la droite atteint 0 : `t̄ + Q̄ / débit` (moyennes des temps et des quantités). Le débit n'est pas celui des deux derniers scans.
- **R5 — Dispersion.** 🚦 Avec trois scans ou plus, l'estimation porte l'écart maximal entre une quantité scannée et la droite, en unités et en % de la quantité du premier scan. Au-delà de 10 %, l'écran signale « débit irrégulier » (mineurs arrivés ou partis) ; l'ETA reste affichée.
- **R6 — Pas d'épuisement.** Un débit nul ou négatif (quantité stable, ou qui augmente : nouvel astéroïde à portée, respawn) rend l'ETA **inconnue**, motif `not_depleting`. Jamais « jamais », jamais une date lointaine.
- **R7 — Type épuisé.** 🚦 Un type présent dans un scan et absent de tous les scans suivants est **épuisé** : sa quantité vaut 0 dans les scans suivants et son ETA est « épuisé entre t(k) et t(k+1) ». Prérequis rappelé à l'écran : scanner depuis la même position avec la même portée, sinon un astéroïde hors de portée passe pour épuisé.
- **R8 — Type apparu.** Un type absent du premier scan et présent ensuite n'a pas de débit sur les scans où il manque : on ne garde que les scans où il est présent. S'il n'est présent que dans un seul scan, son ETA est inconnue, motif `single_scan`.
- **R9 — Un seul scan.** Avec un seul scan, aucun débit : ETA inconnue pour tous les types, motif `single_scan`. Le restant (unités, m³, valeur) est affiché.
- **R10 — ETA du site.** 🚦 L'ETA du site est la plus tardive des ETA connues des types en cours de minage. Les types `not_depleting` sont listés à part (« non minés ») et ne repoussent pas l'ETA du site. Si aucun type n'a d'ETA connue, l'ETA du site est inconnue.
- **R11 — Minerai le plus rentable.** L'estimation met en avant l'ETA du type dont la valeur au m³ est la plus haute parmi les types de valeur connue. Aucun type de valeur connue : pas de mise en avant.
- **R12 — Valeur restante.** Valeur restante d'un type = `unités restantes au dernier scan × valeur unitaire`, valeur unitaire au Jita buy du minerai compressé (une unité compressée = une unité brute, #259). Un type sans prix a une valeur **inconnue** ; la valeur restante du site est alors inconnue et liste les types sans prix (`missingPriceTypeIds`), comme le **coût inconnu**. L'ETA ne dépend pas du prix.
- **R13 — Arrondis.** Calculs en secondes et en flottants ; arrondi seulement à l'affichage : ETA à la minute, débit à l'unité par minute.

## Exemples

Temps relatifs au premier scan (20:00:00 UTC). Quantités en unités. Prix : Veldspar 15 ISK, Scordite 25 ISK l'unité (Jita buy compressé), sauf mention.

| # | Cas | Scans | Attendu |
|---|---|---|---|
| E1 | Deux scans | Veldspar : 20:00 → 60 000 ; 20:30 → 42 000 | débit 600 u/min ; ETA 21:40 ; valeur restante 630 000 ISK |
| E2 | Trois scans, régression | Veldspar : 20:00 → 30 000 ; 20:10 → 25 000 ; 20:20 → 18 000 | débit 600 u/min (pas 700, débit des deux derniers) ; ETA 20:50:33 ; écart maximal 666,67 u (2,22 %), pas de « débit irrégulier » |
| E3 | Débit irrégulier | Veldspar : 20:00 → 30 000 ; 20:10 → 29 500 ; 20:20 → 18 000 | débit 600 u/min ; ETA 20:53:03 ; écart maximal 3 666,67 u (12,22 %) : « débit irrégulier » |
| E4 | Quantité stable | Scordite : 20:00 → 5 000 ; 20:30 → 5 000 | ETA inconnue, `not_depleting` ; listé « non miné » |
| E5 | Quantité qui augmente | Scordite : 20:00 → 5 000 ; 20:30 → 6 000 | ETA inconnue, `not_depleting` |
| E6 | Type épuisé | Scordite : 20:00 → 2 000 ; 20:30 → absent | épuisé entre 20:00 et 20:30 ; valeur restante 0 |
| E7 | ETA du site | E1 + Scordite : 20:00 → 40 000 ; 20:30 → 31 000 | Scordite : débit 300 u/min, ETA 22:13 ; ETA du site 22:13 |
| E8 | Type non miné et ETA du site | E1 + E4 | ETA du site 21:40 (Veldspar) ; Scordite listé « non miné » |
| E9 | Prix manquant | E1, Veldspar sans prix | ETA 21:40 ; valeur restante inconnue, `missingPriceTypeIds` = [Veldspar] |
| E10 | Un seul scan | Veldspar : 20:00 → 60 000 | ETA inconnue, `single_scan` ; restant 60 000 u, valeur 900 000 ISK |
| E11 | Même horodatage | deux scans à 20:00:00 | refusé (validation) |
| E12 | Type apparu | Scordite : 20:00 → absent ; 20:30 → 8 000 | ETA inconnue, `single_scan` |

Calculs (moindres carrés, temps en minutes depuis 20:00) :

- E2 : `t̄ = 10`, `Q̄ = 24 333,33`, pente `−600` ; droite 30 333,33 / 24 333,33 / 18 333,33, écarts 333,33 / 666,67 / 333,33 ; ETA `10 + 24 333,33 / 600 = 50,556 min`, soit 20:50:33.
- E3 : `t̄ = 10`, `Q̄ = 25 833,33`, pente `−600` ; droite 31 833,33 / 25 833,33 / 19 833,33, écarts 1 833,33 / 3 666,67 / 1 833,33 ; ETA `10 + 25 833,33 / 600 = 53,056 min`, soit 20:53:03.
- E7 : Scordite `(40 000 − 31 000) / 30 = 300 u/min`, `31 000 / 300 = 103,33 min` après 20:30, soit 22:13:20.

## Lecture du collage

[À CONFIRMER : format exact, à fixer depuis un exemple réel joint à l'issue #267]

Ce que la lecture doit produire, quel que soit le format : un **scan de site** (R1). Points à fixer avec l'exemple :

- colonnes présentes (nom de l'astéroïde, type de minerai, quantité, volume, distance) et séparateur ;
- unité de la quantité : unités ou m³ ;
- format des nombres (séparateur de milliers, langue du client) ;
- lignes de regroupement par type ou par astéroïde ;
- types reconnus par nom : nom anglais du SDE, et comportement si le client est dans une autre langue.

Règles déjà fixées : une ligne illisible est signalée (numéro de ligne) sans bloquer les autres ; un nom de minerai inconnu du SDE est listé, jamais ignoré en silence.

## Cas limites

- Coups critiques et *residue* (Catalyst) : ils faussent le lien entre ce que reçoivent les mineurs et ce que perd l'astéroïde. L'estimation ne lit que les scans, donc elle n'est pas concernée.
- Scans pris depuis des positions différentes : non détectable, rappel à l'écran (R7).
- Site dont un astéroïde est hors de portée à un scan et à portée au suivant : quantité qui augmente, `not_depleting` (R6).

## Hors périmètre

- ETA à partir du débit du ledger d'une opération de minage avec un seul scan (dépend de #263 ; issue de suite).
- Notification « épuisement dans moins de 10 minutes » (#271).
- Stockage d'un historique de sites : une estimation vit le temps de la session de minage. 🚦 À confirmer : en mémoire côté navigateur, ou persistée côté serveur liée à une opération.

## Glossaire

Termes proposés dans #261 : **Scan de site**, **ETA d'épuisement**. Termes ajoutés par cette spec : **Débit d'épuisement** (unités retirées d'un type par minute, déduites des scans), **Type épuisé**.
