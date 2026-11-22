# Spec — Le compagnon se rafraîchit tant que sa propre fenêtre est visible (#1163)

## Contexte

`useCoalescedGroupProjectEvents` (#1155) suspend tout refetch tant que `document.visibilityState === 'hidden'` et reprend sur `visibilitychange` du `document` principal. `GroupCompanionHost.vue` l'appelle pour le compagnon, qui vit dans une fenêtre détachée (Document PiP ou popup, `useDetachedWindow::currentWindow`) restée visible au-dessus du jeu. Onglet principal masqué : une étape qui repasse à `todo` ou une réservation libérée n'apparaît dans le compagnon qu'au retour de l'onglet principal.

Front seulement. Les autres appelants du composable (vues et composants du projet dans l'onglet principal) gardent la visibilité du `document` principal.

## Règles

- **R1.** `useCoalescedGroupProjectEvents` accepte un quatrième argument optionnel `visibilityDocument: () => Document`, par défaut le `document` principal. La garde de visibilité lit `visibilityDocument().visibilityState` à chaque tentative de refetch.
- **R2.** L'écouteur `visibilitychange` est posé sur le document que rend `visibilityDocument()` et re-posé quand celui-ci change (fenêtre ouverte, remplacée ou fermée) : l'ancien document perd son écouteur, le nouveau le reçoit. Au changement de document, les actions en attente sont retentées aussitôt (`runWaitingActions`).
- **R3.** Le nettoyage de la portée retire l'écouteur du document courant ; plus aucun refetch après l'arrêt.
- **R4.** `GroupCompanionHost.vue` passe `GROUP_PROJECT_EVENT_COALESCE_MS` en troisième argument et, en quatrième, `() => detached.currentWindow.value?.document ?? document` : fenêtre du compagnon ouverte, sa visibilité fait foi ; sinon (ouverture en cours, fenêtre fermée) celle du `document` principal, comportement actuel.
- **R5.** Le reste est inchangé : coalescence (fenêtre de 3 s, un refetch en vol à la fois), cumul des actions pendant la suspension, un seul refetch à la reprise avec toutes les actions.

## Exemples

| # | Document principal | Document de la fenêtre du compagnon | Événement | Résultat |
|---|---|---|---|---|
| E1 | `hidden` | `visible` | action reçue | refetch immédiat, avec l'action |
| E2 | `visible` | `hidden` | action reçue | aucun refetch ; un seul à la reprise de la fenêtre (`visibilitychange` sur son document), avec toutes les actions |
| E3 | `hidden` | aucune fenêtre (`null`) | action reçue | aucun refetch ; refetch quand le document principal redevient visible |
| E4 | `hidden` | fenêtre ouverte après l'événement, `visible` | action reçue puis ouverture | l'action en attente part à l'ouverture, sans attendre un `visibilitychange` |
| E5 | `visible` | fenêtre fermée (retour au document principal) | `visibilitychange` sur l'ancien document de la fenêtre | aucun effet : son écouteur a été retiré |

## Cas limites

- Appelant sans quatrième argument : strictement le comportement de #1155 (tests existants inchangés).
- Fenêtre PiP ou popup détruite avant le nettoyage : `visibilityDocument()` retombe sur le document principal, jamais d'exception.

## Hors périmètre

- Les autres vues et composants qui utilisent le composable ; la logique de sync Mercure ; l'ouverture et la fermeture de la fenêtre détachée.
