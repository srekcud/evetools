# #304 lot 1 : compagnon détachable du minage groupé

Front seul (aucune route, migration ni entité). Maquette : `grouped-mining-v4.html` (compagnon : `cmpTier`, `cmpTools`, `cmpSettings`, `detach`, `setupCompanionWindow`). Prérequis vérifiés sur origin/main : `useMiningSiteStore` (site, scans, `recordScan`), `miningSiteEstimate.ts` (`etaTierOf`, `remainingVolumeRingOf`, `mostProfitableTypeOf`, `isRescanDue`, `readRescanMinutes`), `miningSurveyPaste.ts` (`parseSurveyPaste`), `useMiningSiteFormat`, `miningOperationTime`, `useMiningOperationStore.depart`, événement Mercure `site_scan_recorded` (`syncStore.getMiningOperationEvent`).

## Périmètre

Lot 1 : détachement (Document Picture-in-Picture, repli popup), trois tailles, touches, contenu d'un site actif (page d'un site ou site actif d'une opération), réglages d'affichage. Reliquats, hors lot, sans `Closes` : « Nouveau site » (#305 ouverte : clôture + site lié), contenu hors opération (prochain chunk, « Créer l'opération », « Estimer un site seul »), notifications navigateur (opt-in), lecture du presse-papiers au retour sur la fenêtre (opt-in).

## Règles

1. **Tailles** (`COMPANION_SIZES`, constantes nommées) : `bar` 320×110 touche B, `compact` 340×520 touche C, `detail` 380×1000 touche L. Taille par défaut `compact`, mémorisée dans le navigateur (clé `miningCompanion.size`, lecture tolérante : valeur absente ou inconnue = `compact`).
2. **Touches** (`companionShortcutOf(event)`, fonction pure) : B, C, L (taille), V (coller), D (détacher), insensibles à la casse, ignorées avec Ctrl/Meta/Alt et quand la cible est un champ de saisie (`input`, `textarea`, `select`, `contenteditable`). D n'a de sens que dans la fenêtre principale ; dans la fenêtre détachée D est ignorée.
3. **Fenêtre** : `openCompanionWindow(host: Window, size)` renvoie `{ kind: 'pip' | 'popup', window }` ou une erreur typée. Si `host.documentPictureInPicture` existe : `requestWindow({ width, height })`. Si l'API manque ou si la demande est refusée : repli `host.open('', 'evetools-compagnon', 'popup,width=…,height=…')` avec le message `miningCompanion.notices.popupFallback` (cause jointe). Popup bloquée (`open` renvoie `null`) : erreur typée `popup_blocked`, message `miningCompanion.notices.popupBlocked`, rien d'ouvert. Aucun cas ne tombe en silence.
4. **Une seule fenêtre** : détacher alors que le compagnon est déjà ouvert le remet au premier plan (`focus()`), n'en ouvre pas une seconde. La fermeture de la fenêtre (`pagehide`) remet l'état à « fermé ».
5. **Changement de taille** dans le compagnon : `resizeTo(width, height)` sur la fenêtre ouverte ; si le navigateur refuse (exception), message `miningCompanion.notices.resizeRefused` (ajuster à la main), la taille d'affichage change quand même.
6. **Hôte unique** (`MiningCompanionHost`, monté dans `App.vue`) : le compagnon survit à la navigation, son contenu est rendu par Teleport dans le `body` de la fenêtre ouverte, les feuilles de style de la page principale sont copiées dans la fenêtre. Même contexte JS que la page : le même store Pinia, donc l'état reste synchronisé sans message entre fenêtres. Un store `miningCompanion` porte `{ target: { siteId, operationId | null } | null, size, windowKind, notice, settings }` et les actions `detach`, `close`, `setSize`.
7. **Données du compagnon** : le site et ses scans sont chargés dans le store `miningSite` sous des refs dédiées (`companionSite`, `companionScans`, actions `fetchCompanion(siteId)`), pour ne pas écraser `currentSite` / `scans` de la page. Cible = un site : ce site. Cible = une opération : le site actif le plus récent de `fetchOperationSites(operationId)` (rechargé quand la cible change). Aucun site actif : état vide « aucun site actif sur cette opération » + action « Poste ». Rechargement sur l'événement `site_scan_recorded` de l'opération (site concerné) et après un collage du compagnon.
8. **Contenu** (composant de présentation `MiningCompanionPanel`, props et événements, sans store) :
   - bar : ETA du site (tendance) + compte à rebours, nom du site ;
   - compact : bar + anneau du volume restant, ETA récente, dernier minerai à tomber, dernier scan (« scan n° N, auteur, il y a X min » via `lastScanText`), rappel de re-scan (`isRescanDue`), pastille d'alerte ;
   - detail : compact + ETA par type (une ligne par type : nom, restant, ETA récente, ETA tendance) et, en opération, valeur restante.
   Mêmes calculs que la page : `etaTierOf`, `remainingVolumeRingOf`, `mostProfitableTypeOf`, `isIrregularRate`.
9. **Alertes visuelles seulement, aucun son** : couleur et libellé du palier (`normal`, `orange` < 15 min, `red` < 5 min, `reached`, `depleted`, `unknown`), bandeau de re-scan dû, bandeau « prix manquant » si `missingPriceTypeIds` non vide, badge « rythme irrégulier ».
10. **Donnée inconnue** : ETA, anneau, valeur, auteur sans valeur s'affichent « inconnue » / `UNKNOWN_DASH` (clés `miningSite.*` existantes), jamais 0.
11. **Collage** : Ctrl+V dans la fenêtre du compagnon (événement `paste` de son `document`) et touche V (`navigator.clipboard.readText()` de la fenêtre) passent le texte à `parseSurveyPaste` puis `recordScan(siteId, lines)`, comme `MiningSiteScanForm` : lignes en erreur ou groupe replié = message, rien d'enregistré ; succès = message « scan n° N : ETA de A à B » (clé `miningSite.scan.added`). Le texte collé n'est jamais stocké (ni store, ni localStorage, ni journal). V refusé par le navigateur (permission) : message `miningCompanion.notices.clipboardDenied` (utiliser Ctrl+V). Sans site actif : le collage est refusé avec un message.
12. **« Je pars »** (opération seulement) : visible si l'opération est `in_progress` et `myDepartedAt === null` ; premier clic = demande de confirmation dans le panneau, second clic = `miningOperationStore.depart(operationId)` ; échec = message d'erreur du store ; après succès le bouton disparaît (`myDepartedAt` rechargé via `fetchDetail`).
13. **« Poste »** : remet la fenêtre principale au premier plan (`host.focus()`) et navigue vers la page de la cible (`mining-site-detail` pour un site, `mining-operation-detail` pour une opération).
14. **Bouton « Détacher » (D)** sur `MiningSiteDetail` et sur `MiningOperationDetail` (cible = l'opération) ; absent d'un site clos ; libellé clé `miningCompanion.detach`, titre mentionnant la touche D.
15. **Réglages d'affichage** (volet engrenage, mémorisés dans le navigateur, valeurs bornées) : opacité du fond 40 à 100 (défaut 100), taille du texte S 0,9 / M 1 / L 1,2 (défaut M), délai du rappel de re-scan : la valeur existante `miningSite.rescanMinutes` (`readRescanMinutes`, `clampRescanMinutes`, 3 à 30), partagée avec la page.
16. **i18n** : toutes les chaînes sous `miningCompanion.*` en `en.json` et `fr.json` (parité vérifiée par le test de locales existant).

## Exemples chiffrés

| Cas | Attendu |
|---|---|
| `companionShortcutOf` : `b`, `B`, `c`, `L`, `v`, `d` | `size:bar`, `size:bar`, `size:compact`, `size:detail`, `paste`, `detach` |
| `companionShortcutOf` : `b` avec Ctrl ; `b` dans un `input` ; `x` | `null` |
| taille stockée `"huge"` ou absente | `compact` |
| PiP indisponible, popup ouverte | `kind: 'popup'`, notice `popupFallback` |
| PiP refusé (`requestWindow` rejette), popup ouverte | `kind: 'popup'`, notice `popupFallback` avec la cause |
| PiP refusé et `open` renvoie `null` | erreur `popup_blocked`, aucune fenêtre |
| `trendEta` dans 12 min | palier `orange`, compte à rebours « 12:00 » |
| `trendEta` null | ETA « inconnue », palier `unknown`, jamais 0 |
| dernier scan il y a 11 min, délai 10 | bandeau de re-scan |

## Hors périmètre

Aucun son. Pas de nouvelle route API, pas de migration. Les chaînes `miningSite.*` existantes ne changent pas. La page du site, `MiningSiteScanForm` et leurs tests existants restent intacts (le compagnon ajoute un bouton, ne retire rien). Le comportement réel de la fenêtre au-dessus du client EVE, du redimensionnement par taille et de la permission du presse-papiers n'est pas vérifiable sans test manuel (à noter dans la PR).

## Décisions prises en autonomie

- Hôte dans `App.vue` plutôt que par page : le compagnon doit survivre à la navigation, c'est son usage.
- « Je pars » confirmé en deux temps faute d'annulation côté API.
- Le texte de la maquette prévoit un « Annuler » après « Je pars » : non livré, l'API n'a pas d'annulation de départ.
