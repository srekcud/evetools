# Spec #1337 : bascule acheter ou fabriquer d'un intermédiaire sur un projet en cours

Branche d'intégration `feat/industry-flow`, circuit:m, front seul (aucun endpoint, aucune migration).

## Contexte

La bascule acheter ou fabriquer d'un intermédiaire, avec sa confirmation, n'existe que sur l'ancien écran `ProjectBuyOrBuild.vue` (#1328 le supprime après #1337 à #1340). L'assistant de démarrage ne s'ouvre qu'en `draft` et le détail de carte ne l'offre pas. Le serveur sait déjà basculer : `PATCH /industry/projects/{id}` avec `blacklistTypeIds` (`UpdateProjectProcessor::changeTheBlacklist`, hors `draft` : `buildBom(refuseToDropWorkedLines: true)`, refus en 400 si une ligne a un job lié, un achat ou un apport, ou si le projet est terminé). Les trois options chiffrées au coût livré, la conseillée et la choisie viennent de `GET /industry/projects/{id}/sourcing-decisions` (#1288). Rien à changer côté serveur.

## Règles

1. Le détail de carte (`ProjectFlowCardDetail`) gagne un bloc `buyOrBuild` (titre « Acheter ou fabriquer », origine « Approvisionnement › Acheter ou fabriquer »), placé en premier, pour la carte d'un **intermédiaire** seulement.
2. Intermédiaire = ligne de BOM qui est :
   - un job d'activité `manufacturing` ou `reaction` qui n'est pas un produit final du projet (couvre aussi un intermédiaire couvert par le stock, ligne de job à zéro run) ; ou
   - une ligne matériau dont le type est dans `project.blacklistTypeIds` (intermédiaire déjà acheté).
   Une matière de base, un job de copie ou d'invention, un produit final : pas de bloc.
3. Le bloc lit la ligne de `sourcing-decisions` du type de la carte (store `group-project-setup`, `fetchSourcingDecisions`), chargée comme les autres blocs du détail (état « chargement », erreur affichée en `role="alert"`). Une réponse dont l'`id` n'est pas celui du projet est ignorée. Pas de ligne pour ce type, ou `stage` `base` : message « aucun choix chiffré pour ce type », ni bouton ni invention de valeur.
4. Affichage, une ligne par option présente parmi fabriquer, Jita plus import, marché de la capitale :
   - le coût livré total de l'option et par unité, « inconnu » si `null` (jamais 0) ;
   - l'option **choisie** marquée, l'option **conseillée** étiquetée ;
   - une option non éligible (stock insuffisant à la capitale) signalée avec la quantité en vente.
5. Écart : si `chosen` et `recommended` sont tous deux connus et diffèrent, « écart » = total de la choisie moins total de la conseillée (positif : plus cher que le conseillé), affiché comme dans les choix du démarrage ; « inconnu » si l'un des deux totaux est `null`. Choisie égale à la conseillée : « sur la conseillée ». `recommended` nul : pas d'écart.
6. Badge « exclu » (« acheté (blacklist) », comme `buyOrBuild.switch.blacklisted`) quand le type est dans `project.blacklistTypeIds`, à l'endroit où se fait le choix.
7. Bascule, visible seulement si `canSwitch` (propriétaire ou admin du projet, projet non terminé : la même condition que l'ancien écran, `isOwnerOrAdmin && !isCompleted`). Type exclu : bouton « Passer en fabrication » ; sinon « Passer en achat ». Un clic ouvre la confirmation (texte de l'ancien écran : le type sera acheté ou fabriqué, la BOM sera recalculée, une ligne qui a un job lié, un achat ou un apport ne peut pas être retirée), avec Confirmer et Annuler. Annuler ne touche à rien.
8. Confirmer envoie `PATCH /industry/projects/{id}` avec `{ blacklistTypeIds }` : la liste actuelle du projet, le type retiré (passer en fabrication) ou ajouté (passer en achat), jamais de groupes ni de catégories. Pendant l'appel, les boutons sont désactivés.
9. Succès : le bloc relit `sourcing-decisions` et le composant émet `switched` ; la vue rafraîchit comme `onBuyOrBuildSwitched` (projet, BOM, chaîne, cockpit). Si la carte sélectionnée disparaît de la grille parce que la BOM a changé, le détail se ferme (comportement actuel de `selectedCard`).
10. Refus du serveur (400 ou 422) : le bloc affiche la raison, traduite par `projectRefusalMessage` quand le message est connu, sinon le message du serveur ; la liste exclue et la BOM restent inchangées, le bloc reste à l'état d'avant. Message générique « Le composant n'a pas pu être basculé » si l'erreur n'a pas de message.
11. Un membre sans droit (`canSwitch` faux) voit le choix, l'écart et le badge, sans bouton.

## Exemples chiffrés (tests rouges)

| Cas | Entrée | Attendu |
|---|---|---|
| Fabriqué, conseillé fabriquer | ligne job manufacturing, `chosen: build`, `recommended: build` | bloc affiché, choisie et conseillée sur « fabriquer », « sur la conseillée », bouton « Passer en achat » |
| Fabriqué, conseillé acheter | `chosen: build` (total 1 200 000), `recommended: jita` (total 900 000) | écart + 300 000 ISK |
| Acheté à la capitale | ligne matériau du type, type dans `blacklistTypeIds`, `chosen: structure` | badge exclu, bouton « Passer en fabrication » |
| Option inconnue | `options.jita.total: null` | « inconnu » pour cette option, jamais 0 ; écart « inconnu » si c'est une des deux options comparées |
| Bascule vers l'achat | `blacklistTypeIds: [34]`, intermédiaire 11540, confirmation | `PATCH` avec `{ blacklistTypeIds: [34, 11540] }` |
| Bascule vers la fabrication | `blacklistTypeIds: [34, 11540]`, confirmation | `PATCH` avec `{ blacklistTypeIds: [34] }` |
| Annulation | clic Annuler | aucun `PATCH` |
| Refus serveur | `PATCH` répond 400 « The line "X" carries a linked job, a purchase or a contribution and cannot be removed from the plan. » | raison traduite affichée, bouton toujours là, `switched` non émis |
| Matière de base | carte Tritanium | pas de bloc `buyOrBuild` |
| Produit final | carte Ishtar (job du produit final) | pas de bloc `buyOrBuild` |
| Sans droit | `canSwitch` faux | choix et écart affichés, aucun bouton |

## Cas limites

- Même type consommé à plusieurs stations : un seul choix par type (décision de #1288), une seule ligne dans le bloc.
- `sourcing-decisions` en erreur (422 d'un plan impossible) : l'erreur est dans le bloc, les autres blocs du détail ne sont pas touchés.
- Projet `draft` : la bascule est offerte aussi (le serveur l'accepte), sans effet sur les stations ni les marchés choisis.
- Le marché d'achat (Jita ou capitale) d'une ligne achetée n'est pas écrit par la bascule : il garde son choix stocké.
- Deux bascules à la suite : la liste envoyée part de `project.blacklistTypeIds` à jour (le store de projet est remplacé par la réponse du `PATCH`).

## Accès et fraîcheur

- Lecture : `sourcing-decisions` exige un membre accepté (inchangé). Écriture : `assertAdminOrOwner` côté serveur (inchangé) ; le bouton n'est qu'un confort, jamais le contrôle.
- Aucun identifiant d'un autre périmètre reçu : seuls l'id du projet et des type ids du plan.
- Fraîcheur : relu après chaque bascule ; le rafraîchissement des autres membres reste celui d'aujourd'hui (aucun événement Mercure n'est publié par un changement de blacklist, dette préexistante de l'ancien écran, non traitée ici).

## Tests existants modifiés par la spec

- `frontend/tests/components/group-industry/projectFlowCardDetailBlocks.spec.ts` : `ALL_KINDS` et les listes de blocs d'un job d'intermédiaire gagnent `buyOrBuild`.
- `frontend/tests/components/group-industry/projectFlowTranslations.spec.ts` : `ORIGIN_KINDS` et les origines françaises gagnent `buyOrBuild`.
- `frontend/tests/components/group-industry/projectFlowCardDetailView.spec.ts` : réponses par défaut des `apiRequest` (ajout de `sourcing-decisions`) et listes d'origines.
- `frontend/tests/views/GroupIndustry/groupIndustryDetailFlowCardDetail.spec.ts` : idem si la vue appelle maintenant `sourcing-decisions`.

## Hors périmètre

- Suppression de l'ancien écran `ProjectBuyOrBuild.vue`, de `ProjectStructures.vue` et de leurs clés i18n : #1328.
- Taxe d'installation, politique hors système préféré, détails restants : #1338, #1339, #1340.
- Bascule depuis le bloc des choix de démarrage (`ProjectStartupChoices`) : il reste un résumé en lecture.
- Choix du marché d'achat d'une ligne depuis le détail ; nouvel événement Mercure de blacklist ; tout changement serveur.

## Termes du glossaire

Ajout : **Bascule acheter ou fabriquer** (Buy or build switch).

## Décisions (mode nuit, option recommandée par l'issue)

- D1 : le bloc vit dans le détail de carte (l'issue propose carte ou choix de démarrage ; la carte est l'endroit où l'on agit sur un intermédiaire).
- D2 : même route que l'ancien écran (`blacklistTypeIds`), même confirmation, mêmes règles serveur ; aucun changement de montant calculé.
- D3 : reconnaissance de l'intermédiaire par la ligne de BOM, sans charger `sourcing-decisions` pour chaque carte.
- D4 : l'ancien écran reste en place jusqu'à #1328.
