# #569 : cartes KPI de l'Atelier

Décision de Sylvain (2026-11-10, sur l'issue) : les quatre cartes de la maquette `industry-project-v3.html#p=1` (Jobs en cours, Slots fabrication libres, Capital engagé, Profit attendu) remplacent les trois cartes de `IndustryDashboard` (projets actifs, slots utilisés, santé du pipeline). Les alertes et les actions rapides du dashboard sont conservées. Circuit m, mode nuit, aucune migration, aucune action asynchrone (pas de nouveau topic Mercure : les cartes se rechargent sur `industry-jobs` et `industry-project`, déjà écoutés).

## API (R1)
- **R1** `GET /industry/workshop-projects` ajoute `engagedCapital: ?float` par projet : `totalCostsEngaged` de la personne connectée dans `ProjectDistributionService::calculateDistribution` (apports approuvés, coût réel à date, jobs d'installation compris). Une personne sans apport approuvé dans le projet : `0.0` (somme vide, valeur réelle). Un apport sans valeur estimée : `null`, jamais 0. Règle portée par une classe pure `ViewerEngagedCapital::of(DistributionResult, string $memberId): ?float`, à côté de `ViewerProfitShare` ; la distribution n'est calculée qu'une fois par projet.

| Projet de la personne | `engagedCapital` |
|---|---|
| solo, apports approuvés 1 500 000 + 250 000 | `1750000.0` |
| groupe, la personne a apporté 400 000 sur 3 000 000 | `400000.0` |
| groupe, aucun apport approuvé de la personne | `0.0` |
| un apport de la personne sans valeur estimée | `null` |

## Front (R2 à R5)
- **R2 Agrégat** (`workshopKpis`, pur, `frontend/src/utils/workshopKpis.ts`) : sur les projets de l'Atelier dont le statut n'est pas `completed` (statut lu dans `groupProjectStore.myProjects`, jointure par id), `capital` = somme des `engagedCapital` connus et `profit` = somme des `profit.amount` connus (statuts `final`, `provisional` et `forecast` : la prévision Jita avant la première vente de #570, montant du projet entier), chacun avec `unknownCount` (projets sans valeur). Aucune valeur connue : `amount: null`. Jamais 0 par défaut ; un projet inconnu n'ajoute rien mais est compté et signalé (précédent #360, `unknownProjectCount`).
- **R3 Cartes** (en tête de `IndustryWorkshop.vue`, grille 4 colonnes, 2 sur mobile, `data-testid` `workshop-kpi-jobs|slots|capital|profit`) :
  - **Jobs en cours** : nombre de jobs de `slotsStore.data.characters[].jobs` dont `timeLeftSeconds > 0` ; sous-texte « tous les personnages » ou « aucun » ; inconnu tant que les slots ne sont pas chargés.
  - **Slots fabrication libres** : `globalKpis.manufacturing.max - used` avec « / max », sous-texte par personnage `nom N` (`max - used` d'un personnage dont `max` est connu, sinon « nom » + valeur inconnue) ; si `unknownSkillsCharacterCount > 0`, la mention `industry.slots.partialKpis` existante. Inconnu tant que les slots ne sont pas chargés.
  - **Capital engagé** : `formatIsk` de l'agrégat `capital`, sous-texte « jobs, achats et apports des projets actifs » ; `UnknownValue` si `amount` est nul ; si `unknownCount > 0`, mention « N projet(s) sans valeur ».
  - **Profit attendu** : `formatIsk` signé de l'agrégat `profit` (vert >= 0, rouge < 0), sous-texte « profit final, provisoire ou prévision Jita des projets actifs » ; `UnknownValue` si `amount` est nul ; mention « N projet(s) sans valeur » comme ci-dessus.
- **R4 Dashboard** : `IndustryDashboard.vue` perd la grille de trois cartes et le code mort associé (anneaux, couleurs, pipeline) ; alertes, actions rapides, lien « Voir tous les projets » inchangés. Les clés i18n devenues inutiles (`activeProjects`, `slotsUsed`, `pipelineHealth`, `slotsAvailable`, `targetsMet`) sont retirées en FR et EN, les autres restent.
- **R5** Direction HUD EVE, valeurs en `font-mono`/`tabular-nums`, labels en capitales espacées, noms de personnages tronqués (`truncate` + `title`), mobile 375 px sans débordement, clés FR et EN, aucun commentaire de code.

## Cas limites
- Aucun projet actif : capital et profit inconnus (`UnknownValue`), pas de « 0 ISK ».
- Erreur de l'endpoint : valeurs inconnues et bandeau d'erreur du store, jamais un montant par défaut.

## Hors périmètre
- Pas de part de la personne pour la prévision : le montant `forecast` est celui du projet entier (#570), additionné tel quel ; il ne diffère de la part que pour un projet de groupe.
- Santé du pipeline du Stockpile : l'indicateur disparaît de l'Atelier (décision de la ligne « remplacent » de l'issue) ; il reste dans la page Stockpile.
