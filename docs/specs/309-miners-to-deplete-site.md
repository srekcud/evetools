# Spec #309 — Minage groupé : combien de mineurs pour vider un site

Statut : **brouillon**, décisions de Sylvain du 2026-11-10 intégrées (type = minerai dominant, mineurs = personnages présents, échantillon = mes sites liés à une opération et épuisés, 90 jours, carte sur l'opération planifiée).

## Contexte

Avant une opération, un organisateur veut savoir combien de mineurs il faut pour vider un type de site avant le downtime. Les scans de #267 donnent la durée de vie de chaque site épuisé ; #305 lie un site à une opération, donc aux personnages inscrits. La maquette est `mockups/grouped-mining-v4.html` (branche `docs/mining-specs`, étape 3, carte « Combien de mineurs ? »).

## Règles métier

- **R1 — Échantillon.** Un site entre dans l'échantillon de l'utilisateur quand : il lui appartient, il est lié à une opération, son statut est `closed`, son dernier scan visible a 0 unité (épuisé selon R6 de #267 ; un site fermé à la main garde des unités et est exclu), son premier scan visible date de moins de 90 jours. Les scans masqués (#372) sont ignorés. Un site libre (sans opération) est exclu.
- **R2 — Type de site.** Le type d'un site est le type de minerai qui a le plus d'unités au premier scan visible. Égalité : le plus petit identifiant de type. Aucune colonne : calculé à la lecture.
- **R3 — Durée de vie.** `dernier scan visible − premier scan visible`, en minutes, sans arrondi.
- **R4 — Mineurs.** Nombre de personnages d'un membre `accepted` de l'opération du site, inscrits au plus tard au dernier scan (`enrolledAt <= dernier scan`) et dont le membre n'est pas parti avant le premier scan (`departedAt` nul ou `>= premier scan`). Un membre `removed` n'est pas compté (sa date de retrait n'est pas conservée). Zéro mineur compté : le site reste dans la liste, durée de vie visible, travail **inconnu** (`no_miner`), hors moyenne.
- **R5 — Travail d'un site.** `mineurs × durée de vie` (mineur·minute). Même boost et même vaisseau supposés, débit proportionnel au nombre de mineurs : la carte l'affiche.
- **R6 — Travail du type.** Moyenne arithmétique du travail des sites du type dont le travail est connu. Moins de 3 sites de ce type **avec travail connu** : `insufficient_history`, aucune durée, aucun nombre de mineurs, le nombre de sites trouvés est affiché.
- **R7 — Durée selon les mineurs.** Pour `n` mineurs : `travail du type ÷ n`, pour n de 4 à 9.
- **R8 — Mineurs nécessaires.** `début` = heure de départ planifiée de l'opération ; `limite` = prochain downtime (11:00 UTC) strictement après `début` ; `disponible = limite − début` en minutes. Mineurs nécessaires = `ceil(travail ÷ disponible)`, au moins 1. Chaque `n` de R7 indique s'il finit avant la limite (`début + durée <= limite`).
- **R9 — Types proposés.** Un onglet par type de minerai présent dans l'échantillon (au moins un site épuisé de ce type, même s'il y en a moins de 3). Aucun site épuisé : message « aucun site de ce type scanné jusqu'à épuisement », pas d'onglet.
- **R10 — Lignes affichées.** Pour chaque site de l'onglet : date du premier scan, libellé, mineurs, durée de vie (ou « inconnue » et son motif), travail. Les plus récents d'abord.

## Exemples (début 09:40 UTC, limite 11:00, 80 minutes disponibles)

| # | Cas | Échantillon du type | Attendu |
|---|---|---|---|
| E1 | Trois sites, mineurs constants | A : 6 mineurs, 98 min ; B : 5, 121 ; C : 6, 104 | travail 6 × 98 = 588, 605, 624 → moyenne 605,67 ; 6 mineurs : 100,94 min ; mineurs nécessaires `ceil(605,67 / 80)` = 8 ; n = 8 : 75,71 min, avant la limite ; n = 7 : 86,52 min, après |
| E2 | Deux sites seulement | A, B de E1 | `insufficient_history`, 2 sites, aucune durée |
| E3 | Quatrième site sans mineur compté | E1 + D : 0 personnage | D listé, durée visible, travail inconnu `no_miner` ; moyenne de E1 inchangée ; 3 sites avec travail |
| E4 | Site fermé à la main | E1 + F : dernier scan à 4 000 unités | F exclu (R1) |
| E5 | Site sans opération | E1 + G : site libre épuisé | G exclu |
| E6 | Site de 91 jours | E1 + H : premier scan il y a 91 jours | H exclu |
| E7 | Mineur parti avant le premier scan | A avec 1 personnage de `departedAt` avant le premier scan | ce personnage non compté : A passe à 5 mineurs |
| E8 | Personnage inscrit après le dernier scan | idem avec `enrolledAt` après | non compté |
| E9 | Dominant à égalité | premier scan Veldspar 30 000 / Scordite 30 000 | type Veldspar (plus petit identifiant) |
| E10 | Début à 11:00 pile | début 11:00 | limite = 11:00 le lendemain, 1 440 min disponibles |
| E11 | Un seul mineur suffit | travail 50, disponible 80 | mineurs nécessaires 1 |

## Cas limites

- Un site dont des mineurs ont été retirés de l'opération voit son nombre de mineurs sous-estimé (limite de R4, pas de date de retrait). Hors périmètre de corriger.
- L'API répond pour toute opération dont l'utilisateur est participant ; l'écran n'affiche la carte que pour une opération `planned`.
- Un prix ou un volume manquant ne gêne pas : seules les quantités et les dates entrent dans le calcul.

## Accès et fraîcheur

- Source : sites dont `owner` est l'utilisateur connecté. Aucune donnée d'un autre utilisateur n'est lue. Les mineurs d'un site de l'utilisateur sont des personnages de son opération : il en est participant ou propriétaire.
- L'opération (début planifié) est résolue par `MiningOperationRequest::participatedOperationOf` : un non-participant reçoit la même réponse qu'une opération inexistante.
- Calcul à la lecture, rien n'est enregistré, aucune migration, aucune sync, aucun appel ESI.
- Pas d'action asynchrone, donc pas de topic Mercure.

## Tests existants contredits ou modifiés

Aucun. `MiningSiteResource`, `SiteDepletionEstimator` et les scans ne sont pas modifiés.

## Hors périmètre

- Classe de vaisseau, boost et débit par personnage (#364) : l'hypothèse « débit proportionnel » est affichée, pas corrigée.
- Sites de mes opérations créés par d'autres participants.
- Heure limite choisie à la main, ou carte sur la page des sites.
- Saison et statistiques par système (#317).
- Chunks de lune (pas de scan, pas d'échantillon).

## Glossaire

Termes ajoutés : **Type de site** (minerai dominant du premier scan), **Travail d'un site** (mineurs × durée de vie, en mineur·minute), **Échantillon d'un type** (mes sites liés à une opération, épuisés, de moins de 90 jours).
