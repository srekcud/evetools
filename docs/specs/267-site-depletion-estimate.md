# Spec #267 — Minage : ETA d'épuisement d'un site à partir de plusieurs scans

Statut : **brouillon**. Décisions de Sylvain du 2026-10-29 intégrées (deux débits et courbe, type absent épuisé, types non minés ignorés, historique sans le collage). Reste à valider : la spec dans son ensemble, et le format du collage, qui attend un exemple réel (voir « Lecture du collage »).

## Contexte

Un mineur veut savoir combien de temps une belt ou une anomalie de minage va encore tenir, pour rester, déplacer la flotte ou appeler du renfort. Le Mining Survey Chipset (ex-survey scanner, depuis Catalyst) affiche le restant de chaque astéroïde, et sa fenêtre de résultats se copie toujours (vérifié en jeu le 2026-10-29). Des outils communautaires estiment déjà la durée de vie d'une belt à partir de ce collage.

La fonctionnalité se découpe en trois parties :

1. **Lecture du collage** : texte du chipset → **scan de site**. Bloquée tant qu'un exemple réel n'est pas joint à l'issue.
2. **Estimation** : scans → débits, ETA d'épuisement et valeur restante par type de minerai, puis pour le site. Elle ne dépend pas du format du collage : elle peut passer en phase rouge dès la validation de cette spec.
3. **Historique** : les scans et les métriques qui en découlent sont enregistrés (jamais le collage), pour la courbe d'un site et l'historique des sites passés.

## Règles métier — Estimation

- **R1 — Scan de site.** Un scan est un horodatage et, pour chaque type de minerai, la quantité restante en **unités**, somme des astéroïdes de ce type dans le scan. L'horodatage est l'heure de réception du collage par le serveur (UTC, à la seconde). Le volume restant en m³ vaut `unités × volume du type` (SDE `invTypes.volume`) ; un volume absent du SDE rend le m³ inconnu, pas l'ETA.
- **R2 — Ordre des scans.** Les scans d'un site sont triés par horodatage. Un scan au même horodatage qu'un scan existant du site est refusé (erreur de validation), car le débit serait une division par zéro.
- **R3 — Deux débits.** Pour chaque type, l'estimation calcule deux débits, en unités par minute, et une ETA pour chacun :
  - **Débit récent** : sur les deux derniers scans où le type est présent, `débit = (Q(k−1) − Q(k)) / (t(k) − t(k−1))`, `ETA = t(k) + Q(k) / débit`. Il réagit vite à l'arrivée ou au départ de mineurs.
  - **Débit de tendance** : régression linéaire par moindres carrés de `Q` en fonction de `t` sur tous les scans où le type est présent, `débit = −pente`. L'ETA est l'instant où la droite atteint 0 : `t̄ + Q̄ / débit` (moyennes des temps et des quantités).
  - Avec deux scans, les deux débits et les deux ETA sont égaux.
- **R4 — Dispersion.** Avec trois scans ou plus, l'estimation porte l'écart maximal entre une quantité scannée et la droite de tendance, en unités et en % de la quantité du premier scan du type. Au-delà de 10 %, l'écran signale « débit irrégulier » (mineurs arrivés ou partis) : le débit récent est alors plus fiable que la tendance. Les deux ETA restent affichées.
- **R5 — Pas d'épuisement.** Un débit nul ou négatif (quantité stable, ou qui augmente : nouvel astéroïde à portée, respawn) rend l'ETA correspondante **inconnue**, motif `not_depleting`. Jamais « jamais », jamais une date lointaine. Les deux débits sont évalués séparément : un débit récent nul avec une tendance positive donne une ETA récente inconnue et une ETA de tendance connue.
- **R6 — Type épuisé.** Un type présent dans un scan et absent de tous les scans suivants est **épuisé** : sa quantité vaut 0 dans les scans suivants et son ETA est « épuisé entre t(k) et t(k+1) ». Prérequis rappelé à l'écran : scanner depuis la même position avec la même portée, sinon un astéroïde hors de portée passe pour épuisé.
- **R7 — Type apparu.** Un type absent du premier scan et présent ensuite n'a pas de débit sur les scans où il manque : on ne garde que les scans où il est présent. S'il n'est présent que dans un seul scan, ses ETA sont inconnues, motif `single_scan`.
- **R8 — Un seul scan.** Avec un seul scan, aucun débit : ETA inconnues pour tous les types, motif `single_scan`. Le restant (unités, m³, valeur) est affiché.
- **R9 — ETA du site.** Pour chaque débit (récent, tendance), l'ETA du site est la plus tardive des ETA connues des types en cours de minage. Les types `not_depleting` sont listés à part (« non minés ») et ne repoussent pas l'ETA du site. Si aucun type n'a d'ETA connue, l'ETA du site est inconnue.
- **R10 — Minerai le plus rentable.** L'estimation met en avant les ETA du type dont la valeur au m³ est la plus haute parmi les types de valeur connue. Aucun type de valeur connue : pas de mise en avant.
- **R11 — Valeur restante.** Valeur restante d'un type = `unités restantes au dernier scan × valeur unitaire`, valeur unitaire au Jita buy du minerai compressé (une unité compressée = une unité brute, #259), prix lu au moment du scan. Un type sans prix a une valeur **inconnue** ; la valeur restante du site est alors inconnue et liste les types sans prix (`missingPriceTypeIds`), comme le **coût inconnu**. L'ETA ne dépend pas du prix.
- **R12 — Arrondis.** Calculs en secondes et en flottants. ETA arrondie à la seconde la plus proche quand elle est enregistrée, affichée à la minute ; débit affiché à l'unité par minute.

## Règles métier — Historique

- **R13 — Site.** Un site appartient à un utilisateur. Il porte un libellé saisi (par exemple le nom de l'anomalie), un système solaire facultatif, sa date de création et un statut : `active` ou `closed`. [À CONFIRMER : le collage donne-t-il le système ou le nom du site ? Si oui, ils sont pré-remplis.]
- **R14 — Pas de collage en base.** Le texte collé n'est jamais enregistré, ni en base ni dans les logs. Seul le scan qui en découle l'est.
- **R15 — Ce qui est enregistré à chaque scan.** L'horodatage et, par type : unités, m³ (ou inconnu), prix unitaire lu au moment du scan (ou inconnu), valeur restante. Puis les métriques calculées à cet instant avec tous les scans du site jusque-là : par type, débit récent et son ETA, débit de tendance et son ETA, écart maximal, motif d'ETA inconnue ; pour le site, les deux ETA et la valeur restante. Une métrique enregistrée n'est jamais recalculée : l'historique montre ce que l'outil annonçait à chaque scan.
- **R16 — Courbe d'un site.** Pour chaque type : quantité restante (unités ou m³, au choix) en fonction du temps, un point par scan, avec la droite de tendance courante prolongée jusqu'à 0 et le segment du débit récent. Sous la courbe : l'évolution des ETA annoncées à chaque scan, pour voir si l'estimation se stabilise.
- **R17 — Clôture.** Un site passe à `closed` quand tous ses types sont épuisés (R6), ou à la main. Un site fermé n'accepte plus de scan.
- **R18 — Historique des sites.** Liste des sites de l'utilisateur, du plus récent au plus ancien : libellé, système, premier et dernier scan, durée observée (dernier − premier scan), volume et valeur au premier scan, débit de tendance moyen sur la durée observée, statut. Filtre par système.
- **R19 — Suppression.** Les sites et leurs scans sont supprimés avec le compte de l'utilisateur (`DELETE /api/me`, #53).

## Exemples — Estimation

Temps relatifs au premier scan (20:00:00 UTC). Quantités en unités. Prix : Veldspar 15 ISK, Scordite 25 ISK l'unité (Jita buy compressé), sauf mention.

| # | Cas | Scans | Attendu |
|---|---|---|---|
| E1 | Deux scans | Veldspar : 20:00 → 60 000 ; 20:30 → 42 000 | récent et tendance : 600 u/min, ETA 21:40:00 ; valeur restante 630 000 ISK |
| E2 | Trois scans, débit régulier | Veldspar : 20:00 → 30 000 ; 20:10 → 25 000 ; 20:20 → 18 000 | récent : 700 u/min, ETA 20:45:43 ; tendance : 600 u/min, ETA 20:50:33 ; écart maximal 666,67 u (2,22 %), pas de « débit irrégulier » |
| E3 | Débit irrégulier | Veldspar : 20:00 → 30 000 ; 20:10 → 29 500 ; 20:20 → 18 000 | récent : 1 150 u/min, ETA 20:35:39 ; tendance : 600 u/min, ETA 20:53:03 ; écart maximal 3 666,67 u (12,22 %) : « débit irrégulier » |
| E4 | Quantité stable | Scordite : 20:00 → 5 000 ; 20:30 → 5 000 | ETA inconnues, `not_depleting` ; listé « non miné » |
| E5 | Quantité qui augmente | Scordite : 20:00 → 5 000 ; 20:30 → 6 000 | ETA inconnues, `not_depleting` |
| E6 | Récent nul, tendance positive | Scordite : 20:00 → 9 000 ; 20:10 → 6 000 ; 20:20 → 6 000 | récent : ETA inconnue, `not_depleting` ; tendance : 150 u/min, ETA 20:56:40 |
| E7 | Type épuisé | Scordite : 20:00 → 2 000 ; 20:30 → absent | épuisé entre 20:00 et 20:30 ; valeur restante 0 |
| E8 | ETA du site | E1 + Scordite : 20:00 → 40 000 ; 20:30 → 31 000 | Scordite : 300 u/min, ETA 22:13:20 ; ETA du site 22:13:20 (récent et tendance) |
| E9 | Type non miné et ETA du site | E1 + E4 | ETA du site 21:40:00 (Veldspar) ; Scordite listé « non miné » |
| E10 | Prix manquant | E1, Veldspar sans prix | ETA 21:40:00 ; valeur restante inconnue, `missingPriceTypeIds` = [Veldspar] |
| E11 | Un seul scan | Veldspar : 20:00 → 60 000 | ETA inconnues, `single_scan` ; restant 60 000 u, valeur 900 000 ISK |
| E12 | Même horodatage | second scan à 20:00:00 | refusé (validation) |
| E13 | Type apparu | Scordite : 20:00 → absent ; 20:30 → 8 000 | ETA inconnues, `single_scan` |

Calculs (temps en minutes depuis 20:00) :

- E2 : récent `(25 000 − 18 000) / 10 = 700`, `18 000 / 700 = 25,714 min` après 20:20. Tendance : `t̄ = 10`, `Q̄ = 24 333,33`, pente `−600` ; droite 30 333,33 / 24 333,33 / 18 333,33, écarts 333,33 / 666,67 / 333,33 ; ETA `10 + 24 333,33 / 600 = 50,556 min`.
- E3 : récent `(29 500 − 18 000) / 10 = 1 150`, `18 000 / 1 150 = 15,652 min` après 20:20. Tendance : `Q̄ = 25 833,33`, pente `−600` ; droite 31 833,33 / 25 833,33 / 19 833,33, écarts 1 833,33 / 3 666,67 / 1 833,33 ; ETA `10 + 25 833,33 / 600 = 53,056 min`.
- E6 : tendance `Q̄ = 7 000`, pente `−150` ; droite 7 500 / 7 000 / 6 500 ; ETA `10 + 7 000 / 150 = 56,667 min`.
- E8 : Scordite `(40 000 − 31 000) / 30 = 300`, `31 000 / 300 = 103,33 min` après 20:30.

## Exemples — Historique

| # | Cas | Attendu |
|---|---|---|
| H1 | Trois scans de E2 | trois enregistrements de métriques : au 1er scan, ETA inconnues (`single_scan`) ; au 2e, récent et tendance 500 u/min, ETA 21:00:00 ; au 3e, les valeurs de E2 |
| H2 | Prix Jita qui change entre deux scans | chaque scan garde le prix lu à son horodatage ; les valeurs déjà enregistrées ne changent pas |
| H3 | Collage reçu | aucun champ, aucun log ne contient le texte collé |
| H4 | Dernier type épuisé | le site passe à `closed` ; un nouveau scan est refusé |
| H5 | Historique | site E2 : durée observée 20 min, volume au premier scan `30 000 × volume du Veldspar`, valeur 450 000 ISK, débit de tendance moyen 600 u/min |

Calcul H1 : `(30 000 − 25 000) / 10 = 500`, `25 000 / 500 = 50 min` après 20:10.

## Lecture du collage

[À CONFIRMER : format exact, à fixer depuis un exemple réel joint à l'issue #267]

Ce que la lecture doit produire, quel que soit le format : un **scan de site** (R1). Points à fixer avec l'exemple :

- colonnes présentes (nom de l'astéroïde, type de minerai, quantité, volume, distance) et séparateur ;
- unité de la quantité : unités ou m³ ;
- format des nombres (séparateur de milliers, langue du client) ;
- lignes de regroupement par type ou par astéroïde ;
- types reconnus par nom : nom anglais du SDE, et comportement si le client est dans une autre langue ;
- présence du système ou du nom du site (R13).

Règles déjà fixées : une ligne illisible est signalée (numéro de ligne) sans bloquer les autres ; un nom de minerai inconnu du SDE est listé, jamais ignoré en silence ; le texte n'est jamais enregistré (R14).

## Cas limites

- Coups critiques et *residue* (Catalyst) : ils faussent le lien entre ce que reçoivent les mineurs et ce que perd l'astéroïde. L'estimation ne lit que les scans, donc elle n'est pas concernée.
- Scans pris depuis des positions différentes : non détectable, rappel à l'écran (R6).
- Site dont un astéroïde est hors de portée à un scan et à portée au suivant : quantité qui augmente, `not_depleting` (R5).

## Hors périmètre

- Partage d'un site avec la flotte et rattachement à une opération de minage (avec #262 et #263).
- ETA à partir du débit du ledger d'une opération de minage avec un seul scan (dépend de #263).
- Notification « épuisement dans moins de 10 minutes » (#271).
- Statistiques agrégées entre sites (durée de vie moyenne par type d'anomalie ou par système) : l'historique R18 les rend possibles plus tard.

## Glossaire

Termes proposés dans #261 : **Scan de site**, **ETA d'épuisement**, **Type épuisé**. Termes ajoutés par cette spec : **Débit récent**, **Débit de tendance** (remplacent « Débit d'épuisement »), **Site de minage** (belt ou anomalie suivie par scans, avec son historique).
