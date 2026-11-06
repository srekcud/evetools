# Spec #378 : navigation en sections de la maquette V3

## Contexte

Depuis #362 le projet à plusieurs et le projet solo forment un seul module, servi par `/group-industry`. La barre latérale garde pourtant une entrée « Industrie de groupe », Revenus hors de toute section et aucune section Économie. Maquette retenue : `industry-project-v3.html#p=1`. Le compteur de la file d'actions (#335) est hors lot.

## Règles

- **R1 — Sections.** Barre latérale, dans cet ordre : Tableau de bord ; Production (Industrie, Appraisal, PI) ; Minage (Opérations de minage) ; Économie (Marché, Revenus, Inventaire) ; Compte (Personnages, Paramètres, inchangé). Économie est un groupe repliable comme Production et Minage. Minage et Appraisal gardent leur place.
- **R2 — Plus d'entrée « Industrie de groupe ».** Ni dans la barre latérale, ni dans les modules masquables (case à cocher des réglages). Les identifiants des modules restants (`industry`, `appraisal`, `planetary`, `mining-operations`, `market`, `ledger`, `assets`) ne changent pas : un réglage enregistré qui masque l'un d'eux reste valable.
- **R3 — Accès aux projets depuis Industrie.** Le chemin est le tableau de bord d'Industrie (onglet par défaut), qui porte déjà « Nouveau projet » et « Voir tous les projets » vers `/group-industry`. La route `/group-industry`, `/group-industry/:id` et `/join/:code` restent inchangées. Sur ces routes, l'entrée Industrie est active (`aria-current`), son groupe reste déplié et le titre de page est « Industrie ».
- **R4 — Palette de commandes.** Les écrans suivent l'ordre de la barre latérale. Sous Industrie et ses onglets, deux entrées remplacent l'ancien écran « Industrie de groupe » : `Industry › Projects` (`/group-industry`) et `Industry › Projects › Available` (`/group-industry?tab=available`). La palette ne propose plus l'écran « Group Industry » seul.
- **R5 — Un module masqué reste masqué.** Industrie masquée : aucune de ses entrées de palette (onglets, projets) ni aucune suggestion de projet. Réglage enregistré hérité `group-industry` (ancienne case) : conservé tel quel dans le stockage, sans case pour le défaire, et il continue de retirer les entrées `Projects` et les suggestions de projet de la palette, comme avant.
- **R6 — Aucun retrait.** Aucune route, aucune clé i18n ni aucun module existant n'est supprimé en dehors de l'entrée de barre latérale et de son écran de palette.

## Exemples

| # | Situation | Attendu |
|---|---|---|
| E1 | Barre latérale, `/dashboard` | liens Tableau de bord, Industrie, Appraisal, PI, Opérations de minage, Marché, Revenus, Inventaire, Personnages ; aucun lien « Group Industry » |
| E2 | Groupe « Economy » | bouton d'en-tête `aria-expanded`, contient Market, Revenue, Inventory ; replié, ces liens disparaissent |
| E3 | `/group-industry` | seul le lien Industry porte `aria-current`, titre de page « Industry » |
| E4 | Réglages | cases : Industry, Appraisal, PI, Mining operations, Market, Revenue, Inventory ; pas « Group Industry » |
| E5 | Palette EN, rien masqué | Dashboard, Industry (+6 onglets), Industry › Projects, Industry › Projects › Available, Appraisal, PI, Mining operations (+1), Market (+1), Revenue (+4), Inventory (+1), Characters |
| E6 | `industry` masqué | plus aucune entrée `/industry` ni `/group-industry` |
| E7 | `group-industry` seul masqué (hérité) | entrées `/group-industry*` absentes, `/industry` et ses onglets présents ; stockage inchangé |

## Hors périmètre

Compteur de la file d'actions (#335), refonte d'une page, renommage des routes ou des dossiers `GroupIndustry`, entrée « Paramètres » en route (reste la fenêtre modale), retrait de la clé i18n `nav.groupIndustry`.

## Décisions

- Chemin le plus court : aucun changement de page, le tableau de bord d'Industrie suffit (deux clics : Industrie, Voir tous les projets).
- Réglage hérité `group-industry` : conservé et honoré pour la palette (il ne peut plus être défait depuis l'interface, point à trancher par Sylvain si une case « Projets » est voulue).
