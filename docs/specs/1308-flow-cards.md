# #1308 — Flow cards: activity colour, state, progress bar and source

Circuit `m`, mode nuit : décisions prises sur la recommandation de l'issue, du plan et du prototype (`industry-prototype-ishtar-2026-11-21.html` : `.card`, `.jbar`, `.src`, `.bpc`, `actLegend`). Front seulement : les données viennent de `GET bom` (lignes de BOM, jobs liés) et de `station-quantities` déjà chargés par #1307. Pas de note architect.

## Contexte

`ProjectFlowCard.vue` (#1307) affiche une bordure par état et un libellé d'état. Le prototype colore la carte par **activité** (palette #1171 : copie violet, invention bleu, réaction orange, fabrication cyan, matériau gris), garde l'état en texte, ajoute une barre de progression (verte quand c'est prêt), une pastille de source et un cadre sur l'icône d'un BPC.

## Règles

R1. **Champs ajoutés à `FlowCard`** (module pur `projectFlow.ts`) : `activity: ActivityGroup`, `source: FlowCardSource | null` (`stock`, `blueprint`, `invention`), `bpc: boolean`, `progress: number | null` (entier 0 à 100), `ready: boolean`, `remainingMs: number | null`. `FlowGridInput` reçoit `now: number` (ms), nécessaire à l'état des jobs ; l'écran le passe arrondi à la minute pour ne pas recalculer la grille chaque seconde.

R2. **Ligne de BOM d'une carte.** Ligne de même `typeId` : la ligne de job d'abord, sinon la première ligne. Aucune ligne (type absent de la BOM, `typeId` nul) : `activity` `unknown`, `source` nul, `bpc` faux, `progress` nul, `ready` faux, `remainingMs` nul. Jamais `material` par défaut.

R3. **Activité** = `activityGroupOf(ligne)` (`companionTasks.ts`) pour tous les états, vendu compris : ligne sans job → `material`, job `copy`, `invention`, `reaction`, `manufacturing`, autre activité de job → `unknown`.

R4. **BPC.** `bpc` est vrai si l'activité est `copy` ou `invention` (la carte porte des copies de blueprint).

R5. **Source.** `invention` pour l'activité `invention`, `blueprint` pour `copy`. Sinon `stock` pour une carte d'état `onSite` ou `consumed` d'une ligne sans job dont `fulfilledQuantity` dépasse `purchasedQuantity` (quantité fournie sans achat du projet : stock du container, apport de stock). Tout autre cas : nul. Le BPO ou BPC utilisé par un job de fabrication n'est pas une source de carte (détail de carte, #1310).

R6. **Progression.** État `out` d'une ligne de job : `progress` 100, `ready` vrai. État `inProduction` d'une ligne de job dont `runs > 0` : `progress` = runs des jobs liés à l'état d'affichage `ready` ou `delivered` (`jobDisplayOf`) divisés par `runs`, arrondi, plafonné à 100, `ready` vrai à 100. 0 run terminé : `progress` 0 (piste vide), jamais nul. Ligne de job sans `runs` : nul. Tout autre état : `progress` nul, `ready` faux. La progression est en runs et non en temps : le lien de job ne porte pas la date de départ (ajout = migration, hors périmètre).

R7. **Temps restant.** État `inProduction` : le plus grand `remainingMs` non nul parmi les jobs liés à l'état `running` ou `paused` ; aucun : nul.

R8. **Carte.** `ProjectFlowCard.vue` : bordure gauche et dégradé de teinte selon l'activité (`ACTIVITY_STYLES`), émeraude quand `ready` ; `inTransit` rayé, `unknown` rayé gris en italique, `consumed` atténué (comportement de #1307 conservé) ; l'état reste en texte (ambre pour `toBuy` et `toLaunch`), suivi du temps restant (`flow.remaining`, jours, heures, minutes) quand il existe. Cadre violet sur l'icône quand `bpc`. Pastille de source (`flow.sources.*`, gris stock, violet blueprint, bleu invention). Barre `data-testid="flow-card-progress"` (`role="progressbar"`, `aria-valuenow`), largeur = `progress`, verte quand `ready`, absente quand `progress` est nul. Ligne « qui » du prototype absente : le flux ne porte pas de responsable par carte.

R9. **Légende.** `ProjectFlowGrid.vue` affiche au-dessus des colonnes la légende des cinq activités (`data-testid="flow-activity-legend"`, libellés `groupIndustry.companion.activityGroups.*`, trait de la couleur de l'activité). Pas de légende tant que la grille n'a aucune ligne (vide ou indisponible).

R10. **Libellés** fr et en : `groupIndustry.flow.sources.{stock,blueprint,invention}`, `groupIndustry.flow.remaining`, `groupIndustry.flow.progress` (nom accessible de la barre). Les tests de traduction existants (`projectFlowTranslations`) couvrent la parité.

R11. **Glossaire.** Ajout : **Source d'une carte** (Card source).

## Exemples (tests rouges)

| Entrée | Sortie |
|---|---|
| Zydrine (ligne sans job), onSite 60 | activity `material`, bpc faux, progress nul |
| Composant X (job `reaction`, runs 10), inProduction 40, 2 jobs liés `delivered` de 3 et 2 runs | activity `reaction`, progress 50, ready faux |
| idem, 10 runs livrés | progress 100, ready vrai |
| idem, aucun run terminé, un job `active` fini dans 3 h | progress 0, remainingMs 10 800 000 |
| Composant X en `out`, ligne de job | progress 100, ready vrai |
| Invention de BPC (job `invention`), onSite 20 | activity `invention`, bpc vrai, source `invention` |
| Copie (job `copy`) | activity `copy`, bpc vrai, source `blueprint` |
| Tritanium onSite 400, fulfilled 400, purchased 0 | source `stock` |
| idem, purchased 400 | source nul |
| Type absent de la BOM | activity `unknown`, progress nul |
| Job `manufacturing` en `toLaunch` | progress nul, remainingMs nul |

## Cas limites

- Deux lignes du même type, une de job : la ligne de job gagne.
- Job lié `cancelled`, `reverted` ou `unknown` : ne compte ni en runs terminés ni en temps restant.
- `runs` dépasse le total terminé : plafonné à 100.
- Carte `unknown` (quantité illisible) : activité de sa ligne, pas de barre.

## Décisions

D1. Progression en runs terminés, pas en temps (pas de date de départ sur `IndustryProjectJobMatch`) ; à reprendre si Sylvain veut la barre en temps (colonne `start_date`, migration). Ligne « À relire à la fin ».
D2. Source `stock` : lecture de `fulfilledQuantity − purchasedQuantity` sur les lignes de matériau ; les lignes de job fournies par un apport de stock ne portent pas de pastille (pas de donnée distincte dans la BOM).
D3. Ligne « qui » du prototype et boutons d'action de carte absents (action en jeu, #1310 pour le détail).

## Hors périmètre

Attendus en pointillé (#1309, ses cartes rempliront les mêmes champs), détail de carte et clic (#1310), bande ISK (#1311), BPC attaché à une étape (#1296 côté données). Backend inchangé.
