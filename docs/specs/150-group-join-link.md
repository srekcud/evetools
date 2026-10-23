# Spec #150 — Lien d'adhésion unique pour un projet collaboratif

## Contexte

Les boutons « Share link » (en-tête) et « Copy Join Link » (onglet membres) du détail d'un projet collaboratif appellent `copyShareLink()` (`GroupIndustryDetail.vue:133-135`), qui copie `{origin}/group-industry/join/{code}`. La route d'adhésion est `/join/:code` (`router/index.ts:125`, nom `group-industry-join`) et aucune route de repli ne rattrape l'ancienne forme : le lien copié mène à une page vide. `ShareLinkCard.vue:11` construit déjà la bonne URL.

## Règles métier

- **R1 — Une seule construction du lien d'adhésion.** Le lien d'adhésion d'un projet est `{origin}/join/{shortLinkCode}`. Il est construit par une seule fonction, utilisée par `copyShareLink()` et par `ShareLinkCard`.
- **R2 — Le lien résout vers la page d'adhésion.** Le chemin du lien résout vers la route `group-industry-join` avec `code = shortLinkCode`.

## Exemples chiffrés

| # | Entrée | Aujourd'hui | Attendu |
|---|---|---|---|
| E1 | origin `https://evetools.example`, code `K7Q2MX` | `https://evetools.example/group-industry/join/K7Q2MX` (détail) | `https://evetools.example/join/K7Q2MX` |
| E2 | même lien, côté `ShareLinkCard` | `https://evetools.example/join/K7Q2MX` | identique à E1 |
| E3 | chemin `/join/K7Q2MX` résolu par le routeur | — | route `group-industry-join`, `params.code = 'K7Q2MX'` |

## Hors périmètre

- Route de repli pour les anciens liens `/group-industry/join/{code}` déjà partagés (ils n'ont jamais fonctionné).
- Textes et libellés des boutons.

## Décision prise en autonomie

- Fonction pure partagée plutôt qu'une route de redirection : un seul format de lien.
